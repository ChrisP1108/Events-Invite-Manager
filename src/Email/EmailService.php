<?php

declare(strict_types=1);

namespace EventsInviteManager\Email;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Newsletter;

/**
 * Handles sending invite emails via wp_mail().
 *
 * Available template tags for invite emails:
 *   {{ event_name }}, {{ first_name }}, {{ last_name }}, {{ full_name }},
 *   {{ email }}, {{ qr_code }}, {{ invite_url }},
 *   {{ group_names }}, {{ invitee_names }}, {{ invitee_count }}
 */
final class EmailService
{
    /** @var TemplateRenderer Template renderer used to interpolate email variables. */
    private TemplateRenderer $renderer;

    /**
     * @param TemplateRenderer $renderer Renderer that resolves {{ tag }} placeholders in email templates.
     */
    public function __construct(TemplateRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Sends the invite email for a group to the primary invitee.
     *
     * Template tags {{ group_names }}, {{ invitee_names }}, and {{ invitee_count }}
     * reflect all members in the group; {{ first_name }}, {{ last_name }}, etc.
     * reflect the primary invitee (the email recipient).
     *
     * @param Event           $event
     * @param InvitationGroup $group
     * @param Invitee         $primaryInvitee  The group member who receives the email.
     * @param Invitee[]       $allMembers      All members including the primary.
     * @param string          $qrCodeImgTag    HTML <img> tag for the QR code.
     * @param string          $inviteUrl       RSVP URL encoded in the QR code.
     * @return bool True if wp_mail() accepted the message.
     */
    public function sendGroupInvite(
        Event           $event,
        InvitationGroup $group,
        Invitee         $primaryInvitee,
        array           $allMembers,
        string          $qrCodeImgTag = '',
        string          $inviteUrl    = ''
    ): bool {
        if (empty($event->inviteEmailTemplate)) {
            return false;
        }

        $groupNames = implode(', ', array_map(
            static fn(Invitee $m) => esc_html($m->fullName()),
            $allMembers
        ));

        $variables = [
            'event_name'    => esc_html($event->name),
            'first_name'    => esc_html($primaryInvitee->firstName),
            'last_name'     => esc_html($primaryInvitee->lastName),
            'full_name'     => esc_html($primaryInvitee->fullName()),
            'email'         => esc_html($primaryInvitee->email),
            'qr_code'       => $qrCodeImgTag,
            'invite_url'    => esc_url($inviteUrl),
            'group_names'   => $groupNames,
            'invitee_names' => $groupNames,
            'invitee_count' => (string) count($allMembers),
        ];

        $subject = $this->renderer->render($event->inviteEmailSubject, $variables)
            ?: "You're Invited: {$event->name}";
        $body    = $this->renderer->render($event->inviteEmailTemplate, $variables);

        return $this->dispatchHtml($primaryInvitee->email, $subject, $body, $this->buildFromHeader($event));
    }

    /**
     * Sends a newsletter to a single invitee, personalizing {{ first_name }},
     * {{ last_name }}, {{ full_name }}, and {{ email }} template tags.
     * The newsletter title is used as the email subject.
     *
     * @param Newsletter $newsletter
     * @param Invitee    $invitee
     * @param string     $fromHeader Optional "From: ..." header string.
     * @return bool True if wp_mail() accepted the message.
     */
    public function sendNewsletterToInvitee(Newsletter $newsletter, Invitee $invitee, string $fromHeader = ''): bool
    {
        $variables = [
            'first_name' => esc_html($invitee->firstName),
            'last_name'  => esc_html($invitee->lastName),
            'full_name'  => esc_html($invitee->fullName()),
            'email'      => esc_html($invitee->email),
        ];

        $subject = $this->renderer->render($newsletter->title, $variables);
        $body    = $this->renderer->render($newsletter->content, $variables);

        return $this->dispatchHtml($invitee->email, $subject, $body, $fromHeader);
    }

    /**
     * Sends a newsletter to a single arbitrary email address (for test sends).
     * Template tags are replaced with placeholder values so the layout renders correctly.
     *
     * @param Newsletter $newsletter
     * @param string     $toEmail
     * @param string     $fromHeader Optional "From: ..." header string.
     * @return bool True if wp_mail() accepted the message.
     */
    public function sendNewsletterTest(Newsletter $newsletter, string $toEmail, string $fromHeader = ''): bool
    {
        $variables = [
            'first_name' => 'Test',
            'last_name'  => 'User',
            'full_name'  => 'Test User',
            'email'      => esc_html($toEmail),
        ];

        $subject = '[TEST] ' . $this->renderer->render($newsletter->title, $variables);
        $body    = $this->renderer->render($newsletter->content, $variables);

        return $this->dispatchHtml($toEmail, $subject, $body, $fromHeader);
    }

    /**
     * Sends an HTML email via wp_mail().
     *
     * @param string $to          Recipient email address.
     * @param string $subject     Email subject line.
     * @param string $body        Full HTML body.
     * @param string $fromHeader  Optional "From: ..." header string; omitted when empty.
     * @return bool True if wp_mail() accepted the message.
     */
    private function dispatchHtml(string $to, string $subject, string $body, string $fromHeader = ''): bool
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if ($fromHeader !== '') {
            $headers[] = $fromHeader;
        }

        return wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Builds a "From: ..." mail header from the event's from_email and from_name fields.
     *
     * Resolves the {{ current_domain }} tag in from_email before sanitizing.
     * Returns an empty string when from_email is blank or invalid.
     *
     * @param Event $event The event whose sender fields should be used.
     * @return string e.g. "From: Wedding Team <no-reply@example.com>" or "".
     */
    private function buildFromHeader(Event $event): string
    {
        $fromEmail = sanitize_email($this->resolveCurrentDomainTag($event->fromEmail));

        if ($fromEmail === '') {
            return '';
        }

        $fromName = sanitize_text_field($event->fromName);

        if ($fromName !== '') {
            return 'From: ' . $fromName . ' <' . $fromEmail . '>';
        }

        return 'From: ' . $fromEmail;
    }

    /**
     * Replaces any {{ current_domain }} placeholder in a string with the site's hostname.
     *
     * If the hostname cannot be determined the original string is returned unchanged.
     *
     * @param string $value The string that may contain {{ current_domain }}.
     * @return string The string with the placeholder replaced.
     */
    private function resolveCurrentDomainTag(string $value): string
    {
        $domain = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        if ($domain === '') {
            return $value;
        }

        return preg_replace('/\{\{\s*current_domain\s*\}\}/i', $domain, $value) ?? $value;
    }
}
