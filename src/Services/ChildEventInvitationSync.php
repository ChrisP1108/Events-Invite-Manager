<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;

/**
 * Orchestrates parent-event invitation mutations that must stay in sync with
 * every linked child event.
 *
 * The actual inheritance mechanics (creating/removing/reordering materialized
 * child group rows) live on InvitationGroup itself, so they can never be
 * skipped by a caller that forgets to invoke a "sync" step. This service's
 * job is narrower: wrap a parent mutation plus its child-side effects in one
 * transaction whenever the event actually has children (so a partial failure
 * never leaves the parent updated with only some children synced), and
 * provide the handful of operations that don't map onto a single existing
 * InvitationGroup method — linking, unlinking, and manual reconciliation.
 *
 * Every mutation method returns array{success: bool, error?: string, ...} so
 * a blocked or failed operation is always reported, never silently swallowed.
 */
final class ChildEventInvitationSync
{
    /**
     * @param int   $eventId
     * @param int   $primaryInviteeId
     * @param int[] $additionalMemberIds
     * @param bool  $withinTransaction Set true when the caller already has an open transaction.
     * @return array{success: bool, group: ?InvitationGroup}
     */
    public static function createGroupWithSync(
        int $eventId,
        int $primaryInviteeId,
        array $additionalMemberIds = [],
        bool $withinTransaction = false
    ): array {
        return self::runMutation($eventId, $withinTransaction, static function () use ($eventId, $primaryInviteeId, $additionalMemberIds) {
            $group = InvitationGroup::create($eventId, $primaryInviteeId, $additionalMemberIds);
            return ['success' => $group !== null, 'group' => $group];
        });
    }

    /**
     * @param bool $withinTransaction Set true when the caller already has an open transaction.
     * @return array{success: bool}
     */
    public static function addMemberWithSync(int $groupId, int $inviteeId, int $eventId, bool $withinTransaction = false): array
    {
        return self::runMutation($eventId, $withinTransaction, static function () use ($groupId, $inviteeId, $eventId) {
            return ['success' => InvitationGroup::addMemberToGroup($groupId, $inviteeId, $eventId)];
        });
    }

    /**
     * Blocked (nothing changed) if the invitee has left any non-default data on
     * a child event — see InvitationGroup::hasAnyMeaningfulInheritedDataForParentGroup().
     *
     * @return array{success: bool, error?: string}
     */
    public static function removeMemberWithSync(int $eventId, int $inviteeId): array
    {
        return self::runMutation($eventId, false, static function () use ($eventId, $inviteeId) {
            $result = InvitationGroup::removeMemberFromEvent($inviteeId, $eventId);
            return ['success' => $result['ok'], 'error' => $result['reason'] ?? null];
        });
    }

    /**
     * @return array{success: bool}
     */
    public static function setPrimaryWithSync(int $groupId, int $eventId, int $newPrimaryInviteeId): array
    {
        return self::runMutation($eventId, false, static function () use ($groupId, $newPrimaryInviteeId) {
            return ['success' => InvitationGroup::setPrimaryMember($groupId, $newPrimaryInviteeId)];
        });
    }

    /**
     * @return array{success: bool}
     */
    public static function updateMemberSortOrderWithSync(int $groupId, int $eventId, int $inviteeId, int $sortOrder): array
    {
        return self::runMutation($eventId, false, static function () use ($groupId, $inviteeId, $sortOrder) {
            return ['success' => InvitationGroup::updateMemberSortOrder($groupId, $inviteeId, $sortOrder)];
        });
    }

    /**
     * Blocked (nothing changed) if any member has left non-default data on a
     * child event — see InvitationGroup::hasAnyMeaningfulInheritedDataForParentGroup().
     *
     * @return array{success: bool, error?: string}
     */
    public static function deleteGroupWithSync(int $groupId, int $eventId): array
    {
        return self::runMutation($eventId, false, static function () use ($groupId, $eventId) {
            $result = InvitationGroup::deleteGroup($groupId, $eventId);
            return ['success' => $result['ok'], 'error' => $result['reason'] ?? null];
        });
    }

