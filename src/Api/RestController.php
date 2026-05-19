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
use EventsInviteManager\Services\RsvpFlowResolver;
use EventsInviteManager\Services\RsvpFlowResult;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the plugin's public-facing REST API endpoints.
 *
 * Endpoints
 * ─────────
 *   GET  /wp-json/eim/v1/rsvp?confirmation_code={code}
 *     Resolves the QR code via RsvpFlowResolver and returns the full invitation
 *     state including next_action, requires_food, requires_beverage,
 *     newsletter_url, event details, all group members, and lodging options.
 *
 *   POST /wp-json/eim/v1/register
 *     Accepts a confirmation_code and an optional members array for per-person
 *     RSVP status and menu selections. Sets food_confirmed_at /
 *     beverage_confirmed_at when valid required choices are submitted.
 *     Without a members array the legacy behaviour is preserved: all pending
 *     members are marked as attending.
 */
class RestController
{
    /** @var string WordPress REST namespace for all plugin endpoints. */
    private const NAMESPACE = 'eim/v1';

    /** @var RsvpFlowResolver Flow state resolver — injected for testability. */
    private RsvpFlowResolver $resolver;

    /**
     * @param RsvpFlowResolver|null $resolver Optional resolver override; defaults to a fresh instance.
     */
    public function __construct(?RsvpFlowResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new RsvpFlowResolver();
    }

    /**
     * Registers the REST routes with WordPress.
     *
     * Called once from the plugin bootstrap via `rest_api_init`.
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
     * Delegates flow-state resolution to RsvpFlowResolver and returns a
     * comprehensive payload including next_action, requires_food,
     * requires_beverage, newsletter_url, event metadata, all group members
     * with their current RSVP / menu state, and lodging options.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRsvp(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        $event = $result->event;
        $group = $result->group;

        $primaryInvitee = Invitee::find($group->primaryInviteeId);
        if ($primaryInvitee === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Primary invitee not found.'],
                404
            );
        }

        $members = $result->members;
        $venue   = $event->venueId ? Location::find($event->venueId) : null;
        $lodging = $event->lodgingEnabled ? EventLodging::forEvent($event->id) : [];

        $allAttending = !empty($members)
            && count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_ATTENDING)) === count($members);

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
            'success'          => true,
            'next_action'      => $result->nextAction,
            'requires_food'    => $result->requiresFood,
            'requires_beverage' => $result->requiresBeverage,
            'newsletter_url'   => $result->newsletterUrl,
            'event'            => [
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
                'invitee_id'            => $m->id,
                'first_name'            => $m->firstName,
                'last_name'             => $m->lastName,
                'email'                 => $m->email,
                'rsvp_status'           => $m->rsvpStatus ?: InvitationGroup::RSVP_PENDING,
                'registered_at'         => $m->registeredAt,
                'food_option_id'        => $m->foodOptionId,
                'beverage_option_id'    => $m->beverageOptionId,
                'dietary_notes'         => $m->dietaryNotes,
                'food_confirmed_at'     => $m->foodConfirmedAt,
                'beverage_confirmed_at' => $m->beverageConfirmedAt,
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
     * food_confirmed_at and beverage_confirmed_at are set only when valid, required
     * menu-item IDs are submitted alongside the RSVP.
     *
     * If `members` is omitted, all pending members are marked attending (legacy path).
     *
     * Example with per-member data:
     *   {
     *     "confirmation_code": "...",
     *     "members": [
     *       { "invitee_id": 1, "rsvp_status": "attending", "food_option_id": 3, "beverage_option_id": 5 },
     *       { "invitee_id": 2, "rsvp_status": "declined" }
     *     ]
     *   }
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

        $members       = $group->getMembers();
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

            $processedCount = 0;

            foreach ($rawMemberList as $entry) {
                $inviteeId  = (int) ($entry['invitee_id']  ?? 0);
                $rsvpStatus = (string) ($entry['rsvp_status'] ?? InvitationGroup::RSVP_ATTENDING);

                if ($inviteeId <= 0 || !in_array($inviteeId, $validMemberIds, true)) {
                    continue;
                }

                $extras = [];

                if (array_key_exists('food_option_id', $entry)) {
                    $id      = (int) $entry['food_option_id'];
                    $validId = ($id > 0 && in_array($id, $validFoodIds, true)) ? $id : null;
                    $extras['food_option_id'] = $validId;
                    // Set the confirmation timestamp only when a valid required choice is submitted.
                    if ($validId !== null && !empty($validFoodIds)) {
                        $extras['food_confirmed_at'] = current_time('mysql');
                    }
                }

                if (array_key_exists('beverage_option_id', $entry)) {
                    $id      = (int) $entry['beverage_option_id'];
                    $validId = ($id > 0 && in_array($id, $validBevIds, true)) ? $id : null;
                    $extras['beverage_option_id'] = $validId;
                    // Set the confirmation timestamp only when a valid required choice is submitted.
                    if ($validId !== null && !empty($validBevIds)) {
                        $extras['beverage_confirmed_at'] = current_time('mysql');
                    }
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

        // Re-run the resolver so the response always includes the current next_action.
        $flowResult = $this->resolver->resolve($code);

        return new WP_REST_Response([
            'success'            => true,
            'already_registered' => false,
            'message'            => 'You have successfully registered for the event!',
            'next_action'        => $flowResult->success ? $flowResult->nextAction : null,
            'requires_food'      => $flowResult->success ? $flowResult->requiresFood : false,
            'requires_beverage'  => $flowResult->success ? $flowResult->requiresBeverage : false,
            'newsletter_url'     => $flowResult->success ? $flowResult->newsletterUrl : null,
            'invitee'            => $this->inviteePayload($primaryInvitee),
        ], 200);
    }

    /**
     * Builds the minimal invitee sub-payload used in register responses.
     *
     * @param Invitee $invitee
     * @return array{first_name: string, last_name: string, email: string}
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
