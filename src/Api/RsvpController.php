<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Services\RsvpFlowResult;
use WP_REST_Request;
use WP_REST_Response;

class RsvpController extends AbstractApiController
{
    public function handleRsvp(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $result = $this->resolver->resolve($code);

        return $this->flowResponse($result);
    }

    /**
     * Handles POST /eim/v1/register.
     *
     * If `members` is provided, updates each listed member's rsvp_status individually.
     * food_confirmed_at and beverage_confirmed_at are set only when valid, required
     * menu-item IDs are submitted alongside the RSVP.
     *
     * If `members` is omitted, all pending members are marked attending (legacy path).
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

        $members          = $group->getMembers();
        $rawMemberList    = $request->get_param('members');
        $hasMemberList    = is_array($rawMemberList);
        $rawRsvpNotes     = $request->get_param('rsvp_notes');
        $hasRsvpNotes     = $rawRsvpNotes !== null;
        $rsvpNotes        = $hasRsvpNotes ? sanitize_textarea_field((string) $rawRsvpNotes) : '';
        $rawLodgingBooked = $request->get_param('lodging_booked');
        $hasLodgingBooked = $rawLodgingBooked !== null && $rawLodgingBooked !== '';
        $lodgingBooked    = $hasLodgingBooked ? $this->toBool($rawLodgingBooked) : null;
        $rawLodgingNotes  = $request->get_param('lodging_notes');
        $hasLodgingNotes  = $rawLodgingNotes !== null;
        $lodgingNotes     = $hasLodgingNotes ? sanitize_textarea_field((string) $rawLodgingNotes) : null;
        $currentFlow      = $this->resolver->resolve($code);
        $event            = $currentFlow->event ?? Event::find($group->eventId);

        if ($event === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Event not found.'],
                404
            );
        }

        $memberStatusById = [];
        foreach ($members as $m) {
            $memberStatusById[$m->id] = $m->rsvpStatus;
        }

        // Block RSVP status changes after the deadline.
        // Pending members: always blocked (can't RSVP at all after deadline).
        // Already-responded members: blocked from changing their RSVP status
        //   (e.g. attending → declined), but menu/lodging/notes updates still go through.
        if ($currentFlow->rsvpDeadlinePassed) {
            $pendingMemberIds = array_keys(array_filter(
                $memberStatusById,
                static fn(string $s) => $s === InvitationGroup::RSVP_PENDING
            ));

            if (!empty($pendingMemberIds)) {
                return new WP_REST_Response(
                    ['success' => false, 'message' => 'The RSVP deadline for this event has passed.', 'deadline_passed' => true],
                    422
                );
            }

            // Also block already-responded members from changing their RSVP status.
            if ($hasMemberList && is_array($rawMemberList)) {
                foreach ($rawMemberList as $entry) {
                    $inviteeId       = (int) ($entry['invitee_id'] ?? 0);
                    $currentStatus   = $memberStatusById[$inviteeId] ?? null;
                    $submittedStatus = $currentStatus !== null
                        ? $this->submittedRsvpStatus($entry, $currentStatus)
                        : '';

                    if ($currentStatus !== null
                        && $submittedStatus !== $currentStatus
                        && $currentStatus !== InvitationGroup::RSVP_PENDING) {
                        return new WP_REST_Response(
                            ['success' => false, 'message' => 'The RSVP deadline has passed; you cannot change your RSVP status.', 'deadline_passed' => true],
                            422
                        );
                    }
                }
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

                $submittedStatus = $this->submittedRsvpStatus(
                    $entry,
                    $memberStatusById[$inviteeId] ?? InvitationGroup::RSVP_PENDING
                );
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
                $inviteeId = (int) ($entry['invitee_id']  ?? 0);

                if ($inviteeId <= 0 || !in_array($inviteeId, $validMemberIds, true)) {
                    continue;
                }

                $rsvpStatus = $this->submittedRsvpStatus(
                    $entry,
                    $memberStatusById[$inviteeId] ?? InvitationGroup::RSVP_PENDING
                );

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

        return $this->flowResponse(
            $this->resolver->resolve($code),
            [
                'already_registered' => false,
                'message'            => 'You have successfully registered for the event!',
            ]
        );
    }

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
            'success'               => true,
            'next_action'           => $result->nextAction,
            'requires_lodging'      => $result->requiresLodging,
            'requires_food'         => $result->requiresFood,
            'requires_beverage'     => $result->requiresBeverage,
            'dashboard_url'         => $result->dashboardUrl,
            'rsvp_notes'            => $group->rsvpNotes,
            'rsvp_notes_updated_at' => $group->rsvpNotesUpdatedAt,
            'lodging_booked'        => $group->lodgingBooked,
            'lodging_booked_at'     => $group->lodgingBookedAt,
            'lodging_notes'         => $group->lodgingNotes,
            'event'                 => [
                'name'                 => $event->name,
                'description'          => $event->description,
                'date'                 => $event->formattedDateTimeRange(),
                'rsvp_deadline'        => $event->rsvpDeadline,
                'rsvp_deadline_passed' => $result->rsvpDeadlinePassed,
                'can_rsvp'             => !$result->rsvpDeadlinePassed,
                'venue'                => $venue ? [
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
                'phone'                 => $member->phone,
                'street_address'        => $member->streetAddress,
                'city'                  => $member->city,
                'state'                 => $member->state,
                'zip_code'              => $member->zipCode,
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
     * Returns the RSVP status a submitted member row should apply.
     *
     * Missing status keeps already-responded members unchanged, while pending
     * members still default to attending for checkbox-style first RSVP forms.
     *
     * @param array<string,mixed> $entry
     */
    private function submittedRsvpStatus(array $entry, string $currentStatus): string
    {
        if (array_key_exists('rsvp_status', $entry) && (string) $entry['rsvp_status'] !== '') {
            return (string) $entry['rsvp_status'];
        }

        return $currentStatus === InvitationGroup::RSVP_PENDING
            ? InvitationGroup::RSVP_ATTENDING
            : $currentStatus;
    }

    /** @return array<string,mixed> */
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

        $lodgingIsOther     = $this->toBool($source['lodging_is_other'] ?? false);
        $lodgingUndisclosed = $this->toBool($source['lodging_undisclosed'] ?? false);
        $lodgingId          = array_key_exists('lodging_id', $source) ? (int) $source['lodging_id'] : 0;
        $errors             = [];

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
     * @param array{provided: bool, extras: array<string,mixed>, errors: array<string,string>} $selection
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
     * @param array{provided: bool, extras: array<string,mixed>, errors: array<string,string>} $groupSelection
     * @param array<int,array<string,mixed>> $memberEntriesById
     * @param int[] $validLodgingIds
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

    /** @return array<string,mixed> */
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
