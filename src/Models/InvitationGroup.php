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

        $wpdb->insert($membersTable, [
            'group_id'    => $groupId,
            'invitee_id'  => $primaryInviteeId,
            'rsvp_status' => self::RSVP_PENDING,
            'sort_order'  => $primaryOrder,
        ]);

        $allIds = array_unique(array_filter(array_map('intval', $additionalMemberIds)));
        foreach ($allIds as $memberId) {
            if ($memberId === $primaryInviteeId) {
                continue;
            }

            $memberOrder = $orderMap[$memberId] ?? ++$highWater;

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
                    $groupId,
                    $memberId,
                    self::RSVP_PENDING,
                    $memberOrder
                )
            );
        }

        return self::find($groupId);
    }

    /**
     * Removes a single invitee from their group in an event.
     *
     * Handles group cleanup (empty → delete, primary removed → promote).
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function removeMemberFromEvent(int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        $groupsTable        = DatabaseManager::invitationGroupsTable();
        $membersTable       = DatabaseManager::invitationGroupMembersTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

        $group = self::findForMember($inviteeId, $eventId);

        $wpdb->delete($eventInviteesTable, ['event_id' => $eventId, 'invitee_id' => $inviteeId]);

        if ($group === null) {
            return true;
        }

        $wpdb->delete($membersTable, ['group_id' => $group->id, 'invitee_id' => $inviteeId]);

        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$membersTable} WHERE group_id = %d", $group->id)
        );

        if ($remaining === 0) {
            QrCode::deleteForGroup($group->id);
            $wpdb->delete($groupsTable, ['id' => $group->id]);
            return true;
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
            }
        }

        return true;
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

        return $wpdb->update(
            DatabaseManager::invitationGroupsTable(),
            ['primary_invitee_id' => $inviteeId],
            ['id' => $groupId]
        ) !== false;
    }

    /**
     * Adds a new member to an existing group and registers them on the event.
     *
     * @param int $groupId
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function addMemberToGroup(int $groupId, int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . DatabaseManager::eventInviteesTable() . " (event_id, invitee_id) VALUES (%d, %d)",
            $eventId, $inviteeId
        ));

        $group       = self::find($groupId);
        $orderMap    = $group !== null ? ConnectionGroup::memberOrderMap($group->primaryInviteeId) : [];
        $sortOrder   = $orderMap[$inviteeId] ?? self::nextMemberSortOrder($groupId);

        return $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . DatabaseManager::invitationGroupMembersTable() . " (group_id, invitee_id, rsvp_status, sort_order) VALUES (%d, %d, %s, %d)",
            $groupId, $inviteeId, self::RSVP_PENDING, $sortOrder
        )) !== false;
    }

    /**
     * Deletes an entire group, removing all its members from the event.
     *
     * @param int $groupId
     * @param int $eventId
     * @return bool
     */
    public static function deleteGroup(int $groupId, int $eventId): bool
    {
        global $wpdb;

        $membersTable       = DatabaseManager::invitationGroupMembersTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

        $memberIds = $wpdb->get_col(
            $wpdb->prepare("SELECT invitee_id FROM {$membersTable} WHERE group_id = %d", $groupId)
        );

        foreach ($memberIds as $mid) {
            $wpdb->delete($eventInviteesTable, ['event_id' => $eventId, 'invitee_id' => (int) $mid]);
        }

        $wpdb->delete($membersTable, ['group_id' => $groupId]);
        QrCode::deleteForGroup($groupId);
        $wpdb->delete(DatabaseManager::invitationGroupsTable(), ['id' => $groupId]);

        return true;
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
            createdAt:              $row->created_at     ?? '',
            updatedAt:              $row->updated_at     ?? '',
        );
    }
}
