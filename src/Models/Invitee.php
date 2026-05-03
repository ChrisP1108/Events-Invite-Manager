<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single invitee and provides static CRUD methods against the database.
 *
 * Invite codes are generated automatically by create() using cryptographically
 * secure random bytes, ensuring global uniqueness across events.
 */
final class Invitee
{
    /**
     * @param int         $id            Primary key.
     * @param int         $eventId       Foreign key to the parent event.
     * @param string      $firstName     First name.
     * @param string      $lastName      Last name.
     * @param string      $email         Email address (stored lowercase).
     * @param string      $streetAddress Street address.
     * @param string      $city          City.
     * @param string      $state         State or province.
     * @param string      $zipCode       ZIP / postal code.
     * @param string      $inviteCode    Unique invite code generated at creation time.
     * @param bool        $isRegistered  Whether the invitee has completed front-end registration.
     * @param string|null $registeredAt  MySQL datetime of when registration was completed, or null.
     * @param string|null $inviteSentAt  MySQL datetime of when the invite email was last sent, or null.
     * @param string      $createdAt     MySQL datetime of row creation.
     * @param string      $updatedAt     MySQL datetime of last update.
     */
    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly string  $email,
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
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $table = DatabaseManager::inviteesTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d ORDER BY last_name ASC, first_name ASC",
                $eventId
            )
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Finds a single invitee by primary key.
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

        $table = DatabaseManager::inviteesTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s AND event_id = %d LIMIT 1",
                strtolower(trim($email)),
                $eventId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds an invitee by their unique invite code.
     *
     * @param string $inviteCode
     * @return self|null
     */
    public static function findByInviteCode(string $inviteCode): ?self
    {
        global $wpdb;

        $table = DatabaseManager::inviteesTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE invite_code = %s LIMIT 1", $inviteCode)
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Inserts a new invitee row, generating a unique invite code automatically.
     *
     * @param array<string, mixed> $data Must contain event_id, first_name, last_name, email.
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
            'street_address' => $data['street_address'] ?? '',
            'city'           => $data['city']           ?? '',
            'state'          => $data['state']          ?? '',
            'zip_code'       => $data['zip_code']       ?? '',
            'invite_code'    => self::generateInviteCode(),
            'is_registered'  => 0,
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Updates mutable fields on an existing invitee row.
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
            'street_address' => $data['street_address'] ?? null,
            'city'           => $data['city']           ?? null,
            'state'          => $data['state']          ?? null,
            'zip_code'       => $data['zip_code']       ?? null,
            'is_registered'  => $data['is_registered']  ?? null,
            'registered_at'  => $data['registered_at']  ?? null,
            'invite_sent_at' => $data['invite_sent_at'] ?? null,
        ], static fn($v) => $v !== null);

        if (empty($fields)) {
            return true;
        }

        $result = $wpdb->update(DatabaseManager::inviteesTable(), $fields, ['id' => $id]);

        return $result !== false;
    }

    /**
     * Marks the invitee as registered and records the current timestamp.
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
     * Records the invite-sent timestamp for the given invitee row.
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
     * Deletes an invitee row by primary key.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

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
     * Hydrates an Invitee instance from a raw database row object.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int)  $row->id,
            eventId:       (int)  $row->event_id,
            firstName:            $row->first_name,
            lastName:             $row->last_name,
            email:                $row->email,
            streetAddress:        $row->street_address  ?? '',
            city:                 $row->city            ?? '',
            state:                $row->state           ?? '',
            zipCode:              $row->zip_code        ?? '',
            inviteCode:           $row->invite_code,
            isRegistered:  (bool) $row->is_registered,
            registeredAt:         $row->registered_at   ?? null,
            inviteSentAt:         $row->invite_sent_at  ?? null,
            createdAt:            $row->created_at      ?? '',
            updatedAt:            $row->updated_at      ?? '',
        );
    }
}
