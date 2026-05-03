<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single invitee and provides static CRUD methods against the database.
 *
 * Invitee profile details are global. Event-specific invitation state, including
 * invite codes and registration/send timestamps, lives in the event-invitee pivot
 * table and is hydrated onto instances returned by forEvent(), findForEvent(),
 * findByEmailAndEvent(), and findByInviteCode().
 */
final class Invitee
{
    /**
     * @param int         $id            Primary key.
     * @param int         $eventId       Event ID for an event-specific hydration, or 0 for global profile rows.
     * @param string      $firstName     First name.
     * @param string      $lastName      Last name.
     * @param string      $email         Email address (stored lowercase).
     * @param string      $phone         Phone number.
     * @param string      $streetAddress Street address.
     * @param string      $city          City.
     * @param string      $state         State or province.
     * @param string      $zipCode       ZIP / postal code.
     * @param string      $inviteCode    Event-specific invite code when hydrated for an event.
     * @param bool        $isRegistered  Whether the invitee has registered for the hydrated event.
     * @param string|null $registeredAt  MySQL datetime of when event registration was completed, or null.
     * @param string|null $inviteSentAt  MySQL datetime of when the event invite email was last sent, or null.
     * @param string      $createdAt     MySQL datetime of row creation.
     * @param string      $updatedAt     MySQL datetime of last update.
     */
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
        public readonly string  $inviteCode,
        public readonly bool    $isRegistered,
        public readonly ?string $registeredAt,
        public readonly ?string $inviteSentAt,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    /**
     * Returns all invitees for the given event, ordered by last name then first name.
     *
     * Event-specific invitation fields are read from the pivot table.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id AS invitation_event_id,
                        ei.invite_code AS invitation_invite_code,
                        ei.is_registered AS invitation_is_registered,
                        ei.registered_at AS invitation_registered_at,
                        ei.invite_sent_at AS invitation_invite_sent_at
                 FROM {$eventInviteesTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 WHERE ei.event_id = %d
                 ORDER BY i.last_name ASC, i.first_name ASC",
                $eventId
            )
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Finds a single invitee by primary key.
     *
     * Returns the global invitee profile without event-specific invitation state.
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::inviteesTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds an invitee by primary key for a specific event invitation.
     *
     * @param int $id
     * @param int $eventId
     * @return self|null
     */
    public static function findForEvent(int $id, int $eventId): ?self
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id AS invitation_event_id,
                        ei.invite_code AS invitation_invite_code,
                        ei.is_registered AS invitation_is_registered,
                        ei.registered_at AS invitation_registered_at,
                        ei.invite_sent_at AS invitation_invite_sent_at
                 FROM {$eventInviteesTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 WHERE ei.invitee_id = %d AND ei.event_id = %d
                 LIMIT 1",
                $id,
                $eventId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds an invitee by email address and event ID.
     *
     * Used by the REST API to look up the invitee during the registration flow.
     *
     * @param string $email
     * @param int    $eventId
     * @return self|null
     */
    public static function findByEmailAndEvent(string $email, int $eventId): ?self
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id AS invitation_event_id,
                        ei.invite_code AS invitation_invite_code,
                        ei.is_registered AS invitation_is_registered,
                        ei.registered_at AS invitation_registered_at,
                        ei.invite_sent_at AS invitation_invite_sent_at
                 FROM {$eventInviteesTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 WHERE i.email = %s AND ei.event_id = %d
                 ORDER BY i.id ASC
                 LIMIT 1",
                strtolower(trim($email)),
                $eventId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds an invitee by their unique event invitation code.
     *
     * @param string $inviteCode
     * @return self|null
     */
    public static function findByInviteCode(string $inviteCode): ?self
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id AS invitation_event_id,
                        ei.invite_code AS invitation_invite_code,
                        ei.is_registered AS invitation_is_registered,
                        ei.registered_at AS invitation_registered_at,
                        ei.invite_sent_at AS invitation_invite_sent_at
                 FROM {$eventInviteesTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 WHERE ei.invite_code = %s
                 LIMIT 1",
                $inviteCode
            )
        );

        if ($row) {
            return self::fromRow($row);
        }

        // Backward-compatible fallback for invite codes stored on legacy rows.
        $eventsTable = DatabaseManager::eventsTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*
                 FROM {$inviteesTable} i
                 INNER JOIN {$eventsTable} e ON e.id = i.event_id
                 WHERE i.invite_code = %s
                 LIMIT 1",
                $inviteCode
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns invitees with their invited events for the global admin list.
     *
     * @param string $search  Optional search query.
     * @param string $orderBy Sort key: first_name, last_name, email, phone, or events.
     * @param string $order   Sort direction: asc or desc.
     * @return array<int, array{invitee: self, events: array<int, array{id: int, name: string}>}>
     */
    public static function listForAdmin(string $search = '', string $orderBy = 'last_name', string $order = 'asc'): array
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventsTable        = DatabaseManager::eventsTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();

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
            $like   = '%' . $wpdb->esc_like($search) . '%';
            $where  = "WHERE (
                i.first_name LIKE %s
                OR i.last_name LIKE %s
                OR i.email LIKE %s
                OR i.phone LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM {$eventInviteesTable} ei_search
                    INNER JOIN {$eventsTable} e_search ON e_search.id = ei_search.event_id
                    WHERE ei_search.invitee_id = i.id
                      AND e_search.name LIKE %s
                )
            )";
            $params = [$like, $like, $like, $like, $like];
        }

        $sql = "SELECT i.*
                FROM {$inviteesTable} i
                {$where}
                ORDER BY {$sortSql} {$dirSql}, i.last_name ASC, i.first_name ASC, i.id ASC";

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $invitees = array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
        $eventsByInvitee = self::eventsForInvitees(array_map(static fn(self $invitee) => $invitee->id, $invitees));

        return array_map(
            static fn(self $invitee): array => [
                'invitee' => $invitee,
                'events'  => $eventsByInvitee[$invitee->id] ?? [],
            ],
            $invitees
        );
    }

    /**
     * Searches global invitees that are not yet invited to the given event.
     *
     * Used by the event edit screen's AJAX-powered invitee picker.
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
                 )
                 AND NOT EXISTS (
                    SELECT 1
                    FROM {$eventInviteesTable} ei
                    WHERE ei.invitee_id = i.id AND ei.event_id = %d
                 )
                 ORDER BY i.last_name ASC, i.first_name ASC
                 LIMIT %d",
                $like,
                $like,
                $like,
                $like,
                $eventId,
                max(1, $limit)
            )
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns the events an invitee has been invited to, ordered by event name.
     *
     * @param int $inviteeId
     * @return array<int, array{id: int, name: string}>
     */
    public static function eventsForInvitee(int $inviteeId): array
    {
        $grouped = self::eventsForInvitees([$inviteeId]);

        return $grouped[$inviteeId] ?? [];
    }

    /**
     * Inserts a new global invitee row, generating a legacy invite code for compatibility.
     *
     * @param array<string, mixed> $data Must contain first_name, last_name, email.
     *                                   All other fields are optional.
     * @return int|false The new row ID, or false on database failure.
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::inviteesTable(), [
            'event_id'       => (int) ($data['event_id'] ?? 0),
            'first_name'     => $data['first_name']     ?? '',
            'last_name'      => $data['last_name']      ?? '',
            'email'          => strtolower(trim($data['email'] ?? '')),
            'phone'          => $data['phone']          ?? '',
            'street_address' => $data['street_address'] ?? '',
            'city'           => $data['city']           ?? '',
            'state'          => $data['state']          ?? '',
            'zip_code'       => $data['zip_code']       ?? '',
            'invite_code'    => self::generateInviteCode(),
            'is_registered'  => 0,
        ]);

        $id = $result ? (int) $wpdb->insert_id : false;

        if ($id && (int) ($data['event_id'] ?? 0) > 0) {
            self::inviteToEvent($id, (int) $data['event_id']);
        }

        return $id;
    }

    /**
     * Updates mutable fields on an existing invitee profile row.
     *
     * Only keys present in $data are overwritten; omitted keys are unchanged.
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
     * Invites an existing invitee to an event.
     *
     * A unique event-specific invite code is generated automatically. Existing
     * event-invitee associations are treated as a successful no-op.
     *
     * @param int         $inviteeId
     * @param int         $eventId
     * @param string|null $inviteCode Optional code used during legacy migration.
     * @return bool
     */
    public static function inviteToEvent(int $inviteeId, int $eventId, ?string $inviteCode = null): bool
    {
        global $wpdb;

        if ($inviteeId <= 0 || $eventId <= 0) {
            return false;
        }

        $table = DatabaseManager::eventInviteesTable();
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

        $result = $wpdb->insert($table, [
            'event_id'    => $eventId,
            'invitee_id'  => $inviteeId,
            'invite_code' => $inviteCode ?: self::generateInviteCode(),
        ]);

        return $result !== false;
    }

    /**
     * Removes an invitee from an event without deleting the global invitee profile.
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function removeFromEvent(int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        $result = $wpdb->delete(DatabaseManager::eventInviteesTable(), [
            'event_id'   => $eventId,
            'invitee_id' => $inviteeId,
        ]);

        return $result !== false;
    }

    /**
     * Marks the invitee as registered for a specific event and records the timestamp.
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function markRegisteredForEvent(int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::eventInviteesTable(),
            ['is_registered' => 1, 'registered_at' => current_time('mysql')],
            ['invitee_id' => $inviteeId, 'event_id' => $eventId]
        );

        return $result !== false;
    }

    /**
     * Marks the invitee as registered on the legacy invitee row.
     *
     * @param int $id
     * @return bool
     */
    public static function markRegistered(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::inviteesTable(),
            ['is_registered' => 1, 'registered_at' => current_time('mysql')],
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Records the event invite-sent timestamp for the given invitee.
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function markInviteSentForEvent(int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::eventInviteesTable(),
            ['invite_sent_at' => current_time('mysql')],
            ['invitee_id' => $inviteeId, 'event_id' => $eventId]
        );

        return $result !== false;
    }

    /**
     * Records the invite-sent timestamp for the legacy invitee row.
     *
     * @param int $id
     * @return bool
     */
    public static function markInviteSent(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::inviteesTable(),
            ['invite_sent_at' => current_time('mysql')],
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Deletes an invitee profile and all event invitation associations.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $wpdb->delete(DatabaseManager::eventInviteesTable(), ['invitee_id' => $id]);
        $result = $wpdb->delete(DatabaseManager::inviteesTable(), ['id' => $id]);

        return $result !== false;
    }

    /**
     * Returns the invitee's full name as "First Last".
     *
     * @return string
     */
    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * Returns a formatted mailing address string, omitting empty components.
     *
     * @return string
     */
    public function formattedAddress(): string
    {
        return implode(', ', array_filter([
            $this->streetAddress,
            $this->city,
            $this->state,
            $this->zipCode,
        ]));
    }

    /**
     * Generates a cryptographically secure, URL-safe invite code (32 hex characters).
     *
     * @return string
     */
    public static function generateInviteCode(): string
    {
        return bin2hex(random_bytes(16));
    }

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
     * Hydrates an Invitee instance from a raw database row object.
     *
     * Invitation aliases are preferred when present so event-specific rows expose
     * the correct invite code and registration/send state.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int)  $row->id,
            eventId:       (int)  ($row->invitation_event_id ?? $row->event_id ?? 0),
            firstName:            $row->first_name,
            lastName:             $row->last_name,
            email:                $row->email,
            phone:                $row->phone           ?? '',
            streetAddress:        $row->street_address  ?? '',
            city:                 $row->city            ?? '',
            state:                $row->state           ?? '',
            zipCode:              $row->zip_code        ?? '',
            inviteCode:           $row->invitation_invite_code ?? $row->invite_code ?? '',
            isRegistered:  (bool) ($row->invitation_is_registered ?? $row->is_registered ?? false),
            registeredAt:         $row->invitation_registered_at ?? $row->registered_at ?? null,
            inviteSentAt:         $row->invitation_invite_sent_at ?? $row->invite_sent_at ?? null,
            createdAt:            $row->created_at      ?? '',
            updatedAt:            $row->updated_at      ?? '',
        );
    }
}
