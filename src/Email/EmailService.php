<?php

declare(strict_types=1);

namespace EventsInviteManager\Email;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;

/**
 * Handles sending invite and confirmation-code emails via wp_mail().
 *
 * Both methods render an admin-configured HTML template through TemplateRenderer
 * before dispatching. The Content-Type and optional From headers are passed
 * directly to wp_mail() rather than via filters, which is cleaner and avoids
 * any risk of leaking the HTML content-type to other mailers on the same request.
 */
final class EmailService
{
    /** @var TemplateRenderer Template renderer instance. */
    private TemplateRenderer $renderer;

    /**
     * @param TemplateRenderer $renderer Injected renderer for {{ variable }} substitution.
     */
    public function __construct(TemplateRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Sends the invite email to a single invitee.
     *
     * The RSVP URL is built by appending invite_code and event_id as query
     * parameters to the event's configured rsvp_page_url.
     *
     * Available template tags:
     *   {{ event_name }}, {{ first_name }}, {{ last_name }}, {{ full_name }},
     *   {{ email }}, {{ invite_code }}, {{ rsvp_url }}
     *
     * @param Event   $event
     * @param Invitee $invitee
     * @return bool True if wp_mail() accepted the message for delivery.
     */
    public function sendInvite(Event $event, Invitee $invitee): bool
    {
        if (empty($event->inviteEmailTemplate)) {
            return false;
        }

        $rsvpUrl = add_query_arg(
            ['invite_code' => $invitee->inviteCode, 'event_id' => $event->id],
            $event->rsvpPageUrl
        );

        $variables = [
            'event_name'  => esc_html($event->name),
            'first_name'  => esc_html($invitee->firstName),
            'last_name'   => esc_html($invitee->lastName),
            'full_name'   => esc_html($invitee->fullName()),
            'email'       => esc_html($invitee->email),
            'invite_code' => esc_html($invitee->inviteCode),
            'rsvp_url'    => esc_url($rsvpUrl),
        ];

        $subject = $this->renderer->render($event->inviteEmailSubject, $variables)
            ?: "You're Invited: {$event->name}";
        $body    = $this->renderer->render($event->inviteEmailTemplate, $variables);

        return $this->dispatchHtml($invitee->email, $subject, $body, $this->buildFromHeader($event));
    }

    /**
     * Sends a six-digit confirmation code email to the provided address.
     *
     * Available template tag: {{ confirmation_code }}
     *
     * @param Event  $event
     * @param string $email            Recipient email address.
     * @param string $confirmationCode Six-digit numeric string.
     * @return bool True if wp_mail() accepted the message for delivery.
     */
    public function sendConfirmationCode(Event $event, string $email, string $confirmationCode): bool
    {
        if (empty($event->confirmationEmailTemplate)) {
            return false;
        }

        $variables = ['confirmation_code' => $confirmationCode];

        $subject = $this->renderer->render($event->confirmationEmailSubject, $variables)
            ?: 'Your Confirmation Code';
        $body    = $this->renderer->render($event->confirmationEmailTemplate, $variables);

        return $this->dispatchHtml($email, $subject, $body, $this->buildFromHeader($event));
    }

    /**
     * Sends an HTML email by passing Content-Type (and an optional From header)
     * directly to wp_mail() rather than using a filter.
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param string $fromHeader Pre-formatted From header string, or empty to use the site default.
     * @return bool
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
     * Builds a formatted From header string from the event's from_name / from_email fields.
     *
     * Returns an empty string when no from_email is configured, which causes
     * dispatchHtml() to omit the header and let WordPress use its site default.
     *
     * Examples of returned values:
     *   "From: Chris & Jamie <wedding@example.com>"
     *   "From: wedding@example.com"
     *
     * @param Event $event
     * @return string
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
     * Replaces the {{current_domain}} placeholder with the site's current host.
     *
     * Both {{current_domain}} and {{ current_domain }} forms are supported.
     *
     * @param string $value
     * @return string
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
