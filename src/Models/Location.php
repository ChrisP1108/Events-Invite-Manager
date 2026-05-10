<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single entry in the global location catalogue (eim_locations).
 *
 * Locations are not tied to any specific event. Events reference a location
 * for their venue via the venue_id column, and reference lodging options via
 * the eim_event_lodging pivot table.
 *
 * has_lodging marks whether a location offers guest accommodation — only
 * locations with this flag appear in the lodging autocomplete on event forms.
 */
final class Location
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $streetAddress,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly bool   $isOther,
        public readonly bool   $hasLodging,
        public readonly string $bookingUrl,
        public readonly string $createdAt,
    ) {}

    /**
     * Returns locations for the admin list table, optionally filtered by a search string.
     *
     * Searches the name, city, and state columns. Sort column is validated against
     * an allowlist before being interpolated into the query; order is clamped to
     * 'ASC' or 'DESC'. Returns fully-hydrated Location objects ready for rendering.
     *
     * @param string $query  Optional search string; empty string returns all rows.
     * @param string $sort   Column to sort by ('name', 'is_other', 'has_lodging').
     * @param string $order  Sort direction ('asc' or 'desc').
     * @return self[]
     */
    public static function listForAdmin(string $query, string $sort = 'name', string $order = 'asc'): array
    {
        global $wpdb;

        $table    = DatabaseManager::locationsTable();
        $allowed  = ['name', 'is_other', 'has_lodging'];
        $sortCol  = in_array($sort, $allowed, true) ? $sort : 'name';
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE name LIKE %s OR city LIKE %s OR state LIKE %s ORDER BY {$sortCol} {$orderSql}, name ASC",
                $like,
                $like,
                $like
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = "SELECT * FROM {$table} ORDER BY {$sortCol} {$orderSql}, name ASC";
        }

        $rows = $wpdb->get_results($sql);

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns all locations ordered alphabetically by name.
     *
     * @return self[]
     */
    public static function all(): array
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
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
     * Finds the first location whose name exactly matches the given string.
     *
     * @param string $name
     * @return self|null
     */
    public static function findByName(string $name): ?self
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE name = %s LIMIT 1", $name)
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns the total number of locations in the catalogue.
     *
     * @return int
     */
    public static function count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . DatabaseManager::locationsTable());
    }

    /**
     * Returns event usage grouped by location ID for the Locations admin table.
     *
     * A location can be used as an event venue, a lodging option, or both. When the
     * same event uses a location in both ways, the event is returned once with both
     * roles attached.
     *
     * @param int[] $locationIds
     * @return array<int, array<int, array{id: int, name: string, roles: array<int, string>}>>
     */
    public static function eventUsageForLocations(array $locationIds): array
    {
        global $wpdb;

        $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds))));
        if (empty($locationIds)) {
            return [];
        }

        $eventsTable       = DatabaseManager::eventsTable();
        $eventLodgingTable = DatabaseManager::eventLodgingTable();
        $placeholders      = implode(', ', array_fill(0, count($locationIds), '%d'));
        $params            = array_merge($locationIds, $locationIds);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_usage.location_id, event_usage.event_id, event_usage.event_name, event_usage.role
                 FROM (
                    SELECT e.venue_id AS location_id, e.id AS event_id, e.name AS event_name, 'venue' AS role
                    FROM {$eventsTable} e
                    WHERE e.venue_id IN ({$placeholders})
                    UNION ALL
                    SELECT el.location_id, e.id AS event_id, e.name AS event_name, 'lodging' AS role
                    FROM {$eventLodgingTable} el
                    INNER JOIN {$eventsTable} e ON e.id = el.event_id
                    WHERE el.location_id IN ({$placeholders})
                 ) event_usage
                 ORDER BY event_usage.event_name ASC,
                          CASE event_usage.role WHEN 'venue' THEN 0 ELSE 1 END",
                ...$params
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $locationId = (int) $row->location_id;
            $eventId    = (int) $row->event_id;

            if (!isset($grouped[$locationId][$eventId])) {
                $grouped[$locationId][$eventId] = [
                    'id'    => $eventId,
                    'name'  => (string) $row->event_name,
                    'roles' => [],
                ];
            }

            $role = (string) $row->role;
            if (!in_array($role, $grouped[$locationId][$eventId]['roles'], true)) {
                $grouped[$locationId][$eventId]['roles'][] = $role;
            }
        }

        foreach ($grouped as $locationId => $events) {
            $grouped[$locationId] = array_values($events);
        }

        return $grouped;
    }

    /**
     * Searches locations whose name matches the query string.
     *
     * Pass $lodgingOnly = true to restrict results to locations with has_lodging = 1
     * (used by the lodging autocomplete on event forms).
     *
     * Returns plain associative arrays suitable for JSON encoding.
     *
     * @param string $query       Minimum 2 characters.
     * @param int    $limit       Maximum results to return.
     * @param bool   $lodgingOnly When true, only returns lodging-capable locations.
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = 10, bool $lodgingOnly = false): array
    {
        global $wpdb;

        $table = DatabaseManager::locationsTable();
        $like  = '%' . $wpdb->esc_like($query) . '%';

        $sql = $lodgingOnly
            ? $wpdb->prepare(
                "SELECT * FROM {$table} WHERE name LIKE %s AND has_lodging = 1 ORDER BY name ASC, city ASC LIMIT %d",
                $like, $limit
              )
            : $wpdb->prepare(
                "SELECT * FROM {$table} WHERE name LIKE %s ORDER BY name ASC, city ASC LIMIT %d",
                $like, $limit
              );

        $rows = $wpdb->get_results($sql);

        return array_map(static function (object $row): array {
            $isOther = (bool) $row->is_other;
            $address = implode(', ', array_filter([
                $row->street_address,
                $row->city,
                $row->state,
                $row->zip_code,
            ]));

            return [
                'id'             => (int)  $row->id,
                'name'           =>        $row->name,
                'street_address' =>        $row->street_address ?? '',
                'city'           =>        $row->city           ?? '',
                'state'          =>        $row->state          ?? '',
                'zip_code'       =>        $row->zip_code       ?? '',
                'is_other'       => $isOther,
                'has_lodging'    => (bool) ($row->has_lodging   ?? false),
                'booking_url'    =>        $row->booking_url    ?? '',
                'label'          => $isOther
                    ? $row->name . ' (Other)'
                    : ($address ? $row->name . ' — ' . $address : $row->name),
            ];
        }, $rows ?? []);
    }

    /**
     * Inserts a new location and returns its auto-increment ID, or false on failure.
     *
     * @param array<string, mixed> $data Must contain 'name'.
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $isOther = !empty($data['is_other']);

        $result = $wpdb->insert(DatabaseManager::locationsTable(), [
            'name'           => $data['name']           ?? '',
            'street_address' => $isOther ? '' : ($data['street_address'] ?? ''),
            'city'           => $isOther ? '' : ($data['city']           ?? ''),
            'state'          => $isOther ? '' : ($data['state']          ?? ''),
            'zip_code'       => $isOther ? '' : ($data['zip_code']       ?? ''),
            'is_other'       => $isOther ? 1 : 0,
            'has_lodging'    => !empty($data['has_lodging']) ? 1 : 0,
            'booking_url'    => $data['booking_url'] ?? '',
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Updates an existing location. When is_other is true, address fields are cleared.
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
                'has_lodging'    => !empty($data['has_lodging']) ? 1 : 0,
                'booking_url'    => $data['booking_url'] ?? '',
            ],
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Deletes a location by primary key and cleans up all references to it.
     *
     * Events that used this location as their venue have venue_id set to null.
     * Lodging assignments (eim_event_lodging) pointing to this location are removed.
     * Without this cleanup, deleted locations would leave dangling FK references since
     * the database tables use no enforced foreign key constraints.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $wpdb->update(DatabaseManager::eventsTable(), ['venue_id' => null], ['venue_id' => $id]);
        $wpdb->delete(DatabaseManager::eventLodgingTable(), ['location_id' => $id]);

        $result = $wpdb->delete(DatabaseManager::locationsTable(), ['id' => $id]);

        return $result !== false;
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
     * Hydrates a Location instance from a raw database row object.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int)  $row->id,
            name:                 $row->name,
            streetAddress:        $row->street_address ?? '',
            city:                 $row->city           ?? '',
            state:                $row->state          ?? '',
            zipCode:              $row->zip_code       ?? '',
            isOther:       (bool) ($row->is_other      ?? false),
            hasLodging:    (bool) ($row->has_lodging   ?? false),
            bookingUrl:           $row->booking_url    ?? '',
            createdAt:            $row->created_at     ?? '',
        );
    }
}
