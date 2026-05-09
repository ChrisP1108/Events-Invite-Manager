<?php

declare(strict_types=1);

namespace EventsInviteManager\Email;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;

/**
 * Handles sending invite emails via wp_mail().
 *
 * Renders the admin-configured HTML template through TemplateRenderer before
 * dispatching. The Content-Type and optional From headers are passed directly
 * to wp_mail() rather than via filters.
 *
 * Available template tags for invite emails:
 *   {{ event_name }}, {{ first_name }}, {{ last_name }}, {{ full_name }},
 *   {{ email }}, {{ qr_code }}
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
     * @param Event   $event
     * @param Invitee $invitee
     * @param string  $qrCodeImgTag Optional HTML <img> tag for the invitee's QR code (replaces {{ qr_code }}).
     * @return bool True if wp_mail() accepted the message for delivery.
     */
    public function sendInvite(Event $event, Invitee $invitee, string $qrCodeImgTag = ''): bool
    {
        if (empty($event->inviteEmailTemplate)) {
            return false;
        }

        $variables = [
            'event_name' => esc_html($event->name),
            'first_name' => esc_html($invitee->firstName),
            'last_name'  => esc_html($invitee->lastName),
            'full_name'  => esc_html($invitee->fullName()),
            'email'      => esc_html($invitee->email),
            'qr_code'    => $qrCodeImgTag,
        ];

        $subject = $this->renderer->render($event->inviteEmailSubject, $variables)
            ?: "You're Invited: {$event->name}";
        $body    = $this->renderer->render($event->inviteEmailTemplate, $variables);

        return $this->dispatchHtml($invitee->email, $subject, $body, $this->buildFromHeader($event));
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
