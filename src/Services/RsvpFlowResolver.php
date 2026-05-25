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

        if (!empty($pendingMembers)) {
            return new RsvpFlowResult(
                success:          true,
                nextAction:       RsvpFlowResult::ACTION_RSVP_REQUIRED,
                event:            $event,
                group:            $group,
                members:          $members,
                requiresLodging:  $requiresLodging,
                requiresFood:     $requiresFood,
                requiresBeverage: $requiresBeverage,
                dashboardUrl:     null,
                message:          null,
            );
        }

        if (empty($attendingMembers)) {
            // Every member declined.
            return new RsvpFlowResult(
                success:          true,
                nextAction:       RsvpFlowResult::ACTION_DECLINED,
                event:            $event,
                group:            $group,
                members:          $members,
                requiresLodging:  false,
                requiresFood:     false,
                requiresBeverage: false,
                dashboardUrl:     $event->dashboardUrl($code),
                message:          null,
            );
        }

        // ── Step 5: menu completion check ────────────────────────────────────
        // Only attending members need to have confirmed their menu selections.
        if ($requiresFood || $requiresBeverage) {
            foreach ($attendingMembers as $member) {
                if ($requiresFood && $member->foodConfirmedAt === null) {
                    return new RsvpFlowResult(
                        success:          true,
                        nextAction:       RsvpFlowResult::ACTION_MENU_REQUIRED,
                        event:            $event,
                        group:            $group,
                        members:          $members,
                        requiresLodging:  $requiresLodging,
                        requiresFood:     $requiresFood,
                        requiresBeverage: $requiresBeverage,
                        dashboardUrl:     $event->dashboardUrl($code),
                        message:          null,
                    );
                }
                if ($requiresBeverage && $member->beverageConfirmedAt === null) {
                    return new RsvpFlowResult(
                        success:          true,
                        nextAction:       RsvpFlowResult::ACTION_MENU_REQUIRED,
                        event:            $event,
                        group:            $group,
                        members:          $members,
                        requiresLodging:  $requiresLodging,
                        requiresFood:     $requiresFood,
                        requiresBeverage: $requiresBeverage,
                        dashboardUrl:     $event->dashboardUrl($code),
                        message:          null,
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
                    success:          true,
                    nextAction:       RsvpFlowResult::ACTION_LODGING_REQUIRED,
                    event:            $event,
                    group:            $group,
                    members:          $members,
                    requiresLodging:  true,
                    requiresFood:     $requiresFood,
                    requiresBeverage: $requiresBeverage,
                    dashboardUrl:     $event->dashboardUrl($code),
                    message:          null,
                );
            }
        }

        // ── Step 7: everything complete ───────────────────────────────────────
        return new RsvpFlowResult(
            success:          true,
            nextAction:       RsvpFlowResult::ACTION_DASHBOARD_REDIRECT,
            event:            $event,
            group:            $group,
            members:          $members,
            requiresLodging:  $requiresLodging,
            requiresFood:     $requiresFood,
            requiresBeverage: $requiresBeverage,
            dashboardUrl:     $event->dashboardUrl($code),
            message:          null,
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
