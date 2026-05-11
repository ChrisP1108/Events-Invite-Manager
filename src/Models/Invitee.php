<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single invitee and provides static CRUD methods against the database.
 *
 * Invitee profile details are global. Event-specific invitation state (RSVP status,
 * invite-sent timestamp, group membership) is hydrated onto instances returned by
 * forEvent(), findForEvent(), and findByEmailAndEvent() via JOINs through the
 * eim_event_invitation_groups and eim_event_invitation_group_members tables.
 */
final class Invitee
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly string  $email,
        public readonly string  $phone,
        public readonly string  $streetAddress,
        public readonly string  $city,
        public readonly string  $state,
        public readonly string  $zipCode,
        /** RSVP status for event context: 'pending' | 'attending' | 'declined' | '' for global. */
        public readonly string  $rsvpStatus,
        /** True when rsvpStatus === 'attending'. Kept for template/backward-compat reads. */
        public readonly bool    $isRegistered,
        public readonly ?string $registeredAt,
        public readonly ?string $inviteSentAt,
        public readonly ?int    $groupId,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // Event-scoped finders (hydrated with RSVP state from invitation groups)
    // -------------------------------------------------------------------------

    /**
     * Returns all invitees for the given event ordered by last name then first name.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $inviteesTable = DatabaseManager::inviteesTable();
        $eiTable       = DatabaseManager::eventInviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id            AS invitation_event_id,
                        gd.group_id            AS invitation_group_id,
                        gd.invite_sent_at      AS invitation_invite_sent_at,
                        COALESCE(gd.rsvp_status, 'pending') AS invitation_rsvp_status,
                        gd.registered_at       AS invitation_registered_at
                 FROM {$eiTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 LEFT JOIN (
                     SELECT egm.invitee_id, egm.group_id, egm.rsvp_status,
                            egm.registered_at, eig.invite_sent_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                     WHERE eig.event_id = %d
                 ) gd ON gd.invitee_id = ei.invitee_id
                 WHERE ei.event_id = %d
                 ORDER BY i.last_name ASC, i.first_name ASC",
                $eventId,
                $eventId
            )
        );

        return array_map(static fn(object $row) => self::fromEventRow($row), $rows ?? []);
    }

    /**
     * Finds a single invitee by primary key (global profile, no event context).
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::inviteesTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromPublicRow($row) : null;
    }

    /**
     * Finds an invitee by primary key with event-specific invitation state hydrated.
     *
     * @param int $id
     * @param int $eventId
     * @return self|null
     */
    public static function findForEvent(int $id, int $eventId): ?self
    {
        global $wpdb;

        $inviteesTable = DatabaseManager::inviteesTable();
        $eiTable       = DatabaseManager::eventInviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id            AS invitation_event_id,
                        gd.group_id            AS invitation_group_id,
                        gd.invite_sent_at      AS invitation_invite_sent_at,
                        COALESCE(gd.rsvp_status, 'pending') AS invitation_rsvp_status,
                        gd.registered_at       AS invitation_registered_at
                 FROM {$eiTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 LEFT JOIN (
                     SELECT egm.invitee_id, egm.group_id, egm.rsvp_status,
                            egm.registered_at, eig.invite_sent_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                     WHERE eig.event_id = %d
                 ) gd ON gd.invitee_id = ei.invitee_id
                 WHERE ei.invitee_id = %d AND ei.event_id = %d
                 LIMIT 1",
                $eventId,
                $id,
                $eventId
            )
        );

        return $row ? self::fromEventRow($row) : null;
    }

    /**
     * Finds an invitee by email address and event ID with event context hydrated.
     *
     * @param string $email
     * @param int    $eventId
     * @return self|null
     */
    public static function findByEmailAndEvent(string $email, int $eventId): ?self
    {
        global $wpdb;

        $inviteesTable = DatabaseManager::inviteesTable();
        $eiTable       = DatabaseManager::eventInviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id            AS invitation_event_id,
                        gd.group_id            AS invitation_group_id,
                        gd.invite_sent_at      AS invitation_invite_sent_at,
                        COALESCE(gd.rsvp_status, 'pending') AS invitation_rsvp_status,
                        gd.registered_at       AS invitation_registered_at
                 FROM {$eiTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 LEFT JOIN (
                     SELECT egm.invitee_id, egm.group_id, egm.rsvp_status,
                            egm.registered_at, eig.invite_sent_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                     WHERE eig.event_id = %d
                 ) gd ON gd.invitee_id = ei.invitee_id
                 WHERE i.email = %s AND ei.event_id = %d
                 ORDER BY i.id ASC
                 LIMIT 1",
                $eventId,
                strtolower(trim($email)),
                $eventId
            )
        );

        return $row ? self::fromEventRow($row) : null;
    }

    // -------------------------------------------------------------------------
    // Admin list
    // -------------------------------------------------------------------------

    /**
     * Returns invitees with their invited events for the global admin list.
     *
     * Search covers first/last name, email, phone, invited event names, and names
     * of people who share a connection group with the invitee.
     *
     * @param string $search
     * @param string $orderBy  first_name | last_name | email | phone | events
     * @param string $order    asc | desc
     * @return array<int, array{invitee: self, events: array<int, array{id: int, name: string}>}>
     */
    public static function listForAdmin(string $search = '', string $orderBy = 'last_name', string $order = 'asc'): array
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventsTable        = DatabaseManager::eventsTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $cgMembersTable     = DatabaseManager::inviteeConnectionGroupMembersTable();

        $eventSortSql = "(SELECT GROUP_CONCAT(e_sort.name ORDER BY e_sort.name ASC SEPARATOR ', ')
                          FROM {$eventInviteesTable} ei_sort
                          INNER JOIN {$eventsTable} e_sort ON e_sort.id = ei_sort.event_id
                          WHERE ei_sort.invitee_id = i.id)";

        $sortColumns = [
            'first_name' => 'i.first_name',
            'last_name'  => 'i.last_name',
            'email'      => 'i.email',
            'phone'      => 'i.phone',
            'events'     => $eventSortSql,
        ];

        $sortSql = $sortColumns[$orderBy] ?? $sortColumns['last_name'];
        $dirSql  = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $params  = [];
        $where   = '';
        $search  = trim($search);

        if ($search !== '') {
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $where = "WHERE (
                i.first_name LIKE %s
                OR i.last_name LIKE %s
                OR i.email LIKE %s
                OR i.phone LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM {$eventInviteesTable} ei_s
                    INNER JOIN {$eventsTable} e_s ON e_s.id = ei_s.event_id
                    WHERE ei_s.invitee_id = i.id AND e_s.name LIKE %s
                )
                OR EXISTS (
                    SELECT 1
                    FROM {$cgMembersTable} cgm1
                    INNER JOIN {$cgMembersTable} cgm2 ON cgm2.group_id = cgm1.group_id
                    INNER JOIN {$inviteesTable} ci ON ci.id = cgm1.invitee_id
                    WHERE cgm2.invitee_id = i.id
                      AND cgm1.invitee_id != i.id
                      AND (ci.first_name LIKE %s OR ci.last_name LIKE %s)
                )
            )";
            $params = [$like, $like, $like, $like, $like, $like, $like];
        }

        $sql = "SELECT i.*
                FROM {$inviteesTable} i
                {$where}
                ORDER BY {$sortSql} {$dirSql}, i.last_name ASC, i.first_name ASC, i.id ASC";

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $invitees        = array_map(static fn(object $row) => self::fromPublicRow($row), $rows ?? []);
        $inviteeIds      = array_map(static fn(self $inv) => $inv->id, $invitees);
        $eventsByInvitee = self::eventsForInvitees($inviteeIds);

        return array_map(
            static fn(self $invitee): array => [
                'invitee' => $invitee,
                'events'  => $eventsByInvitee[$invitee->id] ?? [],
            ],
            $invitees
        );
    }

    /**
     * Searches global invitees not yet invited to the given event.
     *
     * Also matches names of people in the same connection group.
     *
     * @param string $search
     * @param int    $eventId
     * @param int    $limit
     * @return self[]
     */
    public static function searchAvailableForEvent(string $search, int $eventId, int $limit = 20): array
    {
        global $wpdb;

        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $cgMembersTable     = DatabaseManager::inviteeConnectionGroupMembersTable();
        $like = '%' . $wpdb->esc_like($search) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*
                 FROM {$inviteesTable} i
                 WHERE (
                    i.first_name LIKE %s
                    OR i.last_name LIKE %s
                    OR i.email LIKE %s
                    OR i.phone LIKE %s
                    OR EXISTS (
                        SELECT 1
                        FROM {$cgMembersTable} cgm1
                        INNER JOIN {$cgMembersTable} cgm2 ON cgm2.group_id = cgm1.group_id
                        INNER JOIN {$inviteesTable} ci ON ci.id = cgm1.invitee_id
                        WHERE cgm2.invitee_id = i.id
                          AND cgm1.invitee_id != i.id
                          AND (ci.first_name LIKE %s OR ci.last_name LIKE %s)
                    )
                 )
                 AND NOT EXISTS (
                    SELECT 1 FROM {$eventInviteesTable} ei
                    WHERE ei.invitee_id = i.id AND ei.event_id = %d
                 )
                 ORDER BY i.last_name ASC, i.first_name ASC
                 LIMIT %d",
                $like, $like, $like, $like, $like, $like,
                $eventId,
                max(1, $limit)
            )
        );

        return array_map(static fn(object $row) => self::fromPublicRow($row), $rows ?? []);
    }

    /**
     * Returns the events an invitee has been invited to, ordered by event name.
     *
     * @param int $inviteeId
     * @return array<int, array{id: int, name: string}>
     */
    public static function eventsForInvitee(int $inviteeId): array
    {
        return self::eventsForInvitees([$inviteeId])[$inviteeId] ?? [];
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Inserts a new global invitee profile row.
     *
     * @param array<string, mixed> $data Must contain first_name, last_name, email.
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::inviteesTable(), [
            'first_name'     => $data['first_name']     ?? '',
            'last_name'      => $data['last_name']      ?? '',
            'email'          => strtolower(trim($data['email'] ?? '')),
            'phone'          => $data['phone']          ?? '',
            'street_address' => $data['street_address'] ?? '',
            'city'           => $data['city']           ?? '',
            'state'          => $data['state']          ?? '',
            'zip_code'       => $data['zip_code']       ?? '',
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Updates mutable fields on an existing invitee profile row.
     *
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = array_filter([
            'first_name'     => $data['first_name']     ?? null,
            'last_name'      => $data['last_name']      ?? null,
            'email'          => isset($data['email']) ? strtolower(trim($data['email'])) : null,
            'phone'          => $data['phone']          ?? null,
            'street_address' => $data['street_address'] ?? null,
            'city'           => $data['city']           ?? null,
            'state'          => $data['state']          ?? null,
            'zip_code'       => $data['zip_code']       ?? null,
        ], static fn($v) => $v !== null);

        if (empty($fields)) {
            return true;
        }

        $result = $wpdb->update(DatabaseManager::inviteesTable(), $fields, ['id' => $id]);

        return $result !== false;
    }

    /**
     * Adds an existing invitee to an event's membership table.
     *
     * Invitation group creation is handled separately by InvitationGroup::create().
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function addToEvent(int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        if ($inviteeId <= 0 || $eventId <= 0) {
            return false;
        }

        $table  = DatabaseManager::eventInviteesTable();
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND invitee_id = %d",
                $eventId,
                $inviteeId
            )
        );

        if ($exists > 0) {
            return true;
        }

        return $wpdb->insert($table, ['event_id' => $eventId, 'invitee_id' => $inviteeId]) !== false;
    }

    /**
     * Deletes an invitee profile, removes them from all groups, and cleans up event data.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        ConnectionGroup::removeInviteeFromAllGroups($id);
        InvitationGroup::removeInviteeFromAllGroups($id);
        $wpdb->delete(DatabaseManager::eventInviteesTable(), ['invitee_id' => $id]);
        $result = $wpdb->delete(DatabaseManager::inviteesTable(), ['id' => $id]);

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Instance helpers
    // -------------------------------------------------------------------------

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function formattedAddress(): string
    {
        return implode(', ', array_filter([
            $this->streetAddress,
            $this->city,
            $this->state,
            $this->zipCode,
        ]));
    }

    // -------------------------------------------------------------------------
    // Row hydration (public so InvitationGroup and ConnectionGroup can use it)
    // -------------------------------------------------------------------------

    /**
     * Hydrates an Invitee from a raw DB row, optionally including invitation_* aliases.
     *
     * Used by InvitationGroup::loadMembersForGroups() and ConnectionGroup::loadMembersForGroups().
     *
     * @param object $row
     * @return self
     */
    public static function fromPublicRow(object $row): self
    {
        $rsvpStatus = $row->invitation_rsvp_status ?? '';

        return new self(
            id:           (int)  $row->id,
            eventId:      (int)  ($row->invitation_event_id ?? 0),
            firstName:           $row->first_name,
            lastName:            $row->last_name,
            email:               $row->email,
            phone:               $row->phone           ?? '',
            streetAddress:       $row->street_address  ?? '',
            city:                $row->city            ?? '',
            state:               $row->state           ?? '',
            zipCode:             $row->zip_code        ?? '',
            rsvpStatus:          $rsvpStatus,
            isRegistered:        $rsvpStatus === InvitationGroup::RSVP_ATTENDING,
            registeredAt:        $row->invitation_registered_at  ?? $row->registered_at  ?? null,
            inviteSentAt:        $row->invitation_invite_sent_at ?? null,
            groupId:      isset($row->invitation_group_id) ? (int) $row->invitation_group_id : null,
            createdAt:           $row->created_at      ?? '',
            updatedAt:           $row->updated_at      ?? '',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns invited events grouped by invitee ID.
     *
     * @param int[] $inviteeIds
     * @return array<int, array<int, array{id: int, name: string}>>
     */
    private static function eventsForInvitees(array $inviteeIds): array
    {
        global $wpdb;

        $inviteeIds = array_values(array_unique(array_filter(array_map('intval', $inviteeIds))));
        if (empty($inviteeIds)) {
            return [];
        }

        $eventsTable        = DatabaseManager::eventsTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $placeholders = implode(', ', array_fill(0, count($inviteeIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ei.invitee_id, e.id, e.name
                 FROM {$eventInviteesTable} ei
                 INNER JOIN {$eventsTable} e ON e.id = ei.event_id
                 WHERE ei.invitee_id IN ({$placeholders})
                 ORDER BY e.name ASC",
                ...$inviteeIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $grouped[(int) $row->invitee_id][] = [
                'id'   => (int) $row->id,
                'name' => (string) $row->name,
            ];
        }

        return $grouped;
    }

    /**
     * Hydrates an Invitee from a row that includes invitation_* alias columns.
     *
     * @param object $row
     * @return self
     */
    private static function fromEventRow(object $row): self
    {
        $rsvpStatus = $row->invitation_rsvp_status ?? InvitationGroup::RSVP_PENDING;

        return new self(
            id:           (int)  $row->id,
            eventId:      (int)  ($row->invitation_event_id ?? 0),
            firstName:           $row->first_name,
            lastName:            $row->last_name,
            email:               $row->email,
            phone:               $row->phone           ?? '',
            streetAddress:       $row->street_address  ?? '',
            city:                $row->city            ?? '',
            state:               $row->state           ?? '',
            zipCode:             $row->zip_code        ?? '',
            rsvpStatus:          $rsvpStatus,
            isRegistered:        $rsvpStatus === InvitationGroup::RSVP_ATTENDING,
            registeredAt:        $row->invitation_registered_at ?? null,
            inviteSentAt:        $row->invitation_invite_sent_at ?? null,
            groupId:      isset($row->invitation_group_id) ? (int) $row->invitation_group_id : null,
            createdAt:           $row->created_at ?? '',
            updatedAt:           $row->updated_at ?? '',
        );
    }
}
