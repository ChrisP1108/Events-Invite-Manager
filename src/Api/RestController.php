<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Email\TemplateRenderer;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the plugin's public-facing REST API endpoints.
 *
 * Two endpoints drive the front-end registration flow:
 *
 *   POST /wp-json/eim/v1/request-code
 *     Validates that the submitted email belongs to an invitee for the given event,
 *     generates a cryptographically random six-digit code, stores it as a WordPress
 *     transient (TTL: 15 minutes), and sends it to the invitee's email address.
 *
 *   POST /wp-json/eim/v1/register
 *     Validates the six-digit code against the stored transient and, on success,
 *     marks the invitee as registered and deletes the transient.
 *
 * Both endpoints are publicly accessible (no authentication required) because they
 * are gated by the invite code embedded in the front-end RSVP page URL.
 */
class RestController
{
    /** @var string REST namespace and version. */
    private const NAMESPACE = 'eim/v1';

    /** @var int Confirmation code time-to-live in seconds (15 minutes). */
    private const CODE_TTL = 900;

    /** @var EmailService Handles sending confirmation code emails. */
    private EmailService $emailService;

    /**
     * @param EmailService|null $emailService Optionally inject; defaults to a fresh instance.
     */
    public function __construct(?EmailService $emailService = null)
    {
        $this->emailService = $emailService ?? new EmailService(new TemplateRenderer());
    }

    /**
     * Registers the REST routes with WordPress via the rest_api_init action.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NAMESPACE, '/request-code', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRequestCode'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'email'    => [
                        'required'          => true,
                        'type'              => 'string',
                        'format'            => 'email',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'event_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/register', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRegister'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'email'    => [
                        'required'          => true,
                        'type'              => 'string',
                        'format'            => 'email',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'code'     => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'event_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);
        });
    }

    /**
     * Handles POST /eim/v1/request-code.
     *
     * Looks up the invitee by email and event, generates and stores a six-digit
     * confirmation code as a transient, then emails it to the invitee.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRequestCode(WP_REST_Request $request): WP_REST_Response
    {
        $email   = strtolower(trim((string) $request->get_param('email')));
        $eventId = (int) $request->get_param('event_id');

        $event = Event::find($eventId);
        if ($event === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event not found.'],
                404
            );
        }

        $invitee = Invitee::findByEmailAndEvent($email, $eventId);
        if ($invitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation was found for this email address.'],
                404
            );
        }

        $code = (string) random_int(100000, 999999);
        set_transient($this->transientKey($email, $eventId), $code, self::CODE_TTL);

        $sent = $this->emailService->sendConfirmationCode($event, $email, $code);
        if (!$sent) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Failed to send the confirmation email. Please try again.'],
                500
            );
        }

        return new WP_REST_Response(
            ['success' => true, 'message' => 'A six-digit confirmation code has been sent to your email address. It expires in 15 minutes.'],
            200
        );
    }

    /**
     * Handles POST /eim/v1/register.
     *
     * Validates the six-digit code against the stored transient and, on success,
     * marks the invitee as registered. The transient is deleted on any valid submission
     * to prevent replay attacks.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRegister(WP_REST_Request $request): WP_REST_Response
    {
        $email   = strtolower(trim((string) $request->get_param('email')));
        $code    = trim((string) $request->get_param('code'));
        $eventId = (int) $request->get_param('event_id');

        $event = Event::find($eventId);
        if ($event === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event not found.'],
                404
            );
        }

        $invitee = Invitee::findByEmailAndEvent($email, $eventId);
        if ($invitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation was found for this email address.'],
                404
            );
        }

        $transientKey = $this->transientKey($email, $eventId);
        $storedCode   = get_transient($transientKey);

        if ($storedCode === false) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'The confirmation code has expired. Please request a new code.'],
                400
            );
        }

        if (!hash_equals((string) $storedCode, $code)) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid confirmation code.'],
                400
            );
        }

        delete_transient($transientKey);

        if ($invitee->isRegistered) {
            return new WP_REST_Response([
                'success'            => true,
                'already_registered' => true,
                'message'            => 'You are already registered for this event.',
                'invitee'            => $this->inviteePayload($invitee),
            ], 200);
        }

        Invitee::markRegistered($invitee->id);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'You have successfully registered for the event!',
            'invitee' => $this->inviteePayload($invitee),
        ], 200);
    }

    /**
     * Builds the WordPress transient key for a confirmation code.
     *
     * Keys are hashed to keep them within WordPress's 172-character transient limit
     * and to avoid exposing email addresses in the options table.
     *
     * @param string $email
     * @param int    $eventId
     * @return string
     */
    private function transientKey(string $email, int $eventId): string
    {
        return 'eim_code_' . md5($email . '_' . $eventId);
    }

    /**
     * Returns the public-facing invitee data array included in successful responses.
     *
     * @param Invitee $invitee
     * @return array<string, string>
     */
    private function inviteePayload(Invitee $invitee): array
    {
        return [
            'first_name' => $invitee->firstName,
            'last_name'  => $invitee->lastName,
            'email'      => $invitee->email,
        ];
    }
}
