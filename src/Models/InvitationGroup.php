<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents one event invitation group.
 *
 * A group contains one or more invitees who share a single invite email sent to
 * the primary invitee's address. RSVP status is tracked per member with three
 * states: pending, attending, declined.
 */
final class InvitationGroup
{
    public const RSVP_PENDING  = 'pending';
    public const RSVP_ATTENDING = 'attending';
    public const RSVP_DECLINED  = 'declined';

    /** @var Invitee[]|null Lazy-loaded member list. */
    private ?array $members = null;

    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly int     $primaryInviteeId,
        public readonly ?string $inviteSentAt,
        public readonly string  $rsvpNotes,
        public readonly ?string $rsvpNotesUpdatedAt,
        public readonly bool    $lodgingBooked,
        public readonly ?string $lodgingBookedAt,
        public readonly string  $lodgingNotes,
        public readonly ?int    $inheritedFromGroupId,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // Static finders
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::invitationGroupsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns all groups for an event with members pre-loaded.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $table = DatabaseManager::invitationGroupsTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %d ORDER BY id ASC", $eventId)
        );

        if (empty($rows)) {
            return [];
        }

        $groups   = array_map(static fn(object $r) => self::fromRow($r), $rows);
        $groupIds = array_map(static fn(self $g) => $g->id, $groups);

        $membersByGroup = self::loadMembersForGroups($groupIds);
        foreach ($groups as $group) {
            $group->members = $membersByGroup[$group->id] ?? [];
        }

        return $groups;
    }

    /**
     * Returns all invitation groups where the given invitee is the primary invitee,
     * across all events. Members are pre-loaded on each group.
     *
     * Used by the invitee dashboard to show cross-event RSVP data.
     *
     * @param int $primaryInviteeId
     * @return self[]
     */
    public static function forPrimaryInvitee(int $primaryInviteeId): array
    {
        global $wpdb;

        $table = DatabaseManager::invitationGroupsTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE primary_invitee_id = %d ORDER BY id ASC",
                $primaryInviteeId
            )
        );

        if (empty($rows)) {
            return [];
        }

        $groups   = array_map(static fn(object $r) => self::fromRow($r), $rows);
        $groupIds = array_map(static fn(self $g) => $g->id, $groups);

        $membersByGroup = self::loadMembersForGroups($groupIds);
        foreach ($groups as $group) {
            $group->members = $membersByGroup[$group->id] ?? [];
        }

        return $groups;
    }

    /**
     * Finds the invitation group containing a specific invitee for a given event.
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return self|null
     */
    public static function findForMember(int $inviteeId, int $eventId): ?self
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT eig.* FROM {$groupsTable} eig
                 INNER JOIN {$membersTable} egm ON egm.group_id = eig.id
                 WHERE egm.invitee_id = %d AND eig.event_id = %d
                 LIMIT 1",
                $inviteeId,
                $eventId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds the materialized child group descended from a specific parent group
     * for a given child event, or null if it hasn't been materialized (yet).
     *
     * @param int $childEventId
     * @param int $parentGroupId
     * @return self|null
     */
    public static function findByInheritance(int $childEventId, int $parentGroupId): ?self
    {
        global $wpdb;

        $table = DatabaseManager::invitationGroupsTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND inherited_from_group_id = %d LIMIT 1",
                $childEventId,
                $parentGroupId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns true if any inherited invitation-group member on this (child) event
     * has left its default pending/empty state — i.e. has an RSVP status, a
     * food/beverage/lodging selection, dietary notes, or a seat assignment.
     *
     * Used to decide whether a child event may be safely unlinked from its parent.
     *
     * @param int $childEventId
     * @return bool
     */
    public static function hasAnyMeaningfulInheritedData(int $childEventId): bool
    {
        global $wpdb;

        $groupsTable = DatabaseManager::invitationGroupsTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$groupsTable} WHERE event_id = %d AND inherited_from_group_id IS NOT NULL",
                $childEventId
            )
        );

        if (empty($rows)) {
            return false;
        }

        $childGroupIds = array_map(static fn(object $r): int => (int) $r->id, $rows);

        if (self::anyGroupHasMeaningfulMemberData($childGroupIds)) {
            return true;
        }

        // Unlinking deletes every inherited group on this child event outright,
        // so group-level data (shared RSVP notes, lodging notes/booked) is
        // always at risk here, regardless of member count.
        foreach ($rows as $row) {
            if (self::groupHasMeaningfulGroupLevelData(self::fromRow($row))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if any child group descended from the given parent group has
     * a member (optionally limited to a single invitee) whose data has left the
     * default pending/empty state.
     *
     * Used to block a parent-side member removal or group deletion from silently
     * orphaning or discarding a child event's RSVP data.
     *
     * @param int      $parentGroupId
     * @param int|null $onlyForInviteeId Limit the check to this invitee's rows only.
     * @return bool
     */
    public static function hasAnyMeaningfulInheritedDataForParentGroup(int $parentGroupId, ?int $onlyForInviteeId = null): bool
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $childGroups = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$groupsTable} WHERE inherited_from_group_id = %d", $parentGroupId)
        );

        if (empty($childGroups)) {
            return false;
        }

        $childGroupIds = array_map(static fn(object $r): int => (int) $r->id, $childGroups);

        if (self::anyGroupHasMeaningfulMemberData($childGroupIds, $onlyForInviteeId)) {
            return true;
        }

        // Group-level data (shared RSVP notes, lodging notes/booked) is only at
        // risk when the child group itself is deleted — a whole-group deletion
        // (no invitee scope), or removing the last remaining member of a group.
        foreach ($childGroups as $row) {
            $childGroup = self::fromRow($row);

            if ($onlyForInviteeId !== null) {
                $remaining = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$membersTable} WHERE group_id = %d AND invitee_id != %d",
                    $childGroup->id,
                    $onlyForInviteeId
                ));
                if ($remaining > 0) {
                    continue;
                }
            }

            if (self::groupHasMeaningfulGroupLevelData($childGroup)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int[]    $groupIds
     * @param int|null $onlyForInviteeId
     * @return bool
     */
    private static function anyGroupHasMeaningfulMemberData(array $groupIds, ?int $onlyForInviteeId = null): bool
    {
        global $wpdb;

        if (empty($groupIds)) {
            return false;
        }

        $membersTable = DatabaseManager::invitationGroupMembersTable();
        $placeholders = implode(', ', array_fill(0, count($groupIds), '%d'));

        $sql    = "SELECT COUNT(*) FROM {$membersTable}
                    WHERE group_id IN ({$placeholders})
                      AND (
                          rsvp_status != %s
                          OR food_option_id IS NOT NULL
                          OR beverage_option_id IS NOT NULL
                          OR lodging_id IS NOT NULL
                          OR lodging_is_other = 1
                          OR lodging_undisclosed = 1
                          OR dietary_notes != ''
                          OR seat_assignment != ''
                      )";
        $params = $groupIds;
        $params[] = self::RSVP_PENDING;

        if ($onlyForInviteeId !== null) {
            $sql .= ' AND invitee_id = %d';
            $params[] = $onlyForInviteeId;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0;
    }

    /**
     * Returns true if a group-level field (shared RSVP notes, lodging notes, or
     * a lodging-booked flag) has left its default empty/unset state — the
     * group-level counterpart to anyGroupHasMeaningfulMemberData().
     */
    private static function groupHasMeaningfulGroupLevelData(self $group): bool
    {
        return trim($group->rsvpNotes) !== ''
            || trim($group->lodgingNotes) !== ''
            || $group->lodgingBooked;
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    /**
     * Creates a new invitation group with its primary member and optional additional members.
     *
     * @param int   $eventId
     * @param int   $primaryInviteeId
     * @param int[] $additionalMemberIds
     * @return self|null
     */
    public static function create(int $eventId, int $primaryInviteeId, array $additionalMemberIds = []): ?self
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();
        $orderMap     = ConnectionGroup::memberOrderMap($primaryInviteeId);

        $result = $wpdb->insert($groupsTable, [
            'event_id'           => $eventId,
            'primary_invitee_id' => $primaryInviteeId,
        ]);

        if (!$result) {
            return null;
        }

        $groupId = (int) $wpdb->insert_id;

        // High-water mark so members with no connection-group order (appended
        // below) always sort after every connection-group-ordered member,
        // regardless of insertion sequence in this loop.
        $highWater    = empty($orderMap) ? 0 : max($orderMap);
        $primaryOrder = $orderMap[$primaryInviteeId] ?? ++$highWater;

        $primaryInserted = $wpdb->insert($membersTable, [
            'group_id'    => $groupId,
            'invitee_id'  => $primaryInviteeId,
            'rsvp_status' => self::RSVP_PENDING,
            'sort_order'  => $primaryOrder,
        ]);

        if (!$primaryInserted) {
            return null;
        }

        $allIds = array_unique(array_filter(array_map('intval', $additionalMemberIds)));
        foreach ($allIds as $memberId) {
            if ($memberId === $primaryInviteeId) {
                continue;
            }

            $memberOrder = $orderMap[$memberId] ?? ++$highWater;

            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
                    $groupId,
                    $memberId,
                    self::RSVP_PENDING,
                    $memberOrder
                )
            );

            if ($inserted === false) {
                return null;
            }
        }

        $newGroup = self::find($groupId);

        if ($newGroup !== null) {
            foreach (Event::childrenOf($eventId) as $childEvent) {
                if (self::createInheritedFrom($childEvent->id, $newGroup) === null) {
                    // A partial failure here must fail the whole group creation
                    // so the caller's transaction rolls everything back rather
                    // than committing a parent group with an incomplete child.
                    return null;
                }
            }
        }

        return $newGroup;
    }

    /**
     * Materializes a real child-event invitation group inherited from a parent
     * group: same primary invitee and member set (same sort order), all members
     * starting pending. Never copies RSVP answers, food/beverage/lodging, notes,
     * or seating. Idempotent — returns the existing inherited group if one
     * already exists for this (child event, parent group) pair.
     *
     * @param int  $childEventId
     * @param self $parentGroup
     * @return self|null
     */
    public static function createInheritedFrom(int $childEventId, self $parentGroup): ?self
    {
        global $wpdb;

        $existing = self::findByInheritance($childEventId, $parentGroup->id);
        if ($existing !== null) {
            return $existing;
        }

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $result = $wpdb->insert($groupsTable, [
            'event_id'                => $childEventId,
            'primary_invitee_id'      => $parentGroup->primaryInviteeId,
            'inherited_from_group_id' => $parentGroup->id,
        ]);

        if (!$result) {
            return null;
        }

        $childGroupId = (int) $wpdb->insert_id;

        foreach ($parentGroup->getMembers() as $member) {
            $memberInserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
                    $childGroupId,
                    $member->id,
                    self::RSVP_PENDING,
                    $member->sortOrder
                )
            );

            if ($memberInserted === false) {
                // Signal failure so the caller's transaction rolls back rather
                // than leaving a materialized child group with missing members.
                return null;
            }

            $eventInviteeInserted = $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO ' . DatabaseManager::eventInviteesTable() . ' (event_id, invitee_id) VALUES (%d, %d)',
                    $childEventId,
                    $member->id
                )
            );

            if ($eventInviteeInserted === false) {
                return null;
            }
        }

        return self::find($childGroupId);
    }

    /**
     * Materializes-or-repairs a child event's inherited group against its
     * current parent group: creates it if missing (same as createInheritedFrom()),
     * otherwise diffs current parent membership against the child's and repairs
     * drift — adds members the parent has gained, removes members the parent has
     * lost (only while that child member is still in its default pending/empty
     * state — a member who has already responded is never auto-removed), and
     * corrects a mismatched primary invitee or sort_order. This is what makes
     * the admin's "Resync Guest List to Children" action actually reconcile an
     * existing child group rather than silently no-op against it.
     *
     * @param int  $childEventId
     * @param self $parentGroup
     * @return array{ok: bool, changed: int}
     */
    public static function reconcileInheritedGroup(int $childEventId, self $parentGroup): array
    {
        global $wpdb;

        $childGroup = self::findByInheritance($childEventId, $parentGroup->id);

        if ($childGroup === null) {
            $created = self::createInheritedFrom($childEventId, $parentGroup);
            return ['ok' => $created !== null, 'changed' => $created !== null ? 1 : 0];
        }

        $membersTable = DatabaseManager::invitationGroupMembersTable();
        $changed      = 0;

        $parentMembers   = $parentGroup->getMembers();
        $parentMemberIds = array_map(static fn(Invitee $m): int => $m->id, $parentMembers);
        $parentSortById  = [];
        foreach ($parentMembers as $m) {
            $parentSortById[$m->id] = $m->sortOrder;
        }

        $childRows = $wpdb->get_results($wpdb->prepare(
            "SELECT invitee_id, sort_order, rsvp_status, food_option_id, beverage_option_id,
                    lodging_id, lodging_is_other, lodging_undisclosed, dietary_notes, seat_assignment
             FROM {$membersTable} WHERE group_id = %d",
            $childGroup->id
        ));
        $childRowsById = [];
        foreach ($childRows ?? [] as $row) {
            $childRowsById[(int) $row->invitee_id] = $row;
        }

        // 1. Add members the parent has gained but the child is missing — pending.
        foreach ($parentMemberIds as $inviteeId) {
            if (isset($childRowsById[$inviteeId])) {
                continue;
            }

            $sortOrder = $parentSortById[$inviteeId] ?? self::nextMemberSortOrder($childGroup->id);

            $memberInserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
                $childGroup->id, $inviteeId, self::RSVP_PENDING, $sortOrder
            ));
            if ($memberInserted === false) {
                return ['ok' => false, 'changed' => $changed];
            }

            $eventInviteeInserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . DatabaseManager::eventInviteesTable() . ' (event_id, invitee_id) VALUES (%d, %d)',
                $childEventId, $inviteeId
            ));
            if ($eventInviteeInserted === false) {
                return ['ok' => false, 'changed' => $changed];
            }

            $changed++;
        }

        // 2. Remove members the parent has lost, but only while the child's own
        //    row is still fully default — never discard a real response.
        foreach ($childRowsById as $inviteeId => $row) {
            if (in_array($inviteeId, $parentMemberIds, true)) {
                continue;
            }

            $isDefault = $row->rsvp_status === self::RSVP_PENDING
                && $row->food_option_id === null
                && $row->beverage_option_id === null
                && $row->lodging_id === null
                && !$row->lodging_is_other
                && !$row->lodging_undisclosed
                && trim((string) $row->dietary_notes) === ''
                && trim((string) $row->seat_assignment) === '';

            if (!$isDefault) {
                continue;
            }

            if ($wpdb->delete($membersTable, ['group_id' => $childGroup->id, 'invitee_id' => $inviteeId]) === false) {
                return ['ok' => false, 'changed' => $changed];
            }
            if ($wpdb->delete(DatabaseManager::eventInviteesTable(), ['event_id' => $childEventId, 'invitee_id' => $inviteeId]) === false) {
                return ['ok' => false, 'changed' => $changed];
            }

            $changed++;
        }

        // 3. Fix a mismatched primary invitee.
        if ($childGroup->primaryInviteeId !== $parentGroup->primaryInviteeId
            && in_array($parentGroup->primaryInviteeId, $parentMemberIds, true)) {
            $updated = $wpdb->update(
                DatabaseManager::invitationGroupsTable(),
                ['primary_invitee_id' => $parentGroup->primaryInviteeId],
                ['id' => $childGroup->id]
            );
            if ($updated === false) {
                return ['ok' => false, 'changed' => $changed];
            }
            $changed++;
        }

        // 4. Fix sort_order drift for members present in both.
        foreach ($parentMemberIds as $inviteeId) {
            if (!isset($childRowsById[$inviteeId])) {
                continue; // just-added above with the correct order already
            }

            $expected = $parentSortById[$inviteeId] ?? null;
            $current  = (int) $childRowsById[$inviteeId]->sort_order;

            if ($expected !== null && $expected !== $current) {
                $updated = $wpdb->update(
                    $membersTable,
                    ['sort_order' => $expected],
                    ['group_id' => $childGroup->id, 'invitee_id' => $inviteeId]
                );
                if ($updated === false) {
                    return ['ok' => false, 'changed' => $changed];
                }
                $changed++;
            }
        }

        return ['ok' => true, 'changed' => $changed];
    }

    /**
     * Deletes every inherited invitation group (and its members/event_invitees
     * rows) belonging to a specific child event, regardless of which parent
     * group each one descends from. Used when unlinking a child event from
     * its parent; caller must already have verified no meaningful data would
     * be discarded (see hasAnyMeaningfulInheritedData()).
     *
     * @param int $childEventId
     * @return bool
     */
    public static function deleteAllInheritedGroupsForEvent(int $childEventId): bool
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $groupIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$groupsTable} WHERE event_id = %d AND inherited_from_group_id IS NOT NULL",
                $childEventId
            )
        );

        foreach ($groupIds as $groupId) {
            $groupId   = (int) $groupId;
            $memberIds = $wpdb->get_col(
                $wpdb->prepare("SELECT invitee_id FROM {$membersTable} WHERE group_id = %d", $groupId)
            );

            foreach ($memberIds as $mid) {
                if ($wpdb->delete(DatabaseManager::eventInviteesTable(), ['event_id' => $childEventId, 'invitee_id' => (int) $mid]) === false) {
                    return false;
                }
            }

            if ($wpdb->delete($membersTable, ['group_id' => $groupId]) === false) {
                return false;
            }
            if ($wpdb->delete($groupsTable, ['id' => $groupId]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes a single invitee from their group in an event.
     *
     * Handles group cleanup (empty → delete, primary removed → promote), and
     * cascades the same removal to every child event's materialized inherited
     * group. Blocked entirely (nothing is changed) if the invitee has left any
     * non-default data (RSVP status, food/beverage/lodging, notes, seating) on
     * one of those child events — see hasAnyMeaningfulInheritedDataForParentGroup().
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return array{ok: bool, reason?: string}
     */
    public static function removeMemberFromEvent(int $inviteeId, int $eventId): array
    {
        global $wpdb;

        $groupsTable        = DatabaseManager::invitationGroupsTable();
        $membersTable       = DatabaseManager::invitationGroupMembersTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

        $group = self::findForMember($inviteeId, $eventId);

        if ($group !== null && self::hasAnyMeaningfulInheritedDataForParentGroup($group->id, $inviteeId)) {
            return ['ok' => false, 'reason' => 'has_child_responses'];
        }

        $eventInviteeDeleted = $wpdb->delete($eventInviteesTable, ['event_id' => $eventId, 'invitee_id' => $inviteeId]);
        if ($eventInviteeDeleted === false) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        if ($group === null) {
            return ['ok' => true];
        }

        if (!self::removeMemberFromInheritedChildren($group->id, $inviteeId)) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        if ($wpdb->delete($membersTable, ['group_id' => $group->id, 'invitee_id' => $inviteeId]) === false) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$membersTable} WHERE group_id = %d", $group->id)
        );

        if ($remaining === 0) {
            QrCode::deleteForGroup($group->id);
            $wpdb->delete($groupsTable, ['id' => $group->id]);
            if (!self::deleteInheritedChildGroups($group->id)) {
                return ['ok' => false, 'reason' => 'db_error'];
            }
            return ['ok' => true];
        }

        if ($inviteeId === $group->primaryInviteeId) {
            $newPrimary = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT invitee_id FROM {$membersTable} WHERE group_id = %d ORDER BY id ASC LIMIT 1",
                    $group->id
                )
            );
            if ($newPrimary > 0) {
                $wpdb->update($groupsTable, ['primary_invitee_id' => $newPrimary], ['id' => $group->id]);
                if (!self::propagatePrimaryToInheritedChildren($group->id, $newPrimary)) {
                    return ['ok' => false, 'reason' => 'db_error'];
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * Removes one invitee's member row (and event_invitees row) from every child
     * group descended from the given parent group. If a child group is left
     * empty, or the removed invitee was that child group's primary, promotes a
     * new primary or deletes the now-empty child group — mirroring the parent
     * cleanup logic in removeMemberFromEvent().
     *
     * Caller must already have verified no meaningful data would be discarded
     * (see hasAnyMeaningfulInheritedDataForParentGroup()).
     *
     * @param int $parentGroupId
     * @param int $inviteeId
     * @return bool
     */
    private static function removeMemberFromInheritedChildren(int $parentGroupId, int $inviteeId): bool
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $childGroups = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$groupsTable} WHERE inherited_from_group_id = %d", $parentGroupId)
        );

        foreach ($childGroups ?? [] as $row) {
            $childGroup = self::fromRow($row);

            if ($wpdb->delete(DatabaseManager::eventInviteesTable(), ['event_id' => $childGroup->eventId, 'invitee_id' => $inviteeId]) === false) {
                return false;
            }
            if ($wpdb->delete($membersTable, ['group_id' => $childGroup->id, 'invitee_id' => $inviteeId]) === false) {
                return false;
            }

            $remaining = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$membersTable} WHERE group_id = %d", $childGroup->id)
            );

            if ($remaining === 0) {
                if ($wpdb->delete($groupsTable, ['id' => $childGroup->id]) === false) {
                    return false;
                }
                continue;
            }

            if ($inviteeId === $childGroup->primaryInviteeId) {
                $newPrimary = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT invitee_id FROM {$membersTable} WHERE group_id = %d ORDER BY id ASC LIMIT 1",
                        $childGroup->id
                    )
                );
                if ($newPrimary > 0 && $wpdb->update($groupsTable, ['primary_invitee_id' => $newPrimary], ['id' => $childGroup->id]) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Deletes every child group (and its members/event_invitees rows) descended
     * from the given parent group. Caller must already have verified no
     * meaningful data would be discarded.
     *
     * @param int $parentGroupId
     * @return bool
     */
    private static function deleteInheritedChildGroups(int $parentGroupId): bool
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $childGroups = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$groupsTable} WHERE inherited_from_group_id = %d", $parentGroupId)
        );

        foreach ($childGroups ?? [] as $row) {
            $childGroup = self::fromRow($row);

            $memberIds = $wpdb->get_col(
                $wpdb->prepare("SELECT invitee_id FROM {$membersTable} WHERE group_id = %d", $childGroup->id)
            );
            foreach ($memberIds as $mid) {
                if ($wpdb->delete(DatabaseManager::eventInviteesTable(), ['event_id' => $childGroup->eventId, 'invitee_id' => (int) $mid]) === false) {
                    return false;
                }
            }

            if ($wpdb->delete($membersTable, ['group_id' => $childGroup->id]) === false) {
                return false;
            }
            if ($wpdb->delete($groupsTable, ['id' => $childGroup->id]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Updates primary_invitee_id on every child group descended from the given
     * parent group, keeping them in sync when the parent's primary changes.
     *
     * @param int $parentGroupId
     * @param int $newPrimaryInviteeId
     * @return bool
     */
    private static function propagatePrimaryToInheritedChildren(int $parentGroupId, int $newPrimaryInviteeId): bool
    {
        global $wpdb;

        $groupsTable = DatabaseManager::invitationGroupsTable();

        return $wpdb->update(
            $groupsTable,
            ['primary_invitee_id' => $newPrimaryInviteeId],
            ['inherited_from_group_id' => $parentGroupId]
        ) !== false;
    }

    /**
     * Sets a group member as the primary recipient.
     *
     * @param int $groupId
     * @param int $inviteeId
     * @return bool
     */
    public static function setPrimaryMember(int $groupId, int $inviteeId): bool
    {
        global $wpdb;

        $isMember = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM " . DatabaseManager::invitationGroupMembersTable() . " WHERE group_id = %d AND invitee_id = %d",
                $groupId, $inviteeId
            )
        );

        if (!$isMember) {
            return false;
        }

        $ok = $wpdb->update(
            DatabaseManager::invitationGroupsTable(),
            ['primary_invitee_id' => $inviteeId],
            ['id' => $groupId]
        ) !== false;

        if ($ok) {
            $ok = self::propagatePrimaryToInheritedChildren($groupId, $inviteeId);
        }

        return $ok;
    }

    /**
     * Adds a new member to an existing group and registers them on the event.
     *
     * Also adds the invitee (pending) to every child event's already-materialized
     * inherited group descended from this one, since child membership is always
     * inherited from the parent.
     *
     * @param int $groupId
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function addMemberToGroup(int $groupId, int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        $eventInviteeInserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . DatabaseManager::eventInviteesTable() . " (event_id, invitee_id) VALUES (%d, %d)",
            $eventId, $inviteeId
        ));

        if ($eventInviteeInserted === false) {
            return false;
        }

        $group       = self::find($groupId);
        $orderMap    = $group !== null ? ConnectionGroup::memberOrderMap($group->primaryInviteeId) : [];
        $sortOrder   = $orderMap[$inviteeId] ?? self::nextMemberSortOrder($groupId);

        $ok = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . DatabaseManager::invitationGroupMembersTable() . " (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
            $groupId, $inviteeId, self::RSVP_PENDING, $sortOrder
        )) !== false;

        if ($ok) {
            $ok = self::addMemberToInheritedChildren($groupId, $inviteeId, $sortOrder);
        }

        return $ok;
    }

    /**
     * Adds one invitee (pending) to every child group descended from the given
     * parent group, plus the corresponding child event_invitees row. Mirrors
     * the parent's own sort_order for this invitee, rather than each child
     * independently appending at its own next position, so display order
     * cannot diverge between the parent and its children.
     *
     * @param int $parentGroupId
     * @param int $inviteeId
     * @param int $sortOrder The invitee's sort_order on the parent group.
     * @return bool
     */
    private static function addMemberToInheritedChildren(int $parentGroupId, int $inviteeId, int $sortOrder): bool
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $childGroups = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$groupsTable} WHERE inherited_from_group_id = %d", $parentGroupId)
        );

        foreach ($childGroups ?? [] as $row) {
            $childGroup = self::fromRow($row);

            $eventInviteeInserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . DatabaseManager::eventInviteesTable() . ' (event_id, invitee_id) VALUES (%d, %d)',
                $childGroup->eventId,
                $inviteeId
            ));

            if ($eventInviteeInserted === false) {
                return false;
            }

            $memberInserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
                $childGroup->id,
                $inviteeId,
                self::RSVP_PENDING,
                $sortOrder
            ));

            if ($memberInserted === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deletes an entire group, removing all its members from the event, and
     * cascades to every child event's materialized inherited group. Blocked
     * entirely (nothing is changed) if any inherited child data has left its
     * default pending/empty state — see hasAnyMeaningfulInheritedDataForParentGroup().
     *
     * @param int $groupId
     * @param int $eventId
     * @return array{ok: bool, reason?: string}
     */
    public static function deleteGroup(int $groupId, int $eventId): array
    {
        global $wpdb;

        if (self::hasAnyMeaningfulInheritedDataForParentGroup($groupId)) {
            return ['ok' => false, 'reason' => 'has_child_responses'];
        }

        $membersTable       = DatabaseManager::invitationGroupMembersTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

        $memberIds = $wpdb->get_col(
            $wpdb->prepare("SELECT invitee_id FROM {$membersTable} WHERE group_id = %d", $groupId)
        );

        foreach ($memberIds as $mid) {
            if ($wpdb->delete($eventInviteesTable, ['event_id' => $eventId, 'invitee_id' => (int) $mid]) === false) {
                return ['ok' => false, 'reason' => 'db_error'];
            }
        }

        if (!self::deleteInheritedChildGroups($groupId)) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        if ($wpdb->delete($membersTable, ['group_id' => $groupId]) === false) {
            return ['ok' => false, 'reason' => 'db_error'];
        }
        QrCode::deleteForGroup($groupId);
        if ($wpdb->delete(DatabaseManager::invitationGroupsTable(), ['id' => $groupId]) === false) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        return ['ok' => true];
    }

    /**
     * Removes an invitee from every invitation group across all events.
     *
     * Called by Invitee::delete() before the invitee row is removed.
     *
     * @param int $inviteeId
     * @return void
     */
    public static function removeInviteeFromAllGroups(int $inviteeId): void
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $groupIds = $wpdb->get_col(
            $wpdb->prepare("SELECT group_id FROM {$membersTable} WHERE invitee_id = %d", $inviteeId)
        );

        if (empty($groupIds)) {
            return;
        }

        $wpdb->delete($membersTable, ['invitee_id' => $inviteeId]);

        foreach ($groupIds as $rawGroupId) {
            $groupId = (int) $rawGroupId;

            $remaining = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$membersTable} WHERE group_id = %d", $groupId)
            );

            if ($remaining === 0) {
                QrCode::deleteForGroup($groupId);
                $wpdb->delete($groupsTable, ['id' => $groupId]);
            } else {
                $group = self::find($groupId);
                if ($group && $group->primaryInviteeId === $inviteeId) {
                    $newPrimary = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT invitee_id FROM {$membersTable} WHERE group_id = %d ORDER BY id ASC LIMIT 1",
                            $groupId
                        )
                    );
                    if ($newPrimary > 0) {
                        $wpdb->update($groupsTable, ['primary_invitee_id' => $newPrimary], ['id' => $groupId]);
                    }
                }
            }
        }
    }

    /**
     * Deletes all invitation groups (and their members) for a given event.
     *
     * @param int $eventId
     * @return void
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $groupIds = $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM {$groupsTable} WHERE event_id = %d", $eventId)
        );

        if (!empty($groupIds)) {
            $placeholders = implode(', ', array_fill(0, count($groupIds), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$membersTable} WHERE group_id IN ({$placeholders})",
                    ...$groupIds
                )
            );
        }

        $wpdb->delete($groupsTable, ['event_id' => $eventId]);
    }

    // -------------------------------------------------------------------------
    // RSVP / invite-sent state
    // -------------------------------------------------------------------------

    /**
     * Records the invite-sent timestamp on this group.
     *
     * @param int $groupId
     * @return bool
     */
    public static function markInviteSent(int $groupId): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::invitationGroupsTable(),
            ['invite_sent_at' => current_time('mysql')],
            ['id' => $groupId]
        );

        return $result !== false;
    }

    /**
     * Saves the shared RSVP notes for an invitation group.
     *
     * @param int    $groupId
     * @param string $notes
     * @return bool
     */
    public static function updateRsvpNotes(int $groupId, string $notes): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::invitationGroupsTable(),
            [
                'rsvp_notes'            => $notes,
                'rsvp_notes_updated_at' => current_time('mysql'),
            ],
            ['id' => $groupId]
        );

        return $result !== false;
    }

    /**
     * Marks all pending members of a group as attending.
     *
     * @param int $groupId
     * @return bool
     */
    public static function markAllMembersAttending(int $groupId): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::invitationGroupMembersTable(),
            ['rsvp_status' => self::RSVP_ATTENDING, 'registered_at' => current_time('mysql')],
            ['group_id' => $groupId, 'rsvp_status' => self::RSVP_PENDING]
        );

        return $result !== false;
    }

    /**
     * Sets the RSVP status (and optional food/beverage selections) for a specific member in a group.
     *
     * @param int    $groupId
     * @param int    $inviteeId
     * @param string $status    One of the RSVP_* constants.
     * @param array  $extras    Optional keys:
     *                            food_option_id (int|null), beverage_option_id (int|null),
     *                            dietary_notes (string), food_confirmed_at (string|null),
     *                            beverage_confirmed_at (string|null),
     *                            lodging_id (int|null), lodging_is_other (bool),
     *                            lodging_undisclosed (bool), lodging_confirmed_at (string|null).
     * @return bool
     */
    public static function updateMemberRsvp(int $groupId, int $inviteeId, string $status, array $extras = []): bool
    {
        global $wpdb;

        $status = in_array($status, [self::RSVP_PENDING, self::RSVP_ATTENDING, self::RSVP_DECLINED], true)
            ? $status
            : self::RSVP_PENDING;

        // Preserve the original registered_at once it is set — later food/lodging edits
        // should not overwrite the date the invitee first accepted.
        if ($status === self::RSVP_ATTENDING) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT registered_at FROM " . DatabaseManager::invitationGroupMembersTable() . " WHERE group_id = %d AND invitee_id = %d LIMIT 1", // phpcs:ignore
                $groupId,
                $inviteeId
            ));
            $registeredAt = $existing ?? current_time('mysql');
        } else {
            $registeredAt = null;
        }

        $fields = [
            'rsvp_status'   => $status,
            'registered_at' => $registeredAt,
        ];

        if (array_key_exists('food_option_id', $extras)) {
            $fields['food_option_id'] = $extras['food_option_id'] !== null ? (int) $extras['food_option_id'] : null;
        }
        if (array_key_exists('beverage_option_id', $extras)) {
            $fields['beverage_option_id'] = $extras['beverage_option_id'] !== null ? (int) $extras['beverage_option_id'] : null;
        }
        if (array_key_exists('dietary_notes', $extras)) {
            $fields['dietary_notes'] = (string) $extras['dietary_notes'];
        }
        if (array_key_exists('food_confirmed_at', $extras)) {
            $fields['food_confirmed_at'] = $extras['food_confirmed_at'];
        }
        if (array_key_exists('beverage_confirmed_at', $extras)) {
            $fields['beverage_confirmed_at'] = $extras['beverage_confirmed_at'];
        }
        if (array_key_exists('lodging_id', $extras)) {
            $fields['lodging_id'] = $extras['lodging_id'] !== null ? (int) $extras['lodging_id'] : null;
        }
        if (array_key_exists('lodging_is_other', $extras)) {
            $fields['lodging_is_other'] = (int) (bool) $extras['lodging_is_other'];
        }
        if (array_key_exists('lodging_undisclosed', $extras)) {
            $fields['lodging_undisclosed'] = (int) (bool) $extras['lodging_undisclosed'];
        }
        if (array_key_exists('lodging_confirmed_at', $extras)) {
            $fields['lodging_confirmed_at'] = $extras['lodging_confirmed_at'];
        }

        $result = $wpdb->update(
            DatabaseManager::invitationGroupMembersTable(),
            $fields,
            ['group_id' => $groupId, 'invitee_id' => $inviteeId]
        );

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Instance helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the loaded members, fetching from the database if not yet loaded.
     *
     * @return Invitee[]
     */
    public function getMembers(): array
    {
        if ($this->members === null) {
            $this->members = self::loadMembersForGroups([$this->id])[$this->id] ?? [];
        }

        return $this->members;
    }

    public function memberCount(): int
    {
        return count($this->getMembers());
    }

    public function attendingCount(): int
    {
        return count(array_filter(
            $this->getMembers(),
            static fn(Invitee $m) => $m->rsvpStatus === self::RSVP_ATTENDING
        ));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Loads and returns event invitation group members keyed by group ID.
     *
     * Hydrates Invitee instances with rsvpStatus, registeredAt, and inviteSentAt
     * from the invitation_group and invitation_group_members tables.
     *
     * @param int[] $groupIds
     * @return array<int, Invitee[]>
     */
    private static function loadMembersForGroups(array $groupIds): array
    {
        global $wpdb;

        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (empty($groupIds)) {
            return [];
        }

        $inviteesTable = DatabaseManager::inviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();
        $placeholders  = implode(', ', array_fill(0, count($groupIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*,
                        egm.group_id              AS invitation_group_id,
                        eig.event_id              AS invitation_event_id,
                        egm.rsvp_status           AS invitation_rsvp_status,
                        egm.registered_at         AS invitation_registered_at,
                        eig.invite_sent_at        AS invitation_invite_sent_at,
                        egm.food_option_id        AS invitation_food_option_id,
                        egm.beverage_option_id    AS invitation_beverage_option_id,
                        egm.dietary_notes         AS invitation_dietary_notes,
                        egm.food_confirmed_at     AS invitation_food_confirmed_at,
                        egm.beverage_confirmed_at AS invitation_beverage_confirmed_at,
                        egm.lodging_id            AS invitation_lodging_id,
                        egm.lodging_is_other      AS invitation_lodging_is_other,
                        egm.lodging_undisclosed   AS invitation_lodging_undisclosed,
                        egm.lodging_confirmed_at  AS invitation_lodging_confirmed_at,
                        egm.seat_assignment       AS invitation_seat_assignment,
                        egm.sort_order            AS invitation_sort_order
                 FROM {$membersTable} egm
                 INNER JOIN {$inviteesTable} i   ON i.id   = egm.invitee_id
                 INNER JOIN {$groupsTable}   eig ON eig.id = egm.group_id
                 WHERE egm.group_id IN ({$placeholders})
                 ORDER BY egm.sort_order ASC, i.last_name ASC, i.first_name ASC",
                ...$groupIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $groupId = (int) $row->invitation_group_id;
            $grouped[$groupId][] = Invitee::fromPublicRow($row);
        }

        return $grouped;
    }

    public static function updateMemberSeatAssignment(int $groupId, int $inviteeId, string $seatAssignment): bool
    {
        global $wpdb;
        $table = DatabaseManager::invitationGroupMembersTable();
        return $wpdb->update(
            $table,
            ['seat_assignment' => $seatAssignment],
            ['group_id' => $groupId, 'invitee_id' => $inviteeId]
        ) !== false;
    }

    /**
     * Computes the next available sort_order for a member being added to a group.
     *
     * Public because RequestedInviteeAddOn::approve() also needs it for its
     * own raw member insert (it can't go through addMemberToGroup() since it
     * needs to set rsvp_status/registered_at directly).
     *
     * @param int $groupId
     * @return int
     */
    public static function nextMemberSortOrder(int $groupId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM " . DatabaseManager::invitationGroupMembersTable() . " WHERE group_id = %d",
                $groupId
            )
        );
    }

    /**
     * Moves a member to a new 1-based position within their group and renumbers
     * every other member's sort_order to keep the sequence contiguous.
     *
     * @param int $groupId
     * @param int $inviteeId
     * @param int $newPosition 1-based target position (clamped to the member count).
     * @return bool
     */
    public static function updateMemberSortOrder(int $groupId, int $inviteeId, int $newPosition): bool
    {
        global $wpdb;

        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $orderedIds = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT invitee_id FROM {$membersTable} WHERE group_id = %d ORDER BY sort_order ASC, id ASC",
                    $groupId
                )
            )
        );

        $currentIndex = array_search($inviteeId, $orderedIds, true);
        if ($currentIndex === false) {
            return false;
        }

        unset($orderedIds[$currentIndex]);
        $orderedIds = array_values($orderedIds);

        $targetIndex = max(0, min($newPosition - 1, count($orderedIds)));
        array_splice($orderedIds, $targetIndex, 0, [$inviteeId]);

        foreach ($orderedIds as $index => $id) {
            $updated = $wpdb->update(
                $membersTable,
                ['sort_order' => $index + 1],
                ['group_id' => $groupId, 'invitee_id' => $id]
            );

            if ($updated === false) {
                return false;
            }
        }

        if (!self::propagateSortOrderToInheritedChildren($groupId, $orderedIds)) {
            return false;
        }

        return true;
    }

    /**
     * Applies the parent group's final member order to every child group
     * descended from it, matched by invitee_id. Order is inherited, not
     * independently editable on a child event.
     *
     * @param int   $parentGroupId
     * @param int[] $orderedInviteeIds Final 0-indexed order (index + 1 = sort_order).
     * @return bool
     */
    private static function propagateSortOrderToInheritedChildren(int $parentGroupId, array $orderedInviteeIds): bool
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        $childGroupIds = $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM {$groupsTable} WHERE inherited_from_group_id = %d", $parentGroupId)
        );

        foreach ($childGroupIds as $childGroupId) {
            foreach ($orderedInviteeIds as $index => $inviteeId) {
                $updated = $wpdb->update(
                    $membersTable,
                    ['sort_order' => $index + 1],
                    ['group_id' => (int) $childGroupId, 'invitee_id' => $inviteeId]
                );

                if ($updated === false) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function updateLodgingNotes(int $groupId, string $notes): bool
    {
        return self::updateLodgingDetails($groupId, null, $notes);
    }

    /**
     * Saves shared lodging details for an invitation group.
     *
     * Pass null for either argument to leave that field unchanged.
     *
     * @param int       $groupId
     * @param bool|null $booked
     * @param string|null $notes
     * @return bool
     */
    public static function updateLodgingDetails(int $groupId, ?bool $booked = null, ?string $notes = null): bool
    {
        global $wpdb;

        $fields = [];

        if ($booked !== null) {
            $fields['lodging_booked'] = (int) $booked;

            if ($booked) {
                $existingBookedAt = $wpdb->get_var($wpdb->prepare(
                    "SELECT lodging_booked_at FROM " . DatabaseManager::invitationGroupsTable() . " WHERE id = %d LIMIT 1", // phpcs:ignore
                    $groupId
                ));
                $fields['lodging_booked_at'] = $existingBookedAt ?: current_time('mysql');
            } else {
                $fields['lodging_booked_at'] = null;
            }
        }

        if ($notes !== null) {
            $fields['lodging_notes'] = $notes;
        }

        if (empty($fields)) {
            return true;
        }

        $result = $wpdb->update(
            DatabaseManager::invitationGroupsTable(),
            $fields,
            ['id'            => $groupId]
        );

        return $result !== false;
    }

    private static function fromRow(object $row): self
    {
        return new self(
            id:               (int) $row->id,
            eventId:          (int) $row->event_id,
            primaryInviteeId: (int) $row->primary_invitee_id,
            inviteSentAt:           $row->invite_sent_at ?? null,
            rsvpNotes:              $row->rsvp_notes ?? '',
            rsvpNotesUpdatedAt:     $row->rsvp_notes_updated_at ?? null,
            lodgingBooked:       (bool) ($row->lodging_booked ?? false),
            lodgingBookedAt:        $row->lodging_booked_at ?? null,
            lodgingNotes:           $row->lodging_notes ?? '',
            inheritedFromGroupId: isset($row->inherited_from_group_id) && $row->inherited_from_group_id !== null ? (int) $row->inherited_from_group_id : null,
            createdAt:              $row->created_at     ?? '',
            updatedAt:              $row->updated_at     ?? '',
        );
    }
}
