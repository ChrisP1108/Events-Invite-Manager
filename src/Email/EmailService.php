<?php

declare(strict_types=1);

namespace EventsInviteManager\Email;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;

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
    private TemplateRenderer $renderer;

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

    private function dispatchHtml(string $to, string $subject, string $body, string $fromHeader = ''): bool
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if ($fromHeader !== '') {
            $headers[] = $fromHeader;
        }

        return wp_mail($to, $subject, $body, $headers);
    }

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

    private function resolveCurrentDomainTag(string $value): string
    {
        $domain = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        if ($domain === '') {
            return $value;
        }

        return preg_replace('/\{\{\s*current_domain\s*\}\}/i', $domain, $value) ?? $value;
    }
}
