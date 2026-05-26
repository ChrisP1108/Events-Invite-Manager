<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\EventMessage;
use EventsInviteManager\Models\Gift;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\Newsletter;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Models\RequestedInviteeAddOn;
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

            register_rest_route(self::NAMESPACE, '/newsletters', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleNewsletters'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'newsletter_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/dashboard', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleDashboard'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/registry', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleRegistry'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'event_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/registry/purchase', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRegistryPurchase'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'event_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'gift_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'is_purchased' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/request-guest', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRequestGuest'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'first_name'        => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'last_name'         => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'email'             => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_email'],
                    'phone'             => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'street_address'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'city'              => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'state'             => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'zip_code'          => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'notes'             => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/messages', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handleGetMessages'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'event_id'          => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/messages', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handlePostMessage'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'event_id'          => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                    'message'           => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
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
                                'invitee_id'          => ['type' => 'integer'],
                                'rsvp_status'         => ['type' => 'string', 'enum' => ['attending', 'declined', 'pending']],
                                'food_option_id'      => ['type' => 'integer'],
                                'beverage_option_id'  => ['type' => 'integer'],
                                'dietary_notes'       => ['type' => 'string'],
                                'lodging_id'          => ['type' => 'integer'],
                                'lodging_is_other'    => ['type' => 'boolean'],
                                'lodging_undisclosed' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                    'rsvp_notes' => [
                        'required' => false,
                        'type'     => 'string',
                    ],
                    'lodging_booked' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                    'lodging_notes' => [
                        'required' => false,
                        'type'     => 'string',
                    ],
                    'lodging_id' => [
                        'required' => false,
                        'type'     => 'integer',
                    ],
                    'lodging_is_other' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                    'lodging_undisclosed' => [
                        'required' => false,
                        'type'     => 'boolean',
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

        return $this->flowResponse($result);
    }

    /**
     * Handles GET /eim/v1/newsletters.
     *
     * Validates the confirmation code through the RSVP flow resolver. Newsletter
     * content is returned only when the flow is fully complete; this keeps
     * late-added lodging/menu steps in front of the newsletter page.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleNewsletters(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if (!$result->isComplete() || $result->event === null || $result->group === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before viewing newsletters.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        // Cross-event: gather upcoming registered events for this primary invitee.
        // Each event uses its own QR confirmation code so edit links and late-added
        // RSVP requirements are evaluated against the correct invitation group.
        $entries = $this->registeredDashboardEntries($result, $code, true);

        $newsletterId = (int) ($request->get_param('newsletter_id') ?? 0);

        if ($newsletterId > 0) {
            // Single-newsletter detail: search across all registered events.
            foreach ($entries as $entry) {
                $newsletter = Newsletter::findPublishedForEvent($entry['event']->id, $newsletterId);
                if ($newsletter !== null) {
                    return new WP_REST_Response([
                        'success'       => true,
                        'event_id'      => $entry['event']->id,
                        'group_id'      => $entry['group']->id,
                        'edit_rsvp_url' => $this->buildRsvpEditUrl($entry['event'], $entry['code']),
                        'rsvp_summary'  => $this->rsvpSummaryPayload($entry['flow'], $entry['code']),
                        'newsletter'    => $this->newsletterDetailPayload($newsletter),
                    ], 200);
                }
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Newsletter not found.',
            ], 404);
        }

        // All newsletters across every registered event, grouped by event.
        $allNewsletters = [];
        $eventGroups     = [];
        foreach ($entries as $entry) {
            $newsletters = array_map(
                fn(Newsletter $nl): array => $this->newsletterSummaryPayload($nl, $entry['event']),
                Newsletter::publishedForEvent($entry['event']->id)
            );

            foreach ($newsletters as $newsletter) {
                $allNewsletters[] = $newsletter;
            }

            $eventGroups[] = [
                'event_id'      => $entry['event']->id,
                'group_id'      => $entry['group']->id,
                'edit_rsvp_url' => $this->buildRsvpEditUrl($entry['event'], $entry['code']),
                'event'         => $this->dashboardEventPayload($entry['event']),
                'count'         => count($newsletters),
                'newsletters'   => $newsletters,
            ];
        }

        return new WP_REST_Response([
            'success'       => true,
            'edit_rsvp_url' => $this->buildRsvpEditUrl($result->event, $code),
            'rsvp_summary'  => $this->rsvpSummaryPayload($result, $code),
            'count'         => count($allNewsletters),
            'events'        => $eventGroups,
            'newsletters'   => $allNewsletters,
        ], 200);
    }

    /**
     * Handles GET /eim/v1/dashboard.
     *
     * Returns all upcoming events the invitation group is registered for, along
     * with RSVP details and published newsletters for each. Requires the RSVP
     * flow to be fully complete (next_action === dashboard_redirect or declined).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleDashboard(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if (!$result->isComplete() || $result->event === null || $result->group === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before accessing the dashboard.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        $entries = $this->registeredDashboardEntries($result, $code);

        $events = [];
        foreach ($entries as $entry) {
            $flow        = $entry['flow'];
            $event       = $entry['event'];
            $targetCode  = $entry['code'];
            $newsletters = $flow->nextAction === RsvpFlowResult::ACTION_DASHBOARD_REDIRECT
                ? Newsletter::publishedForEvent($event->id)
                : [];

            $events[] = [
                'event_id'          => $event->id,
                'group_id'          => $entry['group']->id,
                'next_action'       => $flow->nextAction,
                'is_complete'       => $flow->nextAction === RsvpFlowResult::ACTION_DASHBOARD_REDIRECT,
                'requires_lodging'  => $flow->requiresLodging,
                'requires_food'     => $flow->requiresFood,
                'requires_beverage' => $flow->requiresBeverage,
                'dashboard_url'     => $flow->dashboardUrl,
                'edit_rsvp_url'     => $this->buildRsvpEditUrl($event, $targetCode),
                'event'             => $this->dashboardEventPayload($event),
                'rsvp'              => $this->rsvpSummaryPayload($flow, $targetCode),
                'registry'          => $flow->nextAction === RsvpFlowResult::ACTION_DASHBOARD_REDIRECT
                    ? $this->registryPayloadForEvent($event, $entry['group'])
                    : $this->emptyRegistryPayload(),
                'newsletters'       => array_map(
                    fn(Newsletter $nl): array => $this->newsletterSummaryPayload($nl),
                    $newsletters
                ),
            ];
        }

        return new WP_REST_Response([
            'success'       => true,
            'dashboard_url' => $result->dashboardUrl,
            'events'        => $events,
        ], 200);
    }

    /**
     * Handles GET /eim/v1/registry.
     *
     * Returns registry gifts for complete, upcoming events accessible from the
     * provided QR confirmation code. Pass event_id to fetch one event only.
     */
    public function handleRegistry(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if (!$result->isComplete() || $result->event === null || $result->group === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before viewing the registry.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        $targetEventId = (int) ($request->get_param('event_id') ?? 0);
        $entries       = $this->registeredDashboardEntries($result, $code, true);
        $events        = [];

        foreach ($entries as $entry) {
            if ($targetEventId > 0 && $entry['event']->id !== $targetEventId) {
                continue;
            }

            $events[] = [
                'event_id'      => $entry['event']->id,
                'group_id'      => $entry['group']->id,
                'edit_rsvp_url' => $this->buildRsvpEditUrl($entry['event'], $entry['code']),
                'event'         => $this->dashboardEventPayload($entry['event']),
                'registry'      => $this->registryPayloadForEvent($entry['event'], $entry['group']),
            ];
        }

        if ($targetEventId > 0 && empty($events)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Registry not found for this confirmation code and event.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'count'   => count($events),
            'events'  => $events,
        ], 200);
    }

    /**
     * Handles POST /eim/v1/registry/purchase.
     *
     * Marks an event registry item as purchased by the invitation group tied to
     * the QR code. A group can only unmark a gift it previously marked itself.
     */
    public function handleRegistryPurchase(WP_REST_Request $request): WP_REST_Response
    {
        $code      = trim((string) $request->get_param('confirmation_code'));
        $eventId   = (int) $request->get_param('event_id');
        $giftId    = (int) $request->get_param('gift_id');
        $markBought = $request->get_param('is_purchased') === null
            ? true
            : $this->toBool($request->get_param('is_purchased'));

        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if (!$result->isComplete() || $result->event === null || $result->group === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before updating the registry.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        $entry = $this->dashboardEntryForEvent($this->registeredDashboardEntries($result, $code, true), $eventId);
        if ($entry === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'This event registry is not available for this confirmation code.',
            ], 403);
        }

        $gift = Gift::find($giftId);
        if ($gift === null || !Gift::isLinkedToEvent($giftId, $eventId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Gift not found for this event.',
            ], 404);
        }

        $existing       = Gift::purchaseDetailsForGiftEvent($giftId, $eventId);
        $ownerGroupId   = isset($existing['purchased_by_group_id']) ? (int) $existing['purchased_by_group_id'] : null;
        $alreadyBought  = !empty($existing['is_purchased']);
        $currentGroupId = $entry['group']->id;

        if ($markBought && $alreadyBought && $ownerGroupId !== $currentGroupId) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'This gift is already marked as purchased.',
            ], 409);
        }

        if (!$markBought && $alreadyBought && $ownerGroupId !== $currentGroupId) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Only the purchasing invitation group can unmark this gift.',
            ], 403);
        }

        Gift::setPurchaseStatus(
            $giftId,
            $eventId,
            $markBought,
            $markBought ? $currentGroupId : null,
            $markBought ? $entry['group']->primaryInviteeId : null
        );

        $purchase = Gift::purchaseDetailsForGiftEvent($giftId, $eventId);

        return new WP_REST_Response([
            'success' => true,
            'event_id' => $eventId,
            'group_id' => $currentGroupId,
            'gift'    => $this->giftRegistryItemPayload($gift, $eventId, $purchase, $entry['group']),
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
        $hasMemberList = is_array($rawMemberList);
        $rawRsvpNotes  = $request->get_param('rsvp_notes');
        $hasRsvpNotes  = $rawRsvpNotes !== null;
        $rsvpNotes     = $hasRsvpNotes ? sanitize_textarea_field((string) $rawRsvpNotes) : '';
        $rawLodgingBooked = $request->get_param('lodging_booked');
        $hasLodgingBooked = $rawLodgingBooked !== null && $rawLodgingBooked !== '';
        $lodgingBooked    = $hasLodgingBooked ? $this->toBool($rawLodgingBooked) : null;
        $rawLodgingNotes  = $request->get_param('lodging_notes');
        $hasLodgingNotes  = $rawLodgingNotes !== null;
        $lodgingNotes     = $hasLodgingNotes ? sanitize_textarea_field((string) $rawLodgingNotes) : null;
        $currentFlow   = $this->resolver->resolve($code);
        $event         = $currentFlow->event ?? Event::find($group->eventId);

        if ($event === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event not found.'],
                404
            );
        }

        // Block any request that would change a pending member's status after the deadline.
        // This covers all three bypass paths:
        //   (a) legacy call with no members payload → markAllMembersAttending()
        //   (b) member payload including a pending invitee (explicit or defaulting to attending)
        //   (c) member payload omitting a pending invitee → auto-declined below
        // Menu/lodging updates for already-responded members are unaffected because they
        // only reach here when zero pending members remain.
        if ($currentFlow->rsvpDeadlinePassed) {
            $pendingMemberIds = array_values(array_map(
                static fn(Invitee $m): int => $m->id,
                array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_PENDING)
            ));
            if (!empty($pendingMemberIds)) {
                return new WP_REST_Response(
                    ['success' => false, 'message' => 'The RSVP deadline for this event has passed.', 'deadline_passed' => true],
                    422
                );
            }
        }

        $validFoodIds = $event->foodOptionsEnabled
            ? array_column(array_map(
                static fn(MenuItem $i): array => ['id' => $i->id],
                MenuItem::forEventByType($group->eventId, MenuItem::TYPE_FOOD)
            ), 'id')
            : [];

        $validBevIds = $event->beverageOptionsEnabled
            ? array_column(array_map(
                static fn(MenuItem $i): array => ['id' => $i->id],
                MenuItem::forEventByType($group->eventId, MenuItem::TYPE_BEVERAGE)
            ), 'id')
            : [];

        $validLodgingIds = array_map(
            static fn(EventLodging $l): int => $l->id,
            EventLodging::forEvent($group->eventId)
        );

        $groupLodgingSelection = $this->parseLodgingSelection($this->topLevelLodgingParams($request), $validLodgingIds);
        $validationErrors      = $groupLodgingSelection['errors'];
        $validMemberIds        = array_map(static fn(Invitee $m): int => $m->id, $members);
        $memberEntriesById     = [];
        $groupLodgingKey       = empty($groupLodgingSelection['errors'])
            ? $this->lodgingSelectionKey($groupLodgingSelection)
            : '';
        $memberLodgingKey      = '';

        if ($hasMemberList) {
            foreach ($rawMemberList as $entry) {
                $inviteeId = (int) ($entry['invitee_id'] ?? 0);
                if ($inviteeId <= 0 || !in_array($inviteeId, $validMemberIds, true)) {
                    continue;
                }

                $memberEntriesById[$inviteeId] = $entry;

                if (array_key_exists('food_option_id', $entry)) {
                    $foodId = (int) $entry['food_option_id'];
                    if ($foodId > 0 && !in_array($foodId, $validFoodIds, true)) {
                        $validationErrors['members.' . $inviteeId . '.food_option_id'] = 'Choose a valid food option for this event.';
                    }
                }

                if (array_key_exists('beverage_option_id', $entry)) {
                    $beverageId = (int) $entry['beverage_option_id'];
                    if ($beverageId > 0 && !in_array($beverageId, $validBevIds, true)) {
                        $validationErrors['members.' . $inviteeId . '.beverage_option_id'] = 'Choose a valid beverage option for this event.';
                    }
                }

                $submittedStatus = (string) ($entry['rsvp_status'] ?? InvitationGroup::RSVP_ATTENDING);
                if ($submittedStatus === InvitationGroup::RSVP_ATTENDING) {
                    if ($currentFlow->requiresFood && array_key_exists('food_option_id', $entry) && (int) $entry['food_option_id'] <= 0) {
                        $validationErrors['members.' . $inviteeId . '.food_option_id'] = 'Choose a food option.';
                    }
                    if ($currentFlow->requiresBeverage && array_key_exists('beverage_option_id', $entry) && (int) $entry['beverage_option_id'] <= 0) {
                        $validationErrors['members.' . $inviteeId . '.beverage_option_id'] = 'Choose a beverage option.';
                    }
                }

                $entryLodgingSelection = $this->parseLodgingSelection($entry, $validLodgingIds);
                foreach ($entryLodgingSelection['errors'] as $field => $message) {
                    $validationErrors['members.' . $inviteeId . '.' . $field] = $message;
                }

                $entryLodgingKey = $this->lodgingSelectionKey($entryLodgingSelection);
                if ($entryLodgingKey !== '') {
                    if ($groupLodgingKey !== '' && $entryLodgingKey !== $groupLodgingKey) {
                        $validationErrors['members.' . $inviteeId . '.lodging'] = 'Lodging is shared by the group. Use the group-level lodging selection.';
                    } elseif ($groupLodgingKey === '' && $memberLodgingKey !== '' && $entryLodgingKey !== $memberLodgingKey) {
                        $validationErrors['members.' . $inviteeId . '.lodging'] = 'Lodging is shared by the group. Choose one lodging option for the group.';
                    } elseif ($groupLodgingKey === '' && $memberLodgingKey === '') {
                        $memberLodgingKey = $entryLodgingKey;
                    }
                }
            }

            if (!empty($rawMemberList) && empty($memberEntriesById)) {
                $validationErrors['members'] = 'No valid group members were found in the submitted payload.';
            }
        }

        if ($currentFlow->nextAction === RsvpFlowResult::ACTION_LODGING_REQUIRED && !$this->hasLodgingSelection($groupLodgingSelection, $memberEntriesById, $validLodgingIds)) {
            $validationErrors['lodging'] = 'Choose a lodging option, Other, or Prefer not to disclose.';
        }

        if ($currentFlow->nextAction === RsvpFlowResult::ACTION_MENU_REQUIRED) {
            if (empty($memberEntriesById)) {
                $validationErrors['members'] = 'Submit menu selections for the attending invitees.';
            }

            foreach ($currentFlow->members as $member) {
                if ($member->rsvpStatus !== InvitationGroup::RSVP_ATTENDING) {
                    continue;
                }

                $entry = $memberEntriesById[$member->id] ?? null;
                if ($entry === null) {
                    if ($currentFlow->requiresFood && $member->foodConfirmedAt === null) {
                        $validationErrors['members.' . $member->id . '.food_option_id'] = 'Choose a food option.';
                    }
                    if ($currentFlow->requiresBeverage && $member->beverageConfirmedAt === null) {
                        $validationErrors['members.' . $member->id . '.beverage_option_id'] = 'Choose a beverage option.';
                    }
                    continue;
                }

                $submittedStatus = (string) ($entry['rsvp_status'] ?? $member->rsvpStatus);
                if ($submittedStatus !== InvitationGroup::RSVP_ATTENDING) {
                    continue;
                }

                if ($currentFlow->requiresFood && $member->foodConfirmedAt === null) {
                    $foodId = (int) ($entry['food_option_id'] ?? 0);
                    if ($foodId <= 0 || !in_array($foodId, $validFoodIds, true)) {
                        $validationErrors['members.' . $member->id . '.food_option_id'] = 'Choose a food option.';
                    }
                }

                if ($currentFlow->requiresBeverage && $member->beverageConfirmedAt === null) {
                    $beverageId = (int) ($entry['beverage_option_id'] ?? 0);
                    if ($beverageId <= 0 || !in_array($beverageId, $validBevIds, true)) {
                        $validationErrors['members.' . $member->id . '.beverage_option_id'] = 'Choose a beverage option.';
                    }
                }
            }
        }

        if (!empty($validationErrors)) {
            return $this->validationErrorResponse($validationErrors);
        }

        if ($hasMemberList) {
            $processedCount     = 0;
            $memberLevelLodging = ['provided' => false, 'extras' => []];

            foreach ($rawMemberList as $entry) {
                $inviteeId  = (int) ($entry['invitee_id']  ?? 0);
                $rsvpStatus = (string) ($entry['rsvp_status'] ?? InvitationGroup::RSVP_ATTENDING);

                if ($inviteeId <= 0 || !in_array($inviteeId, $validMemberIds, true)) {
                    continue;
                }

                $extras = [];

                if ($rsvpStatus !== InvitationGroup::RSVP_ATTENDING) {
                    InvitationGroup::updateMemberRsvp($group->id, $inviteeId, $rsvpStatus, $this->clearedSelectionExtras());
                    $processedCount++;
                    continue;
                }

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

                $entryLodgingSelection = $this->parseLodgingSelection($entry, $validLodgingIds);
                if ($entryLodgingSelection['provided']) {
                    $extras = array_merge($extras, $entryLodgingSelection['extras']);
                    // Capture the first member-level lodging for group-wide propagation below.
                    if (!$memberLevelLodging['provided']) {
                        $memberLevelLodging = $entryLodgingSelection;
                    }
                } elseif ($groupLodgingSelection['provided']) {
                    $extras = array_merge($extras, $groupLodgingSelection['extras']);
                }

                InvitationGroup::updateMemberRsvp($group->id, $inviteeId, $rsvpStatus, $extras);
                $processedCount++;
            }

            // Auto-decline any pending members omitted from the payload.
            // This handles checkbox-style UIs where unchecked = not attending.
            foreach ($members as $member) {
                if ($member->rsvpStatus === InvitationGroup::RSVP_PENDING
                    && !array_key_exists($member->id, $memberEntriesById)) {
                    InvitationGroup::updateMemberRsvp($group->id, $member->id, InvitationGroup::RSVP_DECLINED, $this->clearedSelectionExtras());
                    $processedCount++;
                }
            }

            $hasGroupLevelUpdate = $hasRsvpNotes
                || $hasLodgingBooked
                || $hasLodgingNotes
                || $groupLodgingSelection['provided']
                || $memberLevelLodging['provided'];
            if ($processedCount === 0 && !$hasGroupLevelUpdate) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No valid group members were found in the submitted payload.',
                ], 400);
            }

            if ($hasRsvpNotes) {
                InvitationGroup::updateRsvpNotes($group->id, $rsvpNotes);
            }
            if ($hasLodgingBooked || $hasLodgingNotes) {
                InvitationGroup::updateLodgingDetails($group->id, $lodgingBooked, $lodgingNotes);
            }

            // Propagate whichever lodging selection was provided (group-level takes
            // precedence over member-level) to every attending member so lodging is
            // always a single group-wide choice rather than per-member.
            $effectiveLodging = $groupLodgingSelection['provided'] ? $groupLodgingSelection : $memberLevelLodging;
            if ($effectiveLodging['provided']) {
                $freshGroup = InvitationGroup::find($group->id);
                foreach (($freshGroup?->getMembers() ?? []) as $member) {
                    if ($member->rsvpStatus === InvitationGroup::RSVP_ATTENDING) {
                        InvitationGroup::updateMemberRsvp($group->id, $member->id, $member->rsvpStatus, $effectiveLodging['extras']);
                    }
                }
            }
        } else {
            // Backward-compatible: mark all pending members attending.
            $allAlreadyAttending = !empty($members)
                && count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus !== InvitationGroup::RSVP_ATTENDING)) === 0;

            if ($allAlreadyAttending) {
                if ($groupLodgingSelection['provided']) {
                    foreach ($members as $member) {
                        if ($member->rsvpStatus === InvitationGroup::RSVP_ATTENDING) {
                            InvitationGroup::updateMemberRsvp($group->id, $member->id, $member->rsvpStatus, $groupLodgingSelection['extras']);
                        }
                    }
                }

                if ($hasRsvpNotes) {
                    InvitationGroup::updateRsvpNotes($group->id, $rsvpNotes);
                }
                if ($hasLodgingBooked || $hasLodgingNotes) {
                    InvitationGroup::updateLodgingDetails($group->id, $lodgingBooked, $lodgingNotes);
                }

                return $this->flowResponse(
                    $this->resolver->resolve($code),
                    [
                        'already_registered' => true,
                        'message'            => 'You are already registered for this event.',
                    ]
                );
            }

            InvitationGroup::markAllMembersAttending($group->id);

            if ($hasRsvpNotes) {
                InvitationGroup::updateRsvpNotes($group->id, $rsvpNotes);
            }
            if ($hasLodgingBooked || $hasLodgingNotes) {
                InvitationGroup::updateLodgingDetails($group->id, $lodgingBooked, $lodgingNotes);
            }

            if ($groupLodgingSelection['provided']) {
                foreach ($members as $member) {
                    if ($member->rsvpStatus !== InvitationGroup::RSVP_DECLINED) {
                        InvitationGroup::updateMemberRsvp($group->id, $member->id, InvitationGroup::RSVP_ATTENDING, $groupLodgingSelection['extras']);
                    }
                }
            }
        }

        // Re-run the resolver so the response always includes the current next_action.
        $flowResult = $this->resolver->resolve($code);

        return $this->flowResponse(
            $flowResult,
            [
                'already_registered' => false,
                'message'            => 'You have successfully registered for the event!',
            ]
        );
    }

    /**
     * Handles POST /eim/v1/request-guest.
     *
     * Allows an authenticated invitee (via QR code) to request that an additional
     * guest be added to their invitation group. The request is stored as a pending
     * RequestedInviteeAddOn for admin review and approval.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRequestGuest(WP_REST_Request $request): WP_REST_Response
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

        $email = strtolower(trim((string) $request->get_param('email')));
        if ($email === '' || !is_email($email)) {
            return $this->validationErrorResponse(['email' => 'Enter a valid email address.']);
        }

        $connectionGroupId = $this->resolveConnectionGroupId($group);
        if ($connectionGroupId === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No connection group found for this invitation.'],
                422
            );
        }

        // Duplicate-pending check: same email + invitation group.
        global $wpdb;
        $riarTable = DatabaseManager::requestedInviteeAddOnsTable();
        $existing  = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$riarTable} WHERE invitation_group_id = %d AND email = %s AND status = 'pending' LIMIT 1",
            $group->id,
            $email
        ));
        if ($existing !== null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'A pending request for this email already exists for your invitation.'],
                409
            );
        }

        $id = RequestedInviteeAddOn::create([
            'connection_group_id' => $connectionGroupId,
            'event_id'            => $qrCode->eventId,
            'invitation_group_id' => $group->id,
            'first_name'          => (string) $request->get_param('first_name'),
            'last_name'           => (string) $request->get_param('last_name'),
            'email'               => $email,
            'phone'               => (string) ($request->get_param('phone') ?? ''),
            'street_address'      => (string) ($request->get_param('street_address') ?? ''),
            'city'                => (string) ($request->get_param('city') ?? ''),
            'state'               => (string) ($request->get_param('state') ?? ''),
            'zip_code'            => (string) ($request->get_param('zip_code') ?? ''),
            'notes'               => (string) ($request->get_param('notes') ?? ''),
        ]);

        if ($id === false) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Failed to submit guest request. Please try again.'],
                500
            );
        }

        return new WP_REST_Response(['success' => true, 'request_id' => $id], 201);
    }

    /**
     * Handles GET /eim/v1/messages.
     *
     * Returns all messages for the invitation group's connection group scoped to
     * the QR code's event. The event_id must match the QR code's event so an
     * invitee cannot read messages for events they are not invited to.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleGetMessages(WP_REST_Request $request): WP_REST_Response
    {
        $code    = trim((string) $request->get_param('confirmation_code'));
        $eventId = (int) $request->get_param('event_id');

        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        if ($qrCode->eventId !== $eventId) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'This event is not accessible via this confirmation code.'],
                403
            );
        }

        $group = InvitationGroup::find($qrCode->groupId);
        if ($group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation group was found for this confirmation code.'],
                404
            );
        }

        $connectionGroupId = $this->resolveConnectionGroupId($group);
        if ($connectionGroupId === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No connection group found for this invitation.'],
                422
            );
        }

        $messages = EventMessage::forEventGroup($eventId, $connectionGroupId);

        return new WP_REST_Response([
            'success'  => true,
            'event_id' => $eventId,
            'group_id' => $connectionGroupId,
            'count'    => count($messages),
            'messages' => array_map(static fn(EventMessage $msg): array => [
                'id'         => $msg->id,
                'message'    => $msg->message,
                'is_read'    => (bool) $msg->isRead,
                'created_at' => $msg->createdAt,
            ], $messages),
        ], 200);
    }

    /**
     * Handles POST /eim/v1/messages.
     *
     * Creates a new message from the invitee's connection group for the QR code's
     * event. The event_id must match the QR code's event.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handlePostMessage(WP_REST_Request $request): WP_REST_Response
    {
        $code    = trim((string) $request->get_param('confirmation_code'));
        $eventId = (int) $request->get_param('event_id');
        $message = trim((string) $request->get_param('message'));

        if ($message === '') {
            return $this->validationErrorResponse(['message' => 'Message cannot be empty.']);
        }

        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        if ($qrCode->eventId !== $eventId) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'This event is not accessible via this confirmation code.'],
                403
            );
        }

        $group = InvitationGroup::find($qrCode->groupId);
        if ($group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation group was found for this confirmation code.'],
                404
            );
        }

        $connectionGroupId = $this->resolveConnectionGroupId($group);
        if ($connectionGroupId === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No connection group found for this invitation.'],
                422
            );
        }

        $id = EventMessage::create($eventId, $connectionGroupId, $message);

        if ($id === false) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Failed to send message. Please try again.'],
                500
            );
        }

        return new WP_REST_Response(['success' => true, 'message_id' => $id], 201);
    }

    /**
     * Builds the shared RSVP payload returned by GET /rsvp and POST /register.
     *
     * @param RsvpFlowResult      $result Flow state from the resolver.
     * @param array<string,mixed> $extra  Additional top-level fields to merge into the response.
     * @return WP_REST_Response
     */
    private function flowResponse(RsvpFlowResult $result, array $extra = []): WP_REST_Response
    {
        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if ($result->event === null || $result->group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event or invitation group not found.'],
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
        foreach ($members as $member) {
            if ($member->id === $group->primaryInviteeId) {
                $primaryMember = $member;
                break;
            }
        }

        $mapOption = static fn(MenuItem $option): array => [
            'id'          => $option->id,
            'label'       => $option->label,
            'description' => $option->description,
            'sort_order'  => $option->sortOrder,
        ];

        $payload = [
            'success'           => true,
            'next_action'       => $result->nextAction,
            'requires_lodging'  => $result->requiresLodging,
            'requires_food'     => $result->requiresFood,
            'requires_beverage' => $result->requiresBeverage,
            'dashboard_url'     => $result->dashboardUrl,
            'rsvp_notes'        => $group->rsvpNotes,
            'rsvp_notes_updated_at' => $group->rsvpNotesUpdatedAt,
            'lodging_booked'    => $group->lodgingBooked,
            'lodging_booked_at' => $group->lodgingBookedAt,
            'lodging_notes'     => $group->lodgingNotes,
            'event'             => [
                'name'                => $event->name,
                'description'         => $event->description,
                'date'                => $event->formattedDateTimeRange(),
                'rsvp_deadline'       => $event->rsvpDeadline,
                'rsvp_deadline_passed' => $result->rsvpDeadlinePassed,
                'can_rsvp'            => !$result->rsvpDeadlinePassed,
                'venue'               => $venue ? [
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
            ] + $this->inviteeImagePayload($primaryInvitee),
            'group_members' => array_map(fn(Invitee $member): array => [
                'invitee_id'            => $member->id,
                'first_name'            => $member->firstName,
                'last_name'             => $member->lastName,
                'email'                 => $member->email,
                'rsvp_status'           => $member->rsvpStatus ?: InvitationGroup::RSVP_PENDING,
                'registered_at'         => $member->registeredAt,
                'food_option_id'        => $member->foodOptionId,
                'beverage_option_id'    => $member->beverageOptionId,
                'dietary_notes'         => $member->dietaryNotes,
                'food_confirmed_at'     => $member->foodConfirmedAt,
                'beverage_confirmed_at' => $member->beverageConfirmedAt,
                'lodging_id'            => $member->lodgingId,
                'lodging_is_other'      => $member->lodgingIsOther,
                'lodging_undisclosed'   => $member->lodgingUndisclosed,
                'lodging_confirmed_at'  => $member->lodgingConfirmedAt,
            ] + $this->inviteeImagePayload($member), $members),
            'lodging' => array_map(static fn(EventLodging $location): array => [
                'id'          => $location->id,
                'name'        => $location->name,
                'address'     => $location->formattedAddress(),
                'booking_url' => $location->bookingUrl,
                'is_other'    => $location->isOther,
            ], $lodging),
        ];

        return new WP_REST_Response(array_merge($payload, $extra), 200);
    }

    /**
     * Extracts optional group-level lodging fields from a request.
     *
     * @param WP_REST_Request $request
     * @return array<string,mixed>
     */
    private function topLevelLodgingParams(WP_REST_Request $request): array
    {
        $params = [];

        foreach (['lodging_id', 'lodging_is_other', 'lodging_undisclosed'] as $field) {
            $value = $request->get_param($field);
            if ($value !== null && $value !== '') {
                $params[$field] = $value;
            }
        }

        return $params;
    }

    /**
     * Parses and validates a lodging choice from either top-level or member payload data.
     *
     * @param array<string,mixed> $source
     * @param int[]               $validLodgingIds
     * @return array{provided: bool, extras: array<string,mixed>, errors: array<string,string>}
     */
    private function parseLodgingSelection(array $source, array $validLodgingIds): array
    {
        $provided = array_key_exists('lodging_id', $source)
            || array_key_exists('lodging_is_other', $source)
            || array_key_exists('lodging_undisclosed', $source);

        if (!$provided) {
            return ['provided' => false, 'extras' => [], 'errors' => []];
        }

        $lodgingIsOther   = $this->toBool($source['lodging_is_other'] ?? false);
        $lodgingUndisclosed = $this->toBool($source['lodging_undisclosed'] ?? false);
        $lodgingId        = array_key_exists('lodging_id', $source) ? (int) $source['lodging_id'] : 0;
        $errors           = [];

        if ($lodgingIsOther && $lodgingUndisclosed) {
            $errors['lodging'] = 'Choose either Other or Prefer not to disclose, not both.';
        }

        if (empty($errors)) {
            if ($lodgingIsOther || $lodgingUndisclosed) {
                return [
                    'provided' => true,
                    'extras'   => [
                        'lodging_id'           => null,
                        'lodging_is_other'     => $lodgingIsOther,
                        'lodging_undisclosed'  => $lodgingUndisclosed,
                        'lodging_confirmed_at' => current_time('mysql'),
                    ],
                    'errors' => [],
                ];
            }

            if ($lodgingId > 0 && in_array($lodgingId, $validLodgingIds, true)) {
                return [
                    'provided' => true,
                    'extras'   => [
                        'lodging_id'           => $lodgingId,
                        'lodging_is_other'     => false,
                        'lodging_undisclosed'  => false,
                        'lodging_confirmed_at' => current_time('mysql'),
                    ],
                    'errors' => [],
                ];
            }

            $errors['lodging'] = $lodgingId > 0
                ? 'Choose a valid lodging option for this event.'
                : 'Choose a lodging option, Other, or Prefer not to disclose.';
        }

        return ['provided' => true, 'extras' => [], 'errors' => $errors];
    }

    /**
     * Returns a stable comparison key for a valid lodging selection.
     *
     * @param array{provided: bool, extras: array<string,mixed>, errors: array<string,string>} $selection
     * @return string
     */
    private function lodgingSelectionKey(array $selection): string
    {
        if (!$selection['provided'] || !empty($selection['errors']) || empty($selection['extras'])) {
            return '';
        }

        $extras = $selection['extras'];

        if (!empty($extras['lodging_undisclosed'])) {
            return 'undisclosed';
        }

        if (!empty($extras['lodging_is_other'])) {
            return 'other';
        }

        $lodgingId = isset($extras['lodging_id']) ? (int) $extras['lodging_id'] : 0;

        return $lodgingId > 0 ? 'lodging:' . $lodgingId : '';
    }

    /**
     * Returns true when a group-level or member-level lodging choice is present and valid.
     *
     * @param array{provided: bool, extras: array<string,mixed>, errors: array<string,string>} $groupSelection
     * @param array<int,array<string,mixed>> $memberEntriesById
     * @param int[] $validLodgingIds
     * @return bool
     */
    private function hasLodgingSelection(array $groupSelection, array $memberEntriesById, array $validLodgingIds): bool
    {
        if ($groupSelection['provided'] && empty($groupSelection['errors']) && !empty($groupSelection['extras'])) {
            return true;
        }

        foreach ($memberEntriesById as $entry) {
            $selection = $this->parseLodgingSelection($entry, $validLodgingIds);
            if ($selection['provided'] && empty($selection['errors']) && !empty($selection['extras'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converts REST/form boolean-ish values into actual booleans.
     *
     * @param mixed $value
     * @return bool
     */
    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Builds a structured validation-error response for frontend form rendering.
     *
     * @param array<string,string> $errors
     * @return WP_REST_Response
     */
    private function validationErrorResponse(array $errors): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Please correct the highlighted fields.',
            'errors'  => $errors,
        ], 422);
    }

    /**
     * Returns the compact newsletter shape used by the public listing endpoint.
     *
     * @param Newsletter $newsletter
     * @param Event|null $event
     * @return array<string,mixed>
     */
    private function newsletterSummaryPayload(Newsletter $newsletter, ?Event $event = null): array
    {
        $plainContent = trim(wp_strip_all_tags($newsletter->content));

        $payload = [
            'id'           => $newsletter->id,
            'title'        => $newsletter->title,
            'excerpt'      => wp_trim_words($plainContent, 40, '...'),
            'publish_date' => $newsletter->publishDate,
        ];

        if ($event !== null) {
            $payload['event_id']   = $event->id;
            $payload['event_name'] = $event->name;
        }

        return $payload;
    }

    /**
     * Returns the full newsletter shape used by the public detail endpoint.
     *
     * @param Newsletter $newsletter
     * @return array<string,mixed>
     */
    private function newsletterDetailPayload(Newsletter $newsletter): array
    {
        return [
            'id'           => $newsletter->id,
            'title'        => $newsletter->title,
            'content'      => $newsletter->content,
            'publish_date' => $newsletter->publishDate,
        ];
    }

    /**
     * Returns a compact venue payload for the dashboard event listing.
     *
     * @param int $venueId
     * @return array<string,mixed>|null
     */
    private function venuePayload(int $venueId): ?array
    {
        $venue = Location::find($venueId);

        if ($venue === null) {
            return null;
        }

        return [
            'name'    => $venue->name,
            'address' => $venue->formattedAddress(),
        ];
    }

    /**
     * Builds the public registry payload for one event.
     *
     * @param Event                $event
     * @param InvitationGroup|null $viewerGroup The current dashboard group, used
     *                                          only to flag whether it owns a purchase.
     * @return array<string,mixed>
     */
    private function registryPayloadForEvent(Event $event, ?InvitationGroup $viewerGroup = null): array
    {
        $gifts       = Gift::forEvent($event->id, '', 'name', 'asc');
        $purchaseMap = Gift::purchaseDetailsForEvent($event->id);
        $items       = array_map(
            fn(Gift $gift): array => $this->giftRegistryItemPayload(
                $gift,
                $event->id,
                $purchaseMap[$gift->id] ?? null,
                $viewerGroup
            ),
            $gifts
        );

        $purchasedCount = count(array_filter(
            $items,
            static fn(array $item): bool => !empty($item['is_purchased'])
        ));

        return [
            'count'           => count($items),
            'purchased_count' => $purchasedCount,
            'available_count' => count($items) - $purchasedCount,
            'gifts'           => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyRegistryPayload(): array
    {
        return [
            'count'           => 0,
            'purchased_count' => 0,
            'available_count' => 0,
            'gifts'           => [],
        ];
    }

    /**
     * Returns a public registry item shape.
     *
     * @param Gift                 $gift
     * @param int                  $eventId
     * @param array<string,mixed>|null $purchase
     * @param InvitationGroup|null $viewerGroup
     * @return array<string,mixed>
     */
    private function giftRegistryItemPayload(
        Gift $gift,
        int $eventId,
        ?array $purchase = null,
        ?InvitationGroup $viewerGroup = null
    ): array {
        $isPurchased  = !empty($purchase['is_purchased']);
        $ownerGroupId = isset($purchase['purchased_by_group_id']) ? (int) $purchase['purchased_by_group_id'] : null;
        $ownedByViewer = $viewerGroup !== null
            && $ownerGroupId !== null
            && $ownerGroupId === $viewerGroup->id;
        $imageThumbnailUrl = $gift->imageUrl('thumbnail');
        $imageFullUrl      = $gift->imageUrl('full');
        $imageUrl          = $gift->imageUrl('medium');
        if ($imageUrl === '') {
            $imageUrl = $imageFullUrl !== '' ? $imageFullUrl : $imageThumbnailUrl;
        }

        return [
            'id'                         => $gift->id,
            'event_id'                   => $eventId,
            'name'                       => $gift->name,
            'description'                => $gift->description,
            'price_cents'                => $gift->priceCents,
            'formatted_price'            => $gift->formattedPrice(),
            'website_url'                => $gift->websiteUrl,
            'image_attachment_id'        => $gift->imageAttachmentId,
            'image_alt'                  => $gift->imageAttachmentId > 0 ? ($gift->imageAltText() ?: $gift->name) : '',
            'image_thumbnail_url'        => $imageThumbnailUrl,
            'image_url'                  => $imageUrl,
            'image_full_url'             => $imageFullUrl,
            'is_purchased'               => $isPurchased,
            'purchased_at'               => $isPurchased ? ($purchase['purchased_at'] ?? null) : null,
            'purchased_by_current_group' => $ownedByViewer,
            'can_mark_purchased'         => !$isPurchased || $ownedByViewer,
            'can_unmark_purchased'       => $ownedByViewer,
        ];
    }

    /**
     * Returns all upcoming registered dashboard entries accessible from a QR code.
     *
     * Registered means at least one member of the invitation group is attending.
     * Entries can optionally require the per-event flow to be fully complete,
     * which is useful for newsletter access.
     *
     * @param RsvpFlowResult $result
     * @param string         $fallbackCode
     * @param bool           $requireCompleteFlow
     * @return array<int,array{group:InvitationGroup,event:Event,code:string,flow:RsvpFlowResult}>
     */
    private function registeredDashboardEntries(RsvpFlowResult $result, string $fallbackCode, bool $requireCompleteFlow = false): array
    {
        if ($result->group === null) {
            return [];
        }

        $groups = InvitationGroup::forPrimaryInvitee($result->group->primaryInviteeId);
        $nowUtc = current_time('mysql', true);
        $entries = [];
        $seenGroupIds = [];

        foreach ($groups as $group) {
            if (isset($seenGroupIds[$group->id])) {
                continue;
            }
            $seenGroupIds[$group->id] = true;

            $code = $this->confirmationCodeForGroup($group, $result->group, $fallbackCode);
            if ($code === '') {
                continue;
            }

            $flow = $group->id === $result->group->id
                ? $result
                : $this->resolver->resolve($code);

            if (!$flow->success || $flow->event === null || $flow->group === null) {
                continue;
            }

            if (!$this->hasAttendingMembers($flow->members)) {
                continue;
            }

            if (!$this->isUpcomingEvent($flow->event, $nowUtc)) {
                continue;
            }

            if ($requireCompleteFlow && $flow->nextAction !== RsvpFlowResult::ACTION_DASHBOARD_REDIRECT) {
                continue;
            }

            $entries[] = [
                'group' => $flow->group,
                'event' => $flow->event,
                'code'  => $code,
                'flow'  => $flow,
            ];
        }

        return $entries;
    }

    /**
     * Finds one dashboard entry by event ID.
     *
     * @param array<int,array{group:InvitationGroup,event:Event,code:string,flow:RsvpFlowResult}> $entries
     * @return array{group:InvitationGroup,event:Event,code:string,flow:RsvpFlowResult}|null
     */
    private function dashboardEntryForEvent(array $entries, int $eventId): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['event']->id === $eventId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Returns the QR confirmation code for a dashboard group.
     *
     * @param InvitationGroup $group
     * @param InvitationGroup $currentGroup
     * @param string          $fallbackCode
     * @return string
     */
    private function confirmationCodeForGroup(InvitationGroup $group, InvitationGroup $currentGroup, string $fallbackCode): string
    {
        if ($group->id === $currentGroup->id && $fallbackCode !== '') {
            return $fallbackCode;
        }

        $qrCode = QrCode::findForGroup($group->id);

        return $qrCode?->confirmationCode ?? '';
    }

    /**
     * Returns true when an event has not ended yet.
     *
     * Event datetimes are stored in UTC, so this compares against UTC WordPress time.
     *
     * @param Event  $event
     * @param string $nowUtc
     * @return bool
     */
    private function isUpcomingEvent(Event $event, string $nowUtc): bool
    {
        $endDatetime = $event->endDatetime ?? $event->startDatetime ?? null;

        return $endDatetime === null || $endDatetime >= $nowUtc;
    }

    /**
     * Returns true when at least one invitee in the group is attending.
     *
     * @param Invitee[] $members
     * @return bool
     */
    private function hasAttendingMembers(array $members): bool
    {
        foreach ($members as $member) {
            if ($member->rsvpStatus === InvitationGroup::RSVP_ATTENDING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the compact event payload used by public dashboard and newsletter data.
     *
     * @param Event $event
     * @return array<string,mixed>
     */
    private function dashboardEventPayload(Event $event): array
    {
        return [
            'name'           => $event->name,
            'description'    => $event->description,
            'date'           => $event->formattedDateTimeRange(),
            'start_datetime' => $event->startDatetime,
            'end_datetime'   => $event->endDatetime,
            'timezone'       => $event->timezone,
            'venue'          => $event->venueId ? $this->venuePayload($event->venueId) : null,
        ];
    }

    /**
     * Builds the RSVP edit URL for the event's configured RSVP page.
     *
     * @param Event  $event
     * @param string $code
     * @return string|null
     */
    private function buildRsvpEditUrl(Event $event, string $code): ?string
    {
        if ($event->rsvpPageId === null || $event->rsvpPageId <= 0) {
            return null;
        }

        $url = get_permalink($event->rsvpPageId);

        if ($url === false || $url === '') {
            return null;
        }

        $url = add_query_arg('eim_confirmation', rawurlencode($code), $url);

        return add_query_arg('eim_edit', '1', $url);
    }

    /** @return array<string,mixed> */
    private function inviteeImagePayload(Invitee $invitee): array
    {
        $thumbnailUrl = $invitee->imageUrl('thumbnail');
        $fullUrl      = $invitee->imageUrl('full');
        $imageUrl     = $invitee->imageUrl('medium');
        if ($imageUrl === '') {
            $imageUrl = $fullUrl !== '' ? $fullUrl : $thumbnailUrl;
        }

        return [
            'image_attachment_id' => $invitee->imageAttachmentId,
            'image_alt'           => $invitee->imageAttachmentId > 0 ? ($invitee->imageAltText() ?: $invitee->fullName()) : '',
            'image_thumbnail_url' => $thumbnailUrl,
            'image_url'           => $imageUrl,
            'image_full_url'      => $fullUrl,
        ];
    }

    /**
     * Returns the current invitee group's RSVP selections for newsletter rendering.
     *
     * @param RsvpFlowResult $result
     * @param string         $code
     * @return array<string,mixed>
     */
    private function rsvpSummaryPayload(RsvpFlowResult $result, string $code): array
    {
        if ($result->event === null || $result->group === null) {
            return [];
        }

        $event         = $result->event;
        $foodOptions   = $event->foodOptionsEnabled
            ? MenuItem::forEventByType($event->id, MenuItem::TYPE_FOOD)
            : [];
        $bevOptions    = $event->beverageOptionsEnabled
            ? MenuItem::forEventByType($event->id, MenuItem::TYPE_BEVERAGE)
            : [];
        $lodging       = $event->lodgingEnabled ? EventLodging::forEvent($event->id) : [];
        $foodById      = $this->menuOptionMap($foodOptions);
        $beverageById  = $this->menuOptionMap($bevOptions);
        $lodgingById   = $this->lodgingOptionMap($lodging);

        $members = array_map(function (Invitee $member) use ($foodById, $beverageById, $lodgingById): array {
            $isAttending = $member->rsvpStatus === InvitationGroup::RSVP_ATTENDING;
            $fullName    = trim($member->firstName . ' ' . $member->lastName);

            return [
                'invitee_id'            => $member->id,
                'first_name'            => $member->firstName,
                'last_name'             => $member->lastName,
                'full_name'             => $fullName !== '' ? $fullName : $member->email,
                'email'                 => $member->email,
                'rsvp_status'           => $member->rsvpStatus ?: InvitationGroup::RSVP_PENDING,
                'is_attending'          => $isAttending,
                'registered_at'         => $member->registeredAt,
                'food'                  => $isAttending ? $this->menuSelectionPayload($member->foodOptionId, $foodById) : null,
                'food_confirmed_at'     => $member->foodConfirmedAt,
                'beverage'              => $isAttending ? $this->menuSelectionPayload($member->beverageOptionId, $beverageById) : null,
                'beverage_confirmed_at' => $member->beverageConfirmedAt,
                'dietary_notes'         => $isAttending ? $member->dietaryNotes : '',
                'lodging'               => $isAttending ? $this->lodgingSelectionPayload($member, $lodgingById) : null,
                'lodging_confirmed_at'  => $member->lodgingConfirmedAt,
            ] + $this->inviteeImagePayload($member);
        }, $result->members);

        $acceptedMembers = array_values(array_filter(
            $members,
            static fn(array $member): bool => (bool) $member['is_attending']
        ));
        $groupLodging = null;
        foreach ($acceptedMembers as $member) {
            if ($member['lodging'] !== null) {
                $groupLodging = $member['lodging'];
                break;
            }
        }

        return [
            'event_id'           => $event->id,
            'group_id'           => $result->group->id,
            'edit_rsvp_url'      => $this->buildRsvpEditUrl($event, $code),
            'rsvp_notes'         => $result->group->rsvpNotes,
            'rsvp_notes_updated_at' => $result->group->rsvpNotesUpdatedAt,
            'lodging_booked'     => $result->group->lodgingBooked,
            'lodging_booked_at'  => $result->group->lodgingBookedAt,
            'lodging_notes'      => $result->group->lodgingNotes,
            'requires_lodging'   => $result->requiresLodging,
            'requires_food'      => $result->requiresFood,
            'requires_beverage'  => $result->requiresBeverage,
            'accepted_count'     => count($acceptedMembers),
            'accepted_members'   => $acceptedMembers,
            'group_lodging'      => $groupLodging,
            'members'            => $members,
        ];
    }

    /**
     * Returns a lookup map for menu option payloads keyed by menu item ID.
     *
     * @param MenuItem[] $items
     * @return array<int,array<string,mixed>>
     */
    private function menuOptionMap(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $map[$item->id] = $this->menuItemPayload($item);
        }

        return $map;
    }

    /**
     * Returns a public menu item payload.
     *
     * @param MenuItem $item
     * @return array<string,mixed>
     */
    private function menuItemPayload(MenuItem $item): array
    {
        return [
            'id'          => $item->id,
            'type'        => $item->type,
            'label'       => $item->label,
            'description' => $item->description,
            'sort_order'  => $item->sortOrder,
        ];
    }

    /**
     * Returns the selected menu option, falling back to the global item if the
     * event assignment was later removed.
     *
     * @param int|null $id
     * @param array<int,array<string,mixed>> $optionsById
     * @return array<string,mixed>|null
     */
    private function menuSelectionPayload(?int $id, array $optionsById): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        if (isset($optionsById[$id])) {
            return $optionsById[$id];
        }

        $item = MenuItem::find($id);

        if ($item === null) {
            return [
                'id'          => $id,
                'type'        => null,
                'label'       => 'Unavailable option',
                'description' => '',
                'sort_order'  => 0,
            ];
        }

        $payload                 = $this->menuItemPayload($item);
        $payload['is_available'] = false;

        return $payload;
    }

    /**
     * Returns a lookup map for lodging option payloads keyed by assignment ID.
     *
     * @param EventLodging[] $lodging
     * @return array<int,array<string,mixed>>
     */
    private function lodgingOptionMap(array $lodging): array
    {
        $map = [];

        foreach ($lodging as $option) {
            $map[$option->id] = $this->lodgingOptionPayload($option);
        }

        return $map;
    }

    /**
     * Returns a public lodging option payload.
     *
     * @param EventLodging $option
     * @return array<string,mixed>
     */
    private function lodgingOptionPayload(EventLodging $option): array
    {
        return [
            'type'        => 'lodging',
            'id'          => $option->id,
            'name'        => $option->name,
            'address'     => $option->formattedAddress(),
            'booking_url' => $option->bookingUrl,
            'is_other'    => $option->isOther,
        ];
    }

    /**
     * Returns the selected lodging option for an invitee.
     *
     * @param Invitee $member
     * @param array<int,array<string,mixed>> $lodgingById
     * @return array<string,mixed>|null
     */
    private function lodgingSelectionPayload(Invitee $member, array $lodgingById): ?array
    {
        if ($member->lodgingUndisclosed) {
            return [
                'type'  => 'undisclosed',
                'label' => 'Prefer not to disclose',
            ];
        }

        if ($member->lodgingIsOther) {
            return [
                'type'  => 'other',
                'label' => 'Other',
            ];
        }

        if ($member->lodgingId === null || $member->lodgingId <= 0) {
            return null;
        }

        if (isset($lodgingById[$member->lodgingId])) {
            return $lodgingById[$member->lodgingId];
        }

        return [
            'type'         => 'lodging',
            'id'           => $member->lodgingId,
            'name'         => 'Unavailable lodging option',
            'address'      => '',
            'booking_url'  => '',
            'is_other'     => false,
            'is_available' => false,
        ];
    }

    /**
     * Resolves the most appropriate connection group ID for an invitation group.
     *
     * When the primary invitee belongs to a single connection group that group is
     * returned immediately. When they belong to multiple, the method picks the one
     * whose member set is a superset of the invitation group members — i.e. the CG
     * the admin used when building the invitation. Falls back to alphabetically
     * first if no perfect superset match exists.
     *
     * @param InvitationGroup $group
     * @return int|null Null when the invitee belongs to no connection groups.
     */
    private function resolveConnectionGroupId(InvitationGroup $group): ?int
    {
        $connectionGroups = ConnectionGroup::forInvitee($group->primaryInviteeId);

        if (empty($connectionGroups)) {
            return null;
        }

        if (count($connectionGroups) === 1) {
            return $connectionGroups[0]->id;
        }

        $invitationMemberIds = array_map(
            static fn(Invitee $m): int => $m->id,
            $group->getMembers()
        );

        foreach ($connectionGroups as $cg) {
            $cgMemberIds = array_map(
                static fn(Invitee $m): int => $m->id,
                $cg->getMembers()
            );
            if (empty(array_diff($invitationMemberIds, $cgMemberIds))) {
                return $cg->id;
            }
        }

        return $connectionGroups[0]->id;
    }

    /**
     * Clears selection columns when a member is no longer attending.
     *
     * @return array<string,mixed>
     */
    private function clearedSelectionExtras(): array
    {
        return [
            'food_option_id'        => null,
            'beverage_option_id'    => null,
            'dietary_notes'         => '',
            'food_confirmed_at'     => null,
            'beverage_confirmed_at' => null,
            'lodging_id'            => null,
            'lodging_is_other'      => false,
            'lodging_undisclosed'   => false,
            'lodging_confirmed_at'  => null,
        ];
    }
}
