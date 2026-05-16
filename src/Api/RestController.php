<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\QrCode;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the plugin's public-facing REST API endpoints.
 *
 *   GET  /wp-json/eim/v1/rsvp?confirmation_code={code}
 *     Resolves the QR code to an invitation group. Returns event details, the
 *     primary invitee (for backward compat), and all group_members with rsvp_status.
 *
 *   POST /wp-json/eim/v1/register
 *     Accepts a confirmation_code and an optional members array for per-person
 *     RSVP status. Without members, marks all pending members as attending.
 */
class RestController
{
    private const NAMESPACE = 'eim/v1';

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
                    'members' => [
                        'required' => false,
                        'type'     => 'array',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'invitee_id'         => ['type' => 'integer'],
                                'rsvp_status'        => ['type' => 'string', 'enum' => ['attending', 'declined', 'pending']],
                                'food_option_id'     => ['type' => 'integer'],
                                'beverage_option_id' => ['type' => 'integer'],
                                'dietary_notes'      => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ]);
        });
    }

    /**
     * Handles GET /eim/v1/rsvp.
     *
     * Returns event details, primary invitee (backward compat), all group members
     * with their current rsvp_status, and lodging options.
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

        $group = InvitationGroup::find($qrCode->groupId);
        $event = Event::find($qrCode->eventId);

        if ($group === null || $event === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event or invitation group not found.'],
                404
            );
        }

        $primaryInvitee = Invitee::find($group->primaryInviteeId);

        if ($primaryInvitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Primary invitee not found.'],
                404
            );
        }

        $members = $group->getMembers();
        $venue   = $event->venueId ? Location::find($event->venueId) : null;
        $lodging = $event->lodgingEnabled ? EventLodging::forEvent($event->id) : [];

        $allAttending = !empty($members)
            && count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_ATTENDING)) === count($members);

        // Locate the primary invitee's member record so we can return their
        // registeredAt rather than arbitrarily using the first element, which
        // is ordered alphabetically and may not be the primary recipient.
        $primaryMember = null;
        foreach ($members as $m) {
            if ($m->id === $group->primaryInviteeId) {
                $primaryMember = $m;
                break;
            }
        }

        $mapOption = static fn(MenuItem $o): array => [
            'id'          => $o->id,
            'label'       => $o->label,
            'description' => $o->description,
            'sort_order'  => $o->sortOrder,
        ];

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
            'rsvp_options' => [
                'food'     => $event->foodOptionsEnabled
                    ? array_map($mapOption, MenuItem::forEventByType($event->id, MenuItem::TYPE_FOOD))
                    : [],
                'beverage' => $event->beverageOptionsEnabled
                    ? array_map($mapOption, MenuItem::forEventByType($event->id, MenuItem::TYPE_BEVERAGE))
                    : [],
            ],
            'invitee' => [
                'first_name'    => $primaryInvitee->firstName,
                'last_name'     => $primaryInvitee->lastName,
                'email'         => $primaryInvitee->email,
                'registered_at' => $allAttending ? ($primaryMember?->registeredAt ?? null) : null,
            ],
            'group_members' => array_map(static fn(Invitee $m): array => [
                'invitee_id'         => $m->id,
                'first_name'         => $m->firstName,
                'last_name'          => $m->lastName,
                'email'              => $m->email,
                'rsvp_status'        => $m->rsvpStatus ?: InvitationGroup::RSVP_PENDING,
                'registered_at'      => $m->registeredAt,
                'food_option_id'     => $m->foodOptionId,
                'beverage_option_id' => $m->beverageOptionId,
                'dietary_notes'      => $m->dietaryNotes,
            ], $members),
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
     * If `members` is provided, updates each listed member's rsvp_status individually.
     * If omitted, marks all pending members as attending (backward compatible).
     *
     * Example with per-member statuses:
     *   { "confirmation_code": "...", "members": [
     *       { "invitee_id": 1, "rsvp_status": "attending" },
     *       { "invitee_id": 2, "rsvp_status": "declined" }
     *   ]}
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

        $group = InvitationGroup::find($qrCode->groupId);

        if ($group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation group was found for this confirmation code.'],
                404
            );
        }

        $primaryInvitee = Invitee::find($group->primaryInviteeId);

        if ($primaryInvitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Primary invitee not found.'],
                404
            );
        }

        $members      = $group->getMembers();
        $rawMemberList = $request->get_param('members');

        if (!empty($rawMemberList) && is_array($rawMemberList)) {
            // Build allowlists of active menu item IDs assigned to this event so
            // submitted IDs can be validated before being stored.
            $event = Event::find($group->eventId);

            $validFoodIds = $event?->foodOptionsEnabled
                ? array_column(array_map(
                    static fn(MenuItem $i): array => ['id' => $i->id],
                    MenuItem::forEventByType($group->eventId, MenuItem::TYPE_FOOD)
                ), 'id')
                : [];

            $validBevIds = $event?->beverageOptionsEnabled
                ? array_column(array_map(
                    static fn(MenuItem $i): array => ['id' => $i->id],
                    MenuItem::forEventByType($group->eventId, MenuItem::TYPE_BEVERAGE)
                ), 'id')
                : [];

            // Build an allowlist of invitee IDs that actually belong to this group
            // so a stale or forged payload cannot update unrelated members.
            $validMemberIds = array_map(static fn(Invitee $m): int => $m->id, $members);

            // Per-member status update.
            $processedCount = 0;

            foreach ($rawMemberList as $entry) {
                $inviteeId  = (int) ($entry['invitee_id']  ?? 0);
                $rsvpStatus = (string) ($entry['rsvp_status'] ?? InvitationGroup::RSVP_ATTENDING);

                if ($inviteeId <= 0 || !in_array($inviteeId, $validMemberIds, true)) {
                    continue;
                }

                $extras = [];
                if (array_key_exists('food_option_id', $entry)) {
                    $id = (int) $entry['food_option_id'];
                    $extras['food_option_id'] = ($id > 0 && in_array($id, $validFoodIds, true)) ? $id : null;
                }
                if (array_key_exists('beverage_option_id', $entry)) {
                    $id = (int) $entry['beverage_option_id'];
                    $extras['beverage_option_id'] = ($id > 0 && in_array($id, $validBevIds, true)) ? $id : null;
                }
                if (array_key_exists('dietary_notes', $entry)) {
                    $extras['dietary_notes'] = sanitize_textarea_field((string) $entry['dietary_notes']);
                }

                InvitationGroup::updateMemberRsvp($group->id, $inviteeId, $rsvpStatus, $extras);
                $processedCount++;
            }

            if ($processedCount === 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No valid group members were found in the submitted payload.',
                ], 400);
            }
        } else {
            // Backward-compatible: mark all pending members attending.
            $allAlreadyAttending = !empty($members)
                && count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus !== InvitationGroup::RSVP_ATTENDING)) === 0;

            if ($allAlreadyAttending) {
                return new WP_REST_Response([
                    'success'            => true,
                    'already_registered' => true,
                    'message'            => 'You are already registered for this event.',
                    'invitee'            => $this->inviteePayload($primaryInvitee),
                ], 200);
            }

            InvitationGroup::markAllMembersAttending($group->id);
        }

        return new WP_REST_Response([
            'success'            => true,
            'already_registered' => false,
            'message'            => 'You have successfully registered for the event!',
            'invitee'            => $this->inviteePayload($primaryInvitee),
        ], 200);
    }

    private function inviteePayload(Invitee $invitee): array
    {
        return [
            'first_name' => $invitee->firstName,
            'last_name'  => $invitee->lastName,
            'email'      => $invitee->email,
        ];
    }
}
