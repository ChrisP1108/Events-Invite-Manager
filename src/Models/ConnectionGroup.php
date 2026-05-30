<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

/**
 * Represents a global connection group (couple, family, household, etc.).
 *
 * Connection groups are reusable, event-independent relationships between invitees.
 * They serve as suggestions when building event invitation groups — the admin selects
 * which connected people to include in a specific event invite.
 */
final class ConnectionGroup
{
    /** @var Invitee[]|null Lazy-loaded members. */
    private ?array $members = null;

    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // Static finders
    // -------------------------------------------------------------------------

    /**
     * Finds a single connection group by primary key.
     *
     * @param int $id Primary key of the connection group.
     * @return self|null The connection group, or null if not found.
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::inviteeConnectionGroupsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns multiple groups by their IDs, with members pre-loaded.
     *
     * @param int[] $ids
     * @return self[] Indexed by group ID.
     */
    public static function findMany(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $table        = DatabaseManager::inviteeConnectionGroupsTable();
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY name ASC", ...$ids)
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
     * Returns all groups for the admin list, optionally filtered by search.
     *
     * Each group has its members pre-loaded.
     *
     * @param string $search
     * @param string $field  Restrict search to a single column; empty string searches all.
     * @return self[]
     */
    public static function listForAdmin(string $search = '', string $sort = 'name', string $order = 'asc', string $field = ''): array
    {
        global $wpdb;

        $groupsTable        = DatabaseManager::inviteeConnectionGroupsTable();
        $membersTable       = DatabaseManager::inviteeConnectionGroupMembersTable();
        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventsTable        = DatabaseManager::eventsTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

        $where  = '';
        $params = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';

            switch ($field) {
                case 'name':
                    $where  = "WHERE cg.name LIKE %s";
                    $params = [$like];
                    break;
                case 'members':
                    $where  = "WHERE EXISTS (
                        SELECT 1 FROM {$membersTable} cgm
                        INNER JOIN {$inviteesTable} i ON i.id = cgm.invitee_id
                        WHERE cgm.group_id = cg.id
                          AND (i.first_name LIKE %s OR i.last_name LIKE %s OR i.email LIKE %s)
                    )";
                    $params = [$like, $like, $like];
                    break;
                case 'invited_to':
                    $where  = "WHERE EXISTS (
                        SELECT 1 FROM {$membersTable} cgm_ev
                        INNER JOIN {$eventInviteesTable} ei ON ei.invitee_id = cgm_ev.invitee_id
                        INNER JOIN {$eventsTable} e ON e.id = ei.event_id
                        WHERE cgm_ev.group_id = cg.id AND LOWER(e.name) LIKE %s
                    )";
                    $params = [$like];
                    break;
                default:
                    $where  = "WHERE cg.name LIKE %s
                               OR EXISTS (
                                   SELECT 1 FROM {$membersTable} cgm
                                   INNER JOIN {$inviteesTable} i ON i.id = cgm.invitee_id
                                   WHERE cgm.group_id = cg.id
                                     AND (i.first_name LIKE %s OR i.last_name LIKE %s OR i.email LIKE %s)
                               )
                               OR EXISTS (
                                   SELECT 1 FROM {$membersTable} cgm_ev
                                   INNER JOIN {$eventInviteesTable} ei ON ei.invitee_id = cgm_ev.invitee_id
                                   INNER JOIN {$eventsTable} e ON e.id = ei.event_id
                                   WHERE cgm_ev.group_id = cg.id AND LOWER(e.name) LIKE %s
                               )";
                    $params = [$like, $like, $like, $like, $like];
            }
        }

        $dir         = $order === 'desc' ? 'DESC' : 'ASC';
        $orderClause = match ($sort) {
            'members',
            'invited_to' => 'ORDER BY cg.name ASC', // re-sorted in PHP after relational counts are known
            default      => "ORDER BY cg.name {$dir}, cg.id ASC",
        };

        $sql  = "SELECT cg.* FROM {$groupsTable} cg {$where} {$orderClause}";
        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        if (empty($rows)) {
            return [];
        }

        $groups   = array_map(static fn(object $r) => self::fromRow($r), $rows);
        $groupIds = array_map(static fn(self $g) => $g->id, $groups);

        $membersByGroup = self::loadMembersForGroups($groupIds);
        foreach ($groups as $group) {
            $group->members = $membersByGroup[$group->id] ?? [];
        }

        if ($sort === 'members') {
            $mul = $order === 'desc' ? -1 : 1;
            usort($groups, static fn(self $a, self $b) => $mul * (count($a->members) <=> count($b->members)));
        }

        return $groups;
    }

    /**
     * Returns connection groups for many invitees in one query, keyed by invitee ID.
     *
     * @param int[] $inviteeIds
     * @return array<int, self[]>
     */
    public static function forInvitees(array $inviteeIds): array
    {
        global $wpdb;

        $inviteeIds = array_values(array_unique(array_filter(array_map('intval', $inviteeIds))));
        if (empty($inviteeIds)) {
            return [];
        }

        $groupsTable  = DatabaseManager::inviteeConnectionGroupsTable();
        $membersTable = DatabaseManager::inviteeConnectionGroupMembersTable();
        $placeholders = implode(', ', array_fill(0, count($inviteeIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cg.*, cgm.invitee_id AS source_invitee_id
                 FROM {$groupsTable} cg
                 INNER JOIN {$membersTable} cgm ON cgm.group_id = cg.id
                 WHERE cgm.invitee_id IN ({$placeholders})
                 ORDER BY cg.name ASC",
                ...$inviteeIds
            )
        );

        if (empty($rows)) {
            return [];
        }

        // Collect unique group IDs and build initial map.
        $allGroupIds   = array_unique(array_map(static fn(object $r) => (int) $r->id, $rows));
        $membersByGroup = self::loadMembersForGroups($allGroupIds);

        // Group by source invitee.
        $seen       = [];
        $byInvitee  = [];

        foreach ($rows as $row) {
            $inviteeId = (int) $row->source_invitee_id;
            $groupId   = (int) $row->id;

            if (!isset($seen[$inviteeId][$groupId])) {
                $seen[$inviteeId][$groupId] = true;
                $group = self::fromRow($row);
                $group->members = $membersByGroup[$groupId] ?? [];
                $byInvitee[$inviteeId][] = $group;
            }
        }

        return $byInvitee;
    }

    /**
     * Returns all connection groups that contain the given invitee, with members loaded.
     *
     * @param int $inviteeId
     * @return self[]
     */
    public static function forInvitee(int $inviteeId): array
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::inviteeConnectionGroupsTable();
        $membersTable = DatabaseManager::inviteeConnectionGroupMembersTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cg.* FROM {$groupsTable} cg
                 INNER JOIN {$membersTable} cgm ON cgm.group_id = cg.id
                 WHERE cgm.invitee_id = %d
                 ORDER BY cg.name ASC",
                $inviteeId
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

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Creates a new connection group and optionally seeds it with member invitee IDs.
     *
     * @param string $name
     * @param string $type
     * @param int[]  $inviteeIds
     * @return self|null
     */
    public static function create(string $name, array $inviteeIds = []): ?self
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::inviteeConnectionGroupsTable();
        $membersTable = DatabaseManager::inviteeConnectionGroupMembersTable();

        $result = $wpdb->insert($groupsTable, ['name' => $name]);

        if (!$result) {
            return null;
        }

        $groupId = (int) $wpdb->insert_id;

        foreach (array_unique(array_filter(array_map('intval', $inviteeIds))) as $inviteeId) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$membersTable} (group_id, invitee_id) VALUES (%d, %d)",
                    $groupId,
                    $inviteeId
                )
            );
        }

        $created = self::find($groupId);
        if ($created !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_CONNECTION_GROUP, EimChangeEvent::ADDED, $created);
        }
        return $created;
    }

    /**
     * Updates name and type on an existing group.
     *
     * @param int    $id
     * @param string $name
     * @param string $type
     * @return bool
     */
    public static function update(int $id, string $name): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::inviteeConnectionGroupsTable(),
            ['name' => $name],
            ['id' => $id]
        );

        $ok = $result !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_CONNECTION_GROUP, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Deletes a connection group and all its member rows.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $snapshot = self::find($id);

        $wpdb->delete(DatabaseManager::inviteeConnectionGroupMembersTable(), ['group_id' => $id]);
        $result = $wpdb->delete(DatabaseManager::inviteeConnectionGroupsTable(), ['id' => $id]);

        $ok = $result !== false;
        if ($ok && $snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_CONNECTION_GROUP, EimChangeEvent::DELETED, $snapshot);
        }
        return $ok;
    }

    // -------------------------------------------------------------------------
    // Member management
    // -------------------------------------------------------------------------

    /**
     * Adds an invitee to a connection group.
     *
     * @param int    $groupId
     * @param int    $inviteeId
     * @param string $role
     * @return bool
     */
    public static function addMember(int $groupId, int $inviteeId, string $role = ''): bool
    {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO " . DatabaseManager::inviteeConnectionGroupMembersTable() . "
                 (group_id, invitee_id, role) VALUES (%d, %d, %s)",
                $groupId,
                $inviteeId,
                $role
            )
        );

        $ok = $result !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_CONNECTION_GROUP, EimChangeEvent::EDITED, self::find($groupId));
        }
        return $ok;
    }

    /**
     * Removes an invitee from a connection group.
     *
     * @param int $groupId
     * @param int $inviteeId
     * @return bool
     */
    public static function removeMember(int $groupId, int $inviteeId): bool
    {
        global $wpdb;

        $result = $wpdb->delete(
            DatabaseManager::inviteeConnectionGroupMembersTable(),
            ['group_id' => $groupId, 'invitee_id' => $inviteeId]
        );

        $ok = $result !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_CONNECTION_GROUP, EimChangeEvent::EDITED, self::find($groupId));
        }
        return $ok;
    }

    /**
     * Removes an invitee from every connection group they belong to.
     *
     * Called by Invitee::delete() before removing the invitee row.
     *
     * @param int $inviteeId
     * @return void
     */
    public static function removeInviteeFromAllGroups(int $inviteeId): void
    {
        global $wpdb;

        $wpdb->delete(
            DatabaseManager::inviteeConnectionGroupMembersTable(),
            ['invitee_id' => $inviteeId]
        );
    }

    // -------------------------------------------------------------------------
    // Event add-flow helpers
    // -------------------------------------------------------------------------

    /**
     * Returns events each group has been invited to, keyed by group ID.
     *
     * A group "is invited to" an event when at least one of its members appears
     * in eim_event_invitees for that event.
     *
     * @param int[] $groupIds
     * @return array<int, array<int, array{id:int,name:string}>>
     */
    public static function eventsForGroups(array $groupIds): array
    {
        global $wpdb;

        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (empty($groupIds)) {
            return [];
        }

        $membersTable       = DatabaseManager::inviteeConnectionGroupMembersTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $eventsTable        = DatabaseManager::eventsTable();
        $placeholders       = implode(', ', array_fill(0, count($groupIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT cgm.group_id, e.id AS event_id, e.name AS event_name
                 FROM {$membersTable} cgm
                 INNER JOIN {$eventInviteesTable} ei ON ei.invitee_id = cgm.invitee_id
                 INNER JOIN {$eventsTable} e ON e.id = ei.event_id
                 WHERE cgm.group_id IN ({$placeholders})
                 ORDER BY cgm.group_id ASC, e.name ASC",
                ...$groupIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $gid = (int) $row->group_id;
            $eid = (int) $row->event_id;
            if (!isset($grouped[$gid][$eid])) {
                $grouped[$gid][$eid] = ['id' => $eid, 'name' => (string) $row->event_name];
            }
        }

        foreach ($grouped as $gid => $events) {
            $grouped[$gid] = array_values($events);
        }

        return $grouped;
    }

    /**
     * Returns invitees who share a connection group with the given invitee,
     * annotated with whether they are already invited to the event.
     *
     * Used by the AJAX endpoint that populates the "connected invitees" checkboxes
     * on the event edit page after the admin selects a primary invitee.
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return array<int, array{id:int, name:string, email:string, group_name:string, role:string, already_invited:bool}>
     */
    public static function connectedInviteesForEvent(int $inviteeId, int $eventId): array
    {
        global $wpdb;

        $cgroupsTable       = DatabaseManager::inviteeConnectionGroupsTable();
        $cgMembersTable     = DatabaseManager::inviteeConnectionGroupMembersTable();
        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.id, i.first_name, i.last_name, i.email,
                        cg.name  AS group_name,
                        cgm.role AS member_role,
                        EXISTS (
                            SELECT 1 FROM {$eventInviteesTable} ei
                            WHERE ei.invitee_id = i.id AND ei.event_id = %d
                        ) AS already_invited
                 FROM {$cgMembersTable} cgm
                 INNER JOIN {$cgroupsTable}   cg ON cg.id  = cgm.group_id
                 INNER JOIN {$inviteesTable}   i  ON i.id  = cgm.invitee_id
                 WHERE cgm.group_id IN (
                     SELECT group_id FROM {$cgMembersTable} WHERE invitee_id = %d
                 )
                 AND cgm.invitee_id != %d
                 ORDER BY cg.id ASC, i.last_name ASC, i.first_name ASC",
                $eventId,
                $inviteeId,
                $inviteeId
            )
        );

        // De-duplicate: an invitee might share multiple groups with the primary.
        $seen   = [];
        $result = [];

        foreach ($rows ?? [] as $row) {
            $id = (int) $row->id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $result[] = [
                'id'             => $id,
                'name'           => trim($row->first_name . ' ' . $row->last_name),
                'email'          => $row->email,
                'group_name'     => $row->group_name,
                'role'           => $row->member_role,
                'already_invited' => (bool) $row->already_invited,
            ];
        }

        return $result;
    }

    /**
     * Searches invitees not yet members of the given group, for the member picker.
     *
     * @param string $query
     * @param int    $groupId  0 when creating a new group (no exclusion by group).
     * @param int[]  $excludeIds Additional invitee IDs to exclude (already staged in UI).
     * @param int    $limit
     * @return Invitee[]
     */
    public static function searchAvailableMembers(
        string $query,
        int    $groupId,
        array  $excludeIds = [],
        int    $limit = 20
    ): array {
        global $wpdb;

        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $inviteesTable = DatabaseManager::inviteesTable();
        $membersTable  = DatabaseManager::inviteeConnectionGroupMembersTable();
        $like          = '%' . $wpdb->esc_like($query) . '%';

        $excludeClause = '';
        $excludeParams = [];

        if ($groupId > 0) {
            $excludeClause = "AND NOT EXISTS (
                SELECT 1 FROM {$membersTable} cgm
                WHERE cgm.invitee_id = i.id AND cgm.group_id = %d
            )";
            $excludeParams[] = $groupId;
        }

        $extraExclude = '';
        $extraParams  = [];
        if (!empty($excludeIds)) {
            $excludeIds    = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));
            $placeholders  = implode(', ', array_fill(0, count($excludeIds), '%d'));
            $extraExclude  = "AND i.id NOT IN ({$placeholders})";
            $extraParams   = $excludeIds;
        }

        $allParams = array_merge([$like, $like, $like], $excludeParams, $extraParams, [max(1, $limit)]);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.* FROM {$inviteesTable} i
                 WHERE (i.first_name LIKE %s OR i.last_name LIKE %s OR i.email LIKE %s)
                 {$excludeClause}
                 {$extraExclude}
                 ORDER BY i.last_name ASC, i.first_name ASC
                 LIMIT %d",
                ...$allParams
            )
        );

        return array_map(static fn(object $row) => Invitee::fromPublicRow($row), $rows ?? []);
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

    /**
     * Returns the number of invitees in this connection group.
     *
     * Triggers lazy loading of members if they have not been fetched yet.
     *
     * @return int
     */
    public function memberCount(): int
    {
        return count($this->getMembers());
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Loads and returns global connection group members keyed by group ID.
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
        $membersTable  = DatabaseManager::inviteeConnectionGroupMembersTable();
        $placeholders  = implode(', ', array_fill(0, count($groupIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, cgm.group_id AS cg_group_id, cgm.role AS cg_role
                 FROM {$membersTable} cgm
                 INNER JOIN {$inviteesTable} i ON i.id = cgm.invitee_id
                 WHERE cgm.group_id IN ({$placeholders})
                 ORDER BY i.last_name ASC, i.first_name ASC",
                ...$groupIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $groupId = (int) $row->cg_group_id;
            $grouped[$groupId][] = Invitee::fromPublicRow($row);
        }

        return $grouped;
    }

    /**
     * Hydrates a ConnectionGroup instance from a database result row.
     *
     * @param object $row Raw row object returned by $wpdb->get_row() / get_results().
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:        (int) $row->id,
            name:            $row->name,
            createdAt:       $row->created_at ?? '',
            updatedAt:       $row->updated_at ?? '',
        );
    }
}