    /**
     * Links an existing standalone event as a child of a parent, then
     * backfills a materialized inherited group for every one of the parent's
     * existing invitation groups.
     *
     * @return array{success: bool, error?: string, synced?: int}
     */
    public static function linkChildEvent(int $childEventId, int $parentEventId): array
    {
        if ($childEventId === $parentEventId) {
            return ['success' => false, 'error' => 'self_link'];
        }

        // Enforced here (not just in the admin UI, which only ever offers this
        // action from a non-child event) so a crafted request can't create a
        // grandchild — only one hierarchy level is allowed.
        $parentEvent = Event::find($parentEventId);
        if ($parentEvent === null) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($parentEvent->parentEventId !== null) {
            return ['success' => false, 'error' => 'parent_is_a_child'];
        }

        $canLink = Event::canLinkAsChild($childEventId);
        if (!$canLink['ok']) {
            return ['success' => false, 'error' => $canLink['reason']];
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        $nextOrder = count(Event::childrenOf($parentEventId)) + 1;
        $updated   = Event::update($childEventId, [
            'parent_event_id'  => $parentEventId,
            'child_sort_order' => $nextOrder,
        ]);

        if (!$updated) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'update_failed'];
        }

        $backfill = self::backfillChild($childEventId, $parentEventId);

        if (!$backfill['ok']) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'sync_failed'];
        }

        $wpdb->query('COMMIT');

        return ['success' => true, 'synced' => $backfill['changed']];
    }

    /**
     * Unlinks a child event from its parent. Only permitted while every
     * inherited group on the child is still in its default pending/empty
     * state — otherwise this would silently discard RSVP data.
     *
     * @return array{success: bool, error?: string}
     */
    public static function unlinkChildEvent(int $childEventId): array
    {
        $canUnlink = Event::canUnlink($childEventId);
        if (!$canUnlink['ok']) {
            return ['success' => false, 'error' => $canUnlink['reason']];
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        if (!InvitationGroup::deleteAllInheritedGroupsForEvent($childEventId)) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'db_error'];
        }

        $updated = Event::update($childEventId, [
            'parent_event_id'  => null,
            'child_sort_order' => 0,
        ]);

        if (!$updated) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'update_failed'];
        }

        $wpdb->query('COMMIT');

        return ['success' => true];
    }

    /**
     * Explicit, admin-triggered self-heal: for every child of the given parent
     * event, diffs its materialized inherited group against the parent's
     * current membership and repairs any drift — missing/stale members,
     * a mismatched primary invitee, or sort_order drift — creating the group
     * from scratch if it doesn't exist yet. Each child is reconciled in its
     * own transaction, so one child's failure doesn't block the others. This
     * is the only sanctioned place reconciliation may happen outside of a
     * live mutation — it is never invoked from a read/GET request.
     *
     * @return array{success: bool, synced: int, failed: int}
     */
    public static function reconcile(int $parentEventId): array
    {
        global $wpdb;

        $totalChanged = 0;
        $failedCount  = 0;

        foreach (Event::childrenOf($parentEventId) as $childEvent) {
            $wpdb->query('START TRANSACTION');

            $result = self::backfillChild($childEvent->id, $parentEventId);

            if ($result['ok']) {
                $wpdb->query('COMMIT');
                $totalChanged += $result['changed'];
            } else {
                $wpdb->query('ROLLBACK');
                $failedCount++;
            }
        }

        return ['success' => $failedCount === 0, 'synced' => $totalChanged, 'failed' => $failedCount];
    }

    /**
     * Reconciles (creating or repairing) every one of the parent's current
     * invitation groups against the given child event.
     *
     * @return array{ok: bool, changed: int}
     */
    private static function backfillChild(int $childEventId, int $parentEventId): array
    {
        $changed = 0;
        foreach (InvitationGroup::forEvent($parentEventId) as $parentGroup) {
            $result = InvitationGroup::reconcileInheritedGroup($childEventId, $parentGroup);
            if (!$result['ok']) {
                return ['ok' => false, 'changed' => $changed];
            }
            $changed += $result['changed'];
        }

        return ['ok' => true, 'changed' => $changed];
    }

    /**
     * Runs $fn, wrapping it in its own transaction only when the event has
     * children and the caller doesn't already have one open. When the event
     * has no children, delegates directly with no transaction overhead —
     * preserving today's behavior/performance for the common case.
     *
     * @param callable(): array{success: bool, ...} $fn
     * @return array{success: bool, ...}
     */
    private static function runMutation(int $eventId, bool $withinTransaction, callable $fn): array
    {
        if ($withinTransaction || !Event::hasChildren($eventId)) {
            return $fn();
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $wpdb->query(($result['success'] ?? false) ? 'COMMIT' : 'ROLLBACK');

        return $result;
    }
}
