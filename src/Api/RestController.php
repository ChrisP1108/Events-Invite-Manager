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
use EventsInviteManager\Models\Newsletter;
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

        if ($result->nextAction !== RsvpFlowResult::ACTION_NEWSLETTER_REDIRECT || $result->event === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before viewing newsletters.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        $newsletterId = (int) ($request->get_param('newsletter_id') ?? 0);

        if ($newsletterId > 0) {
            $newsletter = Newsletter::findPublishedForEvent($result->event->id, $newsletterId);

            if ($newsletter === null) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Newsletter not found.',
                ], 404);
            }

            return new WP_REST_Response([
                'success'       => true,
                'event_id'      => $result->event->id,
                'edit_rsvp_url' => $this->buildRsvpEditUrl($result->event, $code),
                'rsvp_summary'  => $this->rsvpSummaryPayload($result, $code),
                'newsletter'    => $this->newsletterDetailPayload($newsletter),
            ], 200);
        }

        $newsletters = Newsletter::publishedForEvent($result->event->id);

        return new WP_REST_Response([
            'success'       => true,
            'event_id'      => $result->event->id,
            'edit_rsvp_url' => $this->buildRsvpEditUrl($result->event, $code),
            'rsvp_summary'  => $this->rsvpSummaryPayload($result, $code),
            'count'         => count($newsletters),
            'newsletters'   => array_map(fn(Newsletter $nl): array => $this->newsletterSummaryPayload($nl), $newsletters),
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
        $currentFlow   = $this->resolver->resolve($code);
        $event         = $currentFlow->event ?? Event::find($group->eventId);

        if ($event === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event not found.'],
                404
            );
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
            $processedCount = 0;

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
                } elseif ($rsvpStatus === InvitationGroup::RSVP_ATTENDING && $groupLodgingSelection['provided']) {
                    $extras = array_merge($extras, $groupLodgingSelection['extras']);
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

            if ($groupLodgingSelection['provided']) {
                $freshGroup = InvitationGroup::find($group->id);
                foreach (($freshGroup?->getMembers() ?? []) as $member) {
                    if ($member->rsvpStatus === InvitationGroup::RSVP_ATTENDING) {
                        InvitationGroup::updateMemberRsvp($group->id, $member->id, $member->rsvpStatus, $groupLodgingSelection['extras']);
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

                return $this->flowResponse(
                    $this->resolver->resolve($code),
                    [
                        'already_registered' => true,
                        'message'            => 'You are already registered for this event.',
                    ]
                );
            }

            InvitationGroup::markAllMembersAttending($group->id);

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
            'newsletter_url'    => $result->newsletterUrl,
            'event'             => [
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
            'group_members' => array_map(static fn(Invitee $member): array => [
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
            ], $members),
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
     * @return array<string,mixed>
     */
    private function newsletterSummaryPayload(Newsletter $newsletter): array
    {
        $plainContent = trim(wp_strip_all_tags($newsletter->content));

        return [
            'id'           => $newsletter->id,
            'title'        => $newsletter->title,
            'excerpt'      => wp_trim_words($plainContent, 40, '...'),
            'publish_date' => $newsletter->publishDate,
        ];
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
            ];
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
