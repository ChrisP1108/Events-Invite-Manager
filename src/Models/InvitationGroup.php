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

        $result = $wpdb->insert($groupsTable, [
            'event_id'           => $eventId,
            'primary_invitee_id' => $primaryInviteeId,
        ]);

        if (!$result) {
            return null;
        }

        $groupId = (int) $wpdb->insert_id;

        $wpdb->insert($membersTable, [
            'group_id'    => $groupId,
            'invitee_id'  => $primaryInviteeId,
            'rsvp_status' => self::RSVP_PENDING,
        ]);

        $allIds = array_unique(array_filter(array_map('intval', $additionalMemberIds)));
        foreach ($allIds as $memberId) {
            if ($memberId === $primaryInviteeId) {
                continue;
            }
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id, rsvp_status) VALUES (%d, %d, %s)",
                    $groupId,
                    $memberId,
                    self::RSVP_PENDING
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

        return $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . DatabaseManager::invitationGroupMembersTable() . " (group_id, invitee_id, rsvp_status) VALUES (%d, %d, %s)",
            $groupId, $inviteeId, self::RSVP_PENDING
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
     * Sets the RSVP status for a specific member in a group.
     *
     * @param int    $groupId
     * @param int    $inviteeId
     * @param string $status  One of the RSVP_* constants.
     * @return bool
     */
    public static function updateMemberRsvp(int $groupId, int $inviteeId, string $status): bool
    {
        global $wpdb;

        $status = in_array($status, [self::RSVP_PENDING, self::RSVP_ATTENDING, self::RSVP_DECLINED], true)
            ? $status
            : self::RSVP_PENDING;

        $fields = ['rsvp_status' => $status];
        if ($status === self::RSVP_ATTENDING) {
            $fields['registered_at'] = current_time('mysql');
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
                        egm.group_id       AS invitation_group_id,
                        eig.event_id       AS invitation_event_id,
                        egm.rsvp_status    AS invitation_rsvp_status,
                        egm.registered_at  AS invitation_registered_at,
                        eig.invite_sent_at AS invitation_invite_sent_at
                 FROM {$membersTable} egm
                 INNER JOIN {$inviteesTable} i   ON i.id   = egm.invitee_id
                 INNER JOIN {$groupsTable}   eig ON eig.id = egm.group_id
                 WHERE egm.group_id IN ({$placeholders})
                 ORDER BY i.last_name ASC, i.first_name ASC",
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

    private static function fromRow(object $row): self
    {
        return new self(
            id:               (int) $row->id,
            eventId:          (int) $row->event_id,
            primaryInviteeId: (int) $row->primary_invitee_id,
            inviteSentAt:           $row->invite_sent_at ?? null,
            createdAt:              $row->created_at     ?? '',
            updatedAt:              $row->updated_at     ?? '',
        );
    }
}
