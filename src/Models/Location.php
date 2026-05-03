<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single location record and provides static CRUD methods.
 *
 * The type column distinguishes between two uses:
 *   'lodging' — a lodging option presented to invitees (multiple per event, ordered by sort_order).
 *   'venue'   — the event's physical location (at most one per event).
 *
 * The is_other flag is only meaningful for lodging rows and marks a generic
 * "Other" entry (e.g. "Airbnb / Personal Arrangement") that has no fixed address.
 */
final class Location
{
    /**
     * @param int    $id            Primary key.
     * @param int    $eventId       Foreign key to the parent event.
     * @param string $type          Row type: 'lodging' or 'venue'.
     * @param string $name          Location name.
     * @param string $streetAddress Street address — empty for is_other lodging rows.
     * @param string $city          City — empty for is_other lodging rows.
     * @param string $state         State — empty for is_other lodging rows.
     * @param string $zipCode       ZIP code — empty for is_other lodging rows.
     * @param bool   $isOther       True for a generic lodging "Other" option with no fixed address.
     * @param int    $sortOrder     Display order for lodging rows (ascending); defaults to 0.
     * @param string $createdAt     MySQL datetime of row creation.
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $eventId,
        public readonly string $type,
        public readonly string $name,
        public readonly string $streetAddress,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly bool   $isOther,
        public readonly int    $sortOrder,
        public readonly string $createdAt,
    ) {}

    /**
     * Returns all lodging locations for a given event, ordered by sort_order then name.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND type = 'lodging' ORDER BY sort_order ASC, name ASC",
                $eventId
            )
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns the venue location for a given event, or null if none has been set.
     *
     * @param int $eventId
     * @return self|null
     */
    public static function venueForEvent(int $eventId): ?self
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND type = 'venue' LIMIT 1",
                $eventId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds a single location by primary key.
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns the count of lodging locations (type='lodging') for a given event.
     *
     * @param int $eventId
     * @return int
     */
    public static function countForEvent(int $eventId): int
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND type = 'lodging'", $eventId)
        );
    }

    /**
     * Inserts a new location row and returns its auto-increment ID, or false on failure.
     *
     * Defaults type to 'lodging' when not provided. The is_other flag is only
     * honoured for lodging rows — venue rows always store full address fields.
     *
     * @param array<string, mixed> $data Must contain event_id and name.
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $type    = $data['type'] ?? 'lodging';
        $isOther = $type === 'lodging' && !empty($data['is_other']);

        $result = $wpdb->insert(DatabaseManager::locationsTable(), [
            'event_id'       => (int) ($data['event_id'] ?? 0),
            'type'           => $type,
            'name'           => $data['name']           ?? '',
            'street_address' => $isOther ? '' : ($data['street_address'] ?? ''),
            'city'           => $isOther ? '' : ($data['city']           ?? ''),
            'state'          => $isOther ? '' : ($data['state']          ?? ''),
            'zip_code'       => $isOther ? '' : ($data['zip_code']       ?? ''),
            'is_other'       => $isOther ? 1 : 0,
            'sort_order'     => (int) ($data['sort_order'] ?? 0),
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Updates an existing location row.
     *
     * When is_other is true the address fields are cleared so stale address data
     * does not persist on a lodging row that has been switched to the Other type.
     *
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $isOther = !empty($data['is_other']);

        $result = $wpdb->update(
            DatabaseManager::locationsTable(),
            [
                'name'           => $data['name']           ?? '',
                'street_address' => $isOther ? '' : ($data['street_address'] ?? ''),
                'city'           => $isOther ? '' : ($data['city']           ?? ''),
                'state'          => $isOther ? '' : ($data['state']          ?? ''),
                'zip_code'       => $isOther ? '' : ($data['zip_code']       ?? ''),
                'is_other'       => $isOther ? 1 : 0,
                'sort_order'     => (int) ($data['sort_order'] ?? 0),
            ],
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Deletes a location by primary key.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->delete(DatabaseManager::locationsTable(), ['id' => $id]);

        return $result !== false;
    }

    /**
     * Deletes all locations (both lodging and venue) for a given event.
     *
     * Called automatically by Event::delete() to maintain referential integrity.
     *
     * @param int $eventId
     * @return void
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::locationsTable(), ['event_id' => $eventId]);
    }

    /**
     * Returns a formatted single-line address string, or an empty string for Other locations.
     *
     * @return string
     */
    public function formattedAddress(): string
    {
        if ($this->isOther) {
            return '';
        }

        return implode(', ', array_filter([
            $this->streetAddress,
            $this->city,
            $this->state,
            $this->zipCode,
        ]));
    }

    /**
     * Returns the human-readable location type label (for lodging rows).
     *
     * @return string
     */
    public function typeLabel(): string
    {
        return $this->isOther ? 'Other' : 'Specific Location';
    }

    /**
     * Hydrates a Location instance from a raw database row object.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int)  $row->id,
            eventId:       (int)  $row->event_id,
            type:                 $row->type           ?? 'lodging',
            name:                 $row->name,
            streetAddress:        $row->street_address ?? '',
            city:                 $row->city           ?? '',
            state:                $row->state          ?? '',
            zipCode:              $row->zip_code       ?? '',
            isOther:       (bool) $row->is_other,
            sortOrder:     (int)  $row->sort_order,
            createdAt:            $row->created_at     ?? '',
        );
    }
}
