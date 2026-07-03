<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\QrCode;

/**
 * Resolves the current staged invitation flow state for a given confirmation code.
 *
 * Given a 16-character QR confirmation code the resolver performs a series of
 * ordered checks and returns an RsvpFlowResult that tells the caller exactly
 * what the invitee should do next:
 *
 *   1. invalid_code        — Code not found, or related group / event missing.
 *   2. rsvp_required       — One or more members have not yet confirmed or declined.
 *   3. menu_required       — All confirmed, but required food / beverage not selected.
 *   4. lodging_required    — Menu complete, but lodging selection not yet confirmed.
 *   5. dashboard_redirect  — All steps done; redirect to the invitee dashboard.
 *   6. declined            — Every member declined; nothing further to do.
 *
 * This service intentionally has no WordPress admin dependencies and contains
 * no output logic — it is purely a state calculator. It can therefore be
 * exercised directly in unit tests without a request context.
 */
final class RsvpFlowResolver
{
    /**
     * Resolves the flow state for the provided confirmation code.
     *
     * The method is intentionally free of side-effects: it reads data and
     * returns a result without writing anything to the database.
     *
     * @param string $code Raw 16-character confirmation code from the QR URL.
     * @return RsvpFlowResult
     */
    public function resolve(string $code): RsvpFlowResult
    {
        $code = trim($code);

        // ── Step 1: look up the QR record ────────────────────────────────────
        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return $this->error(RsvpFlowResult::ACTION_INVALID_CODE, 'Invalid or unrecognised confirmation code.');
        }

        // ── Step 2: resolve group and event ──────────────────────────────────
        $group = InvitationGroup::find($qrCode->groupId);
        $event = Event::find($qrCode->eventId);

        if ($group === null || $event === null) {
            return $this->error(RsvpFlowResult::ACTION_INVALID_CODE, 'Event or invitation group not found.');
        }

        // ── Step 3: load members ──────────────────────────────────────────────
        $members = $group->getMembers();

