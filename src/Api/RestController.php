<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\QrCode;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the plugin's public-facing REST API endpoints.
 *
 *   GET  /wp-json/eim/v1/rsvp?confirmation_code={code}
 *     Looks up the QR code and returns event details, lodging options, and the
 *     invitee's current registration status. Used by the RSVP page to populate
 *     itself before the invitee confirms attendance.
 *
 *   POST /wp-json/eim/v1/register
 *     Validates the 16-character QR code confirmation code, marks the invitee
 *     as registered, and returns their details.
 *
 * Both endpoints are publicly accessible (no authentication required) because they
 * are gated by the confirmation code embedded in the scanned QR code URL.
 */
class RestController
{
    /** @var string REST namespace and version. */
    private const NAMESPACE = 'eim/v1';

    /**
     * Registers the REST routes with WordPress via the rest_api_init action.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NAMESPACE, '/rsvp', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleRsvp'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/register', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRegister'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]);
        });
    }

    /**
     * Handles GET /eim/v1/rsvp.
     *
     * Looks up the QR code by confirmation_code and returns the event details,
     * lodging options, and the invitee's current registration status. Intended
     * to be called by the RSVP WordPress page on load so it can render personalised
     * content before the invitee confirms attendance.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRsvp(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $qrCode = QrCode::findByCode($code);

        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        $event   = Event::find($qrCode->eventId);
        $invitee = Invitee::findForEvent($qrCode->inviteeId, $qrCode->eventId);

        if ($event === null || $invitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event or invitee not found.'],
                404
            );
        }

        $venue   = $event->venueId ? Location::find($event->venueId) : null;
        $lodging = $event->lodgingEnabled ? EventLodging::forEvent($event->id) : [];

        return new WP_REST_Response([
            'success' => true,
            'event'   => [
                'name'        => $event->name,
                'description' => $event->description,
                'date'        => $event->formattedDateTimeRange(),
                'venue'       => $venue ? [
                    'name'    => $venue->name,
                    'address' => $venue->formattedAddress(),
                ] : null,
            ],
            'invitee' => [
                'first_name'    => $invitee->firstName,
                'last_name'     => $invitee->lastName,
                'email'         => $invitee->email,
                'is_registered' => $invitee->isRegistered,
                'registered_at' => $invitee->registeredAt,
            ],
            'lodging' => array_map(static fn(EventLodging $l): array => [
                'name'        => $l->name,
                'address'     => $l->formattedAddress(),
                'booking_url' => $l->bookingUrl,
                'is_other'    => $l->isOther,
            ], $lodging),
        ], 200);
    }

    /**
     * Handles POST /eim/v1/register.
     *
     * Looks up the QR code record by the provided confirmation code, then marks the
     * associated invitee as registered for the event.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRegister(WP_REST_Request $request): WP_REST_Response
    {
        $code = trim((string) $request->get_param('confirmation_code'));

        $qrCode = QrCode::findByCode($code);

        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        $invitee = Invitee::findForEvent($qrCode->inviteeId, $qrCode->eventId);

        if ($invitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation was found for this confirmation code.'],
                404
            );
        }

        if ($invitee->isRegistered) {
            return new WP_REST_Response([
                'success'            => true,
                'already_registered' => true,
                'message'            => 'You are already registered for this event.',
                'invitee'            => $this->inviteePayload($invitee),
            ], 200);
        }

        Invitee::markRegisteredForEvent($invitee->id, $qrCode->eventId);

        return new WP_REST_Response([
            'success'            => true,
            'already_registered' => false,
            'message'            => 'You have successfully registered for the event!',
            'invitee'            => $this->inviteePayload($invitee),
        ], 200);
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
