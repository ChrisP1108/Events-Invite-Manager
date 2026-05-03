<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a location in the global library and provides static CRUD methods.
 *
 * Library locations are not tied to any specific event — they serve as a shared
 * catalogue that admins select from when assigning venue and lodging locations to events.
 */
final class LocationLibrary
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $streetAddress,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly bool   $isOther,
        public readonly string $createdAt,
    ) {}

    /**
     * Finds the first library location whose name exactly matches the given string.
     *
     * Used to pre-populate the venue_library_id hidden field when rendering the
     * event edit form so that an already-saved venue doesn't fail library validation.
     *
     * @param string $name
     * @return self|null
     */
    public static function findByName(string $name): ?self
    {
        global $wpdb;

        $table = DatabaseManager::locationLibraryTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE name = %s LIMIT 1", $name)
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns the total number of library locations.
     *
     * @return int
     */
    public static function count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . DatabaseManager::locationLibraryTable());
    }

    /**
     * Returns all library locations ordered alphabetically by name.
     *
     * @return self[]
     */
    public static function all(): array
    {
        global $wpdb;

        $table = DatabaseManager::locationLibraryTable();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Finds a single library location by primary key.
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::locationLibraryTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Searches for library locations whose name matches the given query string.
     *
     * Returns an array of plain associative arrays suitable for JSON encoding.
     *
     * @param string $query Minimum 2 characters.
     * @param int    $limit Maximum number of results.
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = 10): array
    {
        global $wpdb;

        $table = DatabaseManager::locationLibraryTable();
        $like  = '%' . $wpdb->esc_like($query) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE name LIKE %s ORDER BY name ASC, city ASC LIMIT %d",
                $like,
                $limit
            )
        );

        return array_map(static function (object $row): array {
            $isOther = (bool) $row->is_other;
            $address = implode(', ', array_filter([
                $row->street_address,
                $row->city,
                $row->state,
                $row->zip_code,
            ]));

            return [
                'id'             => (int) $row->id,
                'name'           => $row->name,
                'street_address' => $row->street_address ?? '',
                'city'           => $row->city           ?? '',
                'state'          => $row->state          ?? '',
                'zip_code'       => $row->zip_code       ?? '',
                'is_other'       => $isOther,
                'label'          => $isOther
                    ? $row->name . ' (Other)'
                    : ($address ? $row->name . ' — ' . $address : $row->name),
            ];
        }, $rows ?? []);
    }

    /**
     * Inserts a new library location and returns its auto-increment ID, or false on failure.
     *
     * @param array<string, mixed> $data Must contain 'name'.
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $isOther = !empty($data['is_other']);

        $result = $wpdb->insert(DatabaseManager::locationLibraryTable(), [
            'name'           => $data['name']           ?? '',
            'street_address' => $isOther ? '' : ($data['street_address'] ?? ''),
            'city'           => $isOther ? '' : ($data['city']           ?? ''),
            'state'          => $isOther ? '' : ($data['state']          ?? ''),
            'zip_code'       => $isOther ? '' : ($data['zip_code']       ?? ''),
            'is_other'       => $isOther ? 1 : 0,
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Updates an existing library location.
     *
     * When is_other is true, address fields are cleared so no stale address data persists.
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
            DatabaseManager::locationLibraryTable(),
            [
                'name'           => $data['name']           ?? '',
                'street_address' => $isOther ? '' : ($data['street_address'] ?? ''),
                'city'           => $isOther ? '' : ($data['city']           ?? ''),
                'state'          => $isOther ? '' : ($data['state']          ?? ''),
                'zip_code'       => $isOther ? '' : ($data['zip_code']       ?? ''),
                'is_other'       => $isOther ? 1 : 0,
            ],
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Deletes a library location by primary key.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->delete(DatabaseManager::locationLibraryTable(), ['id' => $id]);

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
     * Hydrates a LocationLibrary instance from a raw database row object.
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
            isOther:       (bool) $row->is_other,
            createdAt:            $row->created_at     ?? '',
        );
    }
}