        // ── Step 4: RSVP state check ──────────────────────────────────────────
        $pendingMembers   = array_values(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_PENDING));
        $attendingMembers = array_values(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_ATTENDING));

        $requiresLodging             = $this->resolveLodgingRequirement($event);
        [$requiresFood, $requiresBeverage] = $this->resolveMenuRequirements($event);
        $rsvpStartPending            = $event->isRsvpStartPending();
        $rsvpBeforeStartUrl          = $event->rsvpBeforeStartUrl($code);
        $rsvpAfterDeadlineUrl        = $event->rsvpAfterDeadlineUrl($code);
        $deadlinePassed              = $event->isRsvpDeadlinePassed();

        if (!empty($pendingMembers)) {
            return new RsvpFlowResult(
                success:            true,
                nextAction:         RsvpFlowResult::ACTION_RSVP_REQUIRED,
                event:              $event,
                group:              $group,
                members:            $members,
                requiresLodging:    $requiresLodging,
                requiresFood:       $requiresFood,
                requiresBeverage:   $requiresBeverage,
                dashboardUrl:       null,
                message:            null,
                rsvpStartPending:   $rsvpStartPending,
                rsvpBeforeStartUrl:   $rsvpBeforeStartUrl,
                rsvpDeadlinePassed:   $deadlinePassed,
                rsvpAfterDeadlineUrl: $rsvpAfterDeadlineUrl,
            );
        }

        if (empty($attendingMembers)) {
            // Every member declined.
            return new RsvpFlowResult(
                success:            true,
                nextAction:         RsvpFlowResult::ACTION_DECLINED,
                event:              $event,
                group:              $group,
                members:            $members,
                requiresLodging:    false,
                requiresFood:       false,
                requiresBeverage:   false,
                dashboardUrl:       $event->dashboardUrl($code),
                message:            null,
                rsvpStartPending:   $rsvpStartPending,
                rsvpBeforeStartUrl:   $rsvpBeforeStartUrl,
                rsvpDeadlinePassed:   $deadlinePassed,
                rsvpAfterDeadlineUrl: $rsvpAfterDeadlineUrl,
            );
        }

        // ── Step 5: menu completion check ────────────────────────────────────
        // Only attending members need to have confirmed their menu selections.
        if ($requiresFood || $requiresBeverage) {
            foreach ($attendingMembers as $member) {
                if ($requiresFood && $member->foodConfirmedAt === null) {
                    return new RsvpFlowResult(
                        success:            true,
                        nextAction:         RsvpFlowResult::ACTION_MENU_REQUIRED,
                        event:              $event,
                        group:              $group,
                        members:            $members,
                        requiresLodging:    $requiresLodging,
                        requiresFood:       $requiresFood,
                        requiresBeverage:   $requiresBeverage,
                        dashboardUrl:       $event->dashboardUrl($code),
                        message:            null,
                        rsvpStartPending:   $rsvpStartPending,
                        rsvpBeforeStartUrl: $rsvpBeforeStartUrl,
                        rsvpDeadlinePassed: $deadlinePassed,
                    );
                }
                if ($requiresBeverage && $member->beverageConfirmedAt === null) {
                    return new RsvpFlowResult(
                        success:            true,
                        nextAction:         RsvpFlowResult::ACTION_MENU_REQUIRED,
                        event:              $event,
                        group:              $group,
                        members:            $members,
                        requiresLodging:    $requiresLodging,
                        requiresFood:       $requiresFood,
                        requiresBeverage:   $requiresBeverage,
                        dashboardUrl:       $event->dashboardUrl($code),
                        message:            null,
                        rsvpStartPending:   $rsvpStartPending,
                        rsvpBeforeStartUrl: $rsvpBeforeStartUrl,
                        rsvpDeadlinePassed: $deadlinePassed,
                    );
                }
            }
        }

        // ── Step 6: lodging completion check ─────────────────────────────────
        // Lodging is a single group-level selection, propagated to all attending
        // members when saved. All attending members must have lodging_confirmed_at
        // set before the step is considered complete.
        if ($requiresLodging) {
            $lodgingConfirmed = !empty($attendingMembers) && array_reduce(
                $attendingMembers,
                static fn(bool $carry, Invitee $m): bool => $carry && $m->lodgingConfirmedAt !== null,
                true
            );
            if (!$lodgingConfirmed) {
                return new RsvpFlowResult(
                    success:            true,
                    nextAction:         RsvpFlowResult::ACTION_LODGING_REQUIRED,
                    event:              $event,
                    group:              $group,
                    members:            $members,
                    requiresLodging:    true,
                    requiresFood:       $requiresFood,
                    requiresBeverage:   $requiresBeverage,
                    dashboardUrl:       $event->dashboardUrl($code),
                    message:            null,
                    rsvpStartPending:   $rsvpStartPending,
                    rsvpBeforeStartUrl: $rsvpBeforeStartUrl,
                    rsvpDeadlinePassed: $deadlinePassed,
                );
            }
        }

        // ── Step 7: everything complete ───────────────────────────────────────
        return new RsvpFlowResult(
            success:            true,
            nextAction:         RsvpFlowResult::ACTION_DASHBOARD_REDIRECT,
            event:              $event,
            group:              $group,
            members:            $members,
            requiresLodging:    $requiresLodging,
            requiresFood:       $requiresFood,
            requiresBeverage:   $requiresBeverage,
            dashboardUrl:       $event->dashboardUrl($code),
            message:            null,
            rsvpStartPending:   $rsvpStartPending,
            rsvpBeforeStartUrl: $rsvpBeforeStartUrl,
            rsvpDeadlinePassed: $deadlinePassed,
        );
    }

    // ── Testable public helpers ───────────────────────────────────────────────

    /**
     * Determines whether lodging selection is required for an event.
     *
     * "Required" means lodging is enabled AND at least one lodging option is
     * assigned to the event. If no options exist the step is skipped.
     *
     * @param Event $event
     * @return bool
     */
    public function resolveLodgingRequirement(Event $event): bool
    {
        return $event->lodgingEnabled
            && !empty(EventLodging::forEvent($event->id));
    }

    /**
     * Determines whether food and/or beverage selection is required for an event.
     *
     * "Required" means the event flag is enabled AND at least one active menu item
     * of that type is assigned to the event. If no items are assigned the option
     * cannot be fulfilled, so it is treated as not required.
     *
     * Returns a two-element array: [ bool $requiresFood, bool $requiresBeverage ].
     *
     * @param Event $event
     * @return array{0: bool, 1: bool}
     */
    public function resolveMenuRequirements(Event $event): array
    {
        $requiresFood = $event->foodOptionsEnabled
            && !empty(MenuItem::forEventByType($event->id, MenuItem::TYPE_FOOD));

        $requiresBeverage = $event->beverageOptionsEnabled
            && !empty(MenuItem::forEventByType($event->id, MenuItem::TYPE_BEVERAGE));

        return [$requiresFood, $requiresBeverage];
    }

    /**
     * Returns true if all attending members have completed their required menu selections.
     *
     * Useful for callers that need to check completion without running the full resolver.
     *
     * @param Invitee[] $attendingMembers Members with rsvp_status === 'attending'.
     * @param bool      $requiresFood     Whether food selection is required.
     * @param bool      $requiresBeverage Whether beverage selection is required.
     * @return bool
     */
    public function isMenuComplete(array $attendingMembers, bool $requiresFood, bool $requiresBeverage): bool
    {
        if (!$requiresFood && !$requiresBeverage) {
            return true;
        }

        foreach ($attendingMembers as $member) {
            if ($requiresFood && $member->foodConfirmedAt === null) {
                return false;
            }
            if ($requiresBeverage && $member->beverageConfirmedAt === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves a confirmation code into a flat array suitable for direct use by
     * server-rendered templates (e.g. WordPress theme page templates).
     *
     * Unlike resolve(), which returns a structured RsvpFlowResult of typed value
     * objects for programmatic consumers, this flattens the entire result --
     * event, group, and per-member RSVP/menu/lodging state, plus QR code URLs --
     * into primitive, snake_case-keyed arrays.
     *
     * The caller is responsible for extracting and sanitizing the raw code from
     * the request (e.g. $_GET); this method never touches superglobals.
     *
     * @param string $confirmationCode Already-sanitized confirmation code.
     * @return array{
     *     confirmation_code: string,
     *     next_action: string,
     *     requires_lodging: bool,
     *     requires_food: bool,
     *     requires_beverage: bool,
     *     dashboard_url: string|null,
     *     rsvp_start_pending: bool,
     *     rsvp_before_start_url: string|null,
     *     rsvp_deadline_passed: bool,
     *     rsvp_after_deadline_url: string|null,
     *     qr_code: array{svg_url:string,png_url:string}|null,
     *     event: array{id:int,name:string,description:string,start_datetime:string|null,end_datetime:string|null,timezone:string,lodging_enabled:bool,food_options_enabled:bool,beverage_options_enabled:bool}|null,
     *     group: array{id:int,event_id:int,primary_invitee_id:int,rsvp_notes:string,lodging_booked:bool}|null,
     *     primary_member_id: int|null,
     *     members: array<int,array{id:int,first_name:string,last_name:string,email:string,rsvp_status:string,is_registered:bool,food_option_id:int|null,beverage_option_id:int|null,dietary_notes:string,lodging_id:int|null,is_primary:bool,sort_order:int}>
     * }|false
     */
    public function resolveConfirmationCodeToArray(string $confirmationCode): array|false
    {
        $confirmationCode = trim($confirmationCode);

        if ($confirmationCode === '') {
            return false;
        }

        $flowResult = $this->resolve($confirmationCode);

        if (!$flowResult->success) {
            return false;
        }

        $qrCode = QrCode::findByCode($confirmationCode);

        return [
            'confirmation_code'       => $confirmationCode,
            'next_action'             => $flowResult->nextAction,
            'requires_lodging'        => $flowResult->requiresLodging,
            'requires_food'           => $flowResult->requiresFood,
            'requires_beverage'       => $flowResult->requiresBeverage,
            'dashboard_url'           => $flowResult->dashboardUrl,
            'rsvp_start_pending'      => $flowResult->rsvpStartPending,
            'rsvp_before_start_url'   => $flowResult->rsvpBeforeStartUrl,
            'rsvp_deadline_passed'    => $flowResult->rsvpDeadlinePassed,
            'rsvp_after_deadline_url' => $flowResult->rsvpAfterDeadlineUrl,
            'qr_code' => $qrCode ? [
                'svg_url' => wp_make_link_relative($qrCode->svgUrl()),
                'png_url' => wp_make_link_relative($qrCode->pngUrl()),
            ] : null,
            'event' => $flowResult->event ? [
                'id'                       => $flowResult->event->id,
                'name'                     => $flowResult->event->name,
                'description'              => $flowResult->event->description,
                'start_datetime'           => $flowResult->event->startDatetime,
                'end_datetime'             => $flowResult->event->endDatetime,
                'timezone'                 => $flowResult->event->timezone,
                'lodging_enabled'          => $flowResult->event->lodgingEnabled,
                'food_options_enabled'     => $flowResult->event->foodOptionsEnabled,
                'beverage_options_enabled' => $flowResult->event->beverageOptionsEnabled,
            ] : null,
            'group' => $flowResult->group ? [
                'id'                 => $flowResult->group->id,
                'event_id'           => $flowResult->group->eventId,
                'primary_invitee_id' => $flowResult->group->primaryInviteeId,
                'rsvp_notes'         => $flowResult->group->rsvpNotes,
                'lodging_booked'     => $flowResult->group->lodgingBooked,
            ] : null,
            'members' => array_map(static fn(Invitee $member) => [
                'id'                 => $member->id,
                'first_name'         => $member->firstName,
                'last_name'          => $member->lastName,
                'email'              => $member->email,
                'rsvp_status'        => $member->rsvpStatus,
                'is_registered'      => $member->isRegistered,
                'food_option_id'     => $member->foodOptionId,
                'beverage_option_id' => $member->beverageOptionId,
                'dietary_notes'      => $member->dietaryNotes,
                'lodging_id'         => $member->lodgingId,
                'is_primary'         => $flowResult->group !== null && $member->id === $flowResult->group->primaryInviteeId,
                'sort_order'         => $member->sortOrder,
            ], $flowResult->members),
            'primary_member_id' => $flowResult->group?->primaryInviteeId,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Builds an error RsvpFlowResult with no event/group/member data.
     *
     * @param string $action  One of the ACTION_* error constants.
     * @param string $message Human-readable description of the error.
     * @return RsvpFlowResult
     */
    private function error(string $action, string $message): RsvpFlowResult
    {
        return new RsvpFlowResult(
            success:          false,
            nextAction:       $action,
            event:            null,
            group:            null,
            members:          [],
            requiresLodging:  false,
            requiresFood:     false,
            requiresBeverage: false,
            dashboardUrl:     null,
            message:          $message,
        );
    }
}
