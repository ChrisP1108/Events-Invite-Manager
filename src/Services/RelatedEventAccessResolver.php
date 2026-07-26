<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\QrCode;

/**
 * Centralizes cross-event authorization for the RSVP API so a confirmation
 * code can act on its own event or on one of that event's direct children,
 * without duplicating the "is this really a child, and does it really have a
 * materialized group" checks in every controller.
 *
 * This is purely a read-time authorization check — it never creates or
 * modifies any data. If a child's inherited group is missing (which should
 * only happen if reconciliation was never run after some earlier
 * inconsistency), it reports REASON_SYNC_INCOMPLETE rather than silently
 * materializing it; self-healing only ever happens via an explicit
 * admin-triggered reconcile action (see ChildEventInvitationSync::reconcile()).
 */
final class RelatedEventAccessResolver
{
    /**
     * Resolves a confirmation code to the Event/InvitationGroup pair it is
     * authorized to act on.
     *
     * @param string   $confirmationCode
     * @param int|null $targetEventId When null (or equal to the code's own event), resolves to the code's own event.
     * @return RelatedEventAccessResult
     */
    public function resolve(string $confirmationCode, ?int $targetEventId): RelatedEventAccessResult
    {
        $code = trim($confirmationCode);

        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return new RelatedEventAccessResult(false, null, null, $code, RelatedEventAccessResult::REASON_INVALID_CODE);
        }

        $qrEvent = Event::find($qrCode->eventId);
        $qrGroup = InvitationGroup::find($qrCode->groupId);

        if ($qrEvent === null || $qrGroup === null) {
            return new RelatedEventAccessResult(false, null, null, $code, RelatedEventAccessResult::REASON_INVALID_CODE);
        }

        // Defense in depth: the group a QR code points to must actually belong
        // to the same event the QR code points to. This should always hold by
        // construction (QrCodeService creates both together), but a mismatch
        // here would otherwise be trusted blindly.
        if ($qrGroup->eventId !== $qrEvent->id) {
            return new RelatedEventAccessResult(false, null, null, $code, RelatedEventAccessResult::REASON_INVALID_CODE);
        }

        if ($targetEventId === null || $targetEventId === $qrEvent->id) {
            return new RelatedEventAccessResult(true, $qrEvent, $qrGroup, $code);
        }

        $targetEvent = Event::find($targetEventId);
        if ($targetEvent === null) {
            return new RelatedEventAccessResult(false, null, null, $code, RelatedEventAccessResult::REASON_EVENT_NOT_FOUND);
        }

        // Never authorize a child merely because the invitee happens to exist
        // in some other group — the target must be a direct child of the
        // code's own event.
        if ($targetEvent->parentEventId !== $qrEvent->id) {
            return new RelatedEventAccessResult(false, null, null, $code, RelatedEventAccessResult::REASON_NOT_A_CHILD);
        }

        $childGroup = InvitationGroup::findByInheritance($targetEvent->id, $qrGroup->id);
        if ($childGroup === null) {
            return new RelatedEventAccessResult(false, null, null, $code, RelatedEventAccessResult::REASON_SYNC_INCOMPLETE);
        }

        return new RelatedEventAccessResult(true, $targetEvent, $childGroup, $code);
    }
}
