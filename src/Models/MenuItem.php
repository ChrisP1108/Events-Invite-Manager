<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a global food or beverage menu item.
 *
 * Items live in a shared library and are assigned to individual events via the
 * eim_event_menu_items pivot table.  Invitee selections (food_option_id /
 * beverage_option_id on eim_event_invitation_group_members) reference this
 * table's primary key.
 */
class MenuItem
{
    /** @var string Type constant for food items. */
    public const TYPE_FOOD     = 'food';

    /** @var string Type constant for beverage items. */
    public const TYPE_BEVERAGE = 'beverage';

    public function __construct(
        public readonly int    $id,
        public readonly string $type,
        public readonly string $label,
        public readonly string $description,
        public readonly int    $priceCents,
        public readonly ?int   $vendorId,
        public readonly int    $sortOrder,
        public readonly bool   $isActive,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Returns a formatted dollar-string for the item price, or an empty string
     * when the price is zero.
     *
     * @return string e.g. "$12.50" or "".
     */
    public function formattedPrice(): string
    {
        if ($this->priceCents === 0) {
            return '';
        }
        return '$' . number_format($this->priceCents / 100, 2);
    }

    // -------------------------------------------------------------------------
    // Global library — CRUD
    // -------------------------------------------------------------------------

    /**
     * Finds a single menu item by primary key.
     *
     * @param int $id Primary key of the menu item.
     * @return self|null The menu item, or null if not found.
     */
    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::menuItemsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns all items of the given type for the admin list, optionally
     * filtered by a search string and restricted to one column.
     *
     * @return self[]
     */
    public static function listByType(
        string $type,
        string $search = '',
        string $sort   = 'label',
        string $order  = 'asc',
        string $field  = ''
    ): array {
        global $wpdb;

        $table    = DatabaseManager::menuItemsTable();
        $type     = $type === self::TYPE_BEVERAGE ? self::TYPE_BEVERAGE : self::TYPE_FOOD;
        $sortCol  = $sort === 'description' ? 'description' : 'label';
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $orderBy  = "ORDER BY {$sortCol} {$orderSql}, label ASC, id ASC";

        if ($search === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE type = %s {$orderBy}", $type)
            );
            return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        }

        $like = '%' . $wpdb->esc_like(strtolower($search)) . '%';

        switch ($field) {
            case 'label':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sql = $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE type = %s AND LOWER(label) LIKE %s {$orderBy}",
                    $type, $like
                );
                break;
            case 'description':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sql = $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE type = %s AND LOWER(description) LIKE %s {$orderBy}",
                    $type, $like
                );
                break;
            default:
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sql = $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE type = %s AND (LOWER(label) LIKE %s OR LOWER(description) LIKE %s)
                     {$orderBy}",
                    $type, $like, $like
                );
        }

        $rows = $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Autocomplete search — returns active items of the given type whose label
     * contains the query string.  Used by the event-edit menu item picker.
     *
     * @return self[]
     */
    public static function search(string $query, string $type, int $limit = 20): array
    {
        global $wpdb;

        $query = trim($query);
        if (mb_strlen($query) < 1) {
            return [];
        }

        $table = DatabaseManager::menuItemsTable();
        $type  = $type === self::TYPE_BEVERAGE ? self::TYPE_BEVERAGE : self::TYPE_FOOD;
        $like  = '%' . $wpdb->esc_like(strtolower($query)) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE type = %s AND is_active = 1
                   AND (LOWER(label) LIKE %s OR LOWER(description) LIKE %s)
                 ORDER BY label ASC
                 LIMIT %d",
                $type, $like, $like, $limit
            )
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Creates a new menu item in the global library.
     *
     * Accepted keys in $data: type, label, description, price_cents, is_active.
     * Defaults: type = TYPE_FOOD, is_active = 1.
     *
     * @param array<string,mixed> $data Column values for the new row.
     * @return self|null The newly created item, or null on failure.
     */
    public static function create(array $data): ?self
    {
        global $wpdb;

        $type = in_array($data['type'] ?? '', [self::TYPE_FOOD, self::TYPE_BEVERAGE], true)
            ? $data['type']
            : self::TYPE_FOOD;

        $insertData = [
            'type'        => $type,
            'label'       => (string) ($data['label']       ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'price_cents' => (int)    ($data['price_cents'] ?? 0),
            'is_active'   => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ];
        $vendorId = isset($data['vendor_id']) ? (int) $data['vendor_id'] : 0;
        if ($vendorId > 0) {
            $insertData['vendor_id'] = $vendorId;
        }
        $result = $wpdb->insert(DatabaseManager::menuItemsTable(), $insertData);

        return $result ? self::find((int) $wpdb->insert_id) : null;
    }

    /**
     * Updates one or more columns on an existing menu item.
     *
     * Accepted keys in $data: label, description, price_cents, is_active.
     * Unknown keys are silently ignored.
     *
     * @param int                 $id   Primary key of the item to update.
     * @param array<string,mixed> $data Fields to update.
     * @return bool True on success or when there are no fields to update.
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = [];
        if (isset($data['label']))       $fields['label']       = (string) $data['label'];
        if (isset($data['description'])) $fields['description'] = (string) $data['description'];
        if (isset($data['price_cents'])) $fields['price_cents'] = (int)    $data['price_cents'];
        if (array_key_exists('vendor_id', $data)) $fields['vendor_id'] = $data['vendor_id'] > 0 ? (int) $data['vendor_id'] : null;
        if (isset($data['is_active']))   $fields['is_active']   = (int)    $data['is_active'];

        if (empty($fields)) {
            return true;
        }

        return $wpdb->update(DatabaseManager::menuItemsTable(), $fields, ['id' => $id]) !== false;
    }

    /**
     * Deletes a menu item from the global library and removes it from all event pivots.
     *
     * @param int $id Primary key of the item to delete.
     * @return bool True on success.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::eventMenuItemsTable(), ['menu_item_id' => $id]);
        return $wpdb->delete(DatabaseManager::menuItemsTable(), ['id' => $id]) !== false;
    }

    // -------------------------------------------------------------------------
    // Event pivot — assign / remove / query items for a specific event
    // -------------------------------------------------------------------------

    /**
     * Returns all menu items assigned to an event, optionally filtered by type.
     *
     * @return self[]
     */
    public static function forEvent(int $eventId, string $type = ''): array
    {
        global $wpdb;

        $itemsTable = DatabaseManager::menuItemsTable();
        $pivotTable = DatabaseManager::eventMenuItemsTable();

        if ($type !== '') {
            $type = $type === self::TYPE_BEVERAGE ? self::TYPE_BEVERAGE : self::TYPE_FOOD;
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT mi.id, mi.type, mi.label, mi.description,
                            mi.price_cents, emi.sort_order, mi.is_active, mi.created_at, mi.updated_at
                     FROM {$itemsTable} mi
                     INNER JOIN {$pivotTable} emi ON emi.menu_item_id = mi.id
                     WHERE emi.event_id = %d AND mi.type = %s
                     ORDER BY emi.sort_order ASC, mi.label ASC",
                    $eventId,
                    $type
                )
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT mi.id, mi.type, mi.label, mi.description,
                            mi.price_cents, emi.sort_order, mi.is_active, mi.created_at, mi.updated_at
                     FROM {$itemsTable} mi
                     INNER JOIN {$pivotTable} emi ON emi.menu_item_id = mi.id
                     WHERE emi.event_id = %d
                     ORDER BY mi.type ASC, emi.sort_order ASC, mi.label ASC",
                    $eventId
                )
            );
        }

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Convenience alias used by the REST API.
     *
     * @return self[]
     */
    public static function forEventByType(int $eventId, string $type): array
    {
        return self::forEvent($eventId, $type);
    }

    /**
     * Assigns a menu item to an event (inserts into the pivot table).
     *
     * If the item is already assigned this is a no-op and returns true.
     * sort_order is computed automatically via {@see nextEventSortOrder()}.
     *
     * @param int $eventId    The event to assign the item to.
     * @param int $menuItemId The menu item to assign.
     * @return bool True on success.
     */
    public static function addToEvent(int $eventId, int $menuItemId): bool
    {
        global $wpdb;

        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . DatabaseManager::eventMenuItemsTable()
                . " WHERE event_id = %d AND menu_item_id = %d",
                $eventId,
                $menuItemId
            )
        );

        if ($existing > 0) {
            return true;
        }

        return $wpdb->insert(DatabaseManager::eventMenuItemsTable(), [
            'event_id'     => $eventId,
            'menu_item_id' => $menuItemId,
            'sort_order'   => self::nextEventSortOrder($eventId, $menuItemId),
        ]) !== false;
    }

    /**
     * Updates event-specific ordering for one menu item type.
     *
     * @param int   $eventId
     * @param string $type
     * @param int[] $menuItemIds
     * @return bool
     */
    public static function updateEventSortOrder(int $eventId, string $type, array $menuItemIds): bool
    {
        global $wpdb;

        $type        = $type === self::TYPE_BEVERAGE ? self::TYPE_BEVERAGE : self::TYPE_FOOD;
        $menuItemIds = array_values(array_unique(array_filter(array_map('intval', $menuItemIds))));

        if (empty($menuItemIds)) {
            return true;
        }

        $itemsTable = DatabaseManager::menuItemsTable();
        $pivotTable = DatabaseManager::eventMenuItemsTable();
        $placeholders = implode(', ', array_fill(0, count($menuItemIds), '%d'));

        $validIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT emi.menu_item_id
                 FROM {$pivotTable} emi
                 INNER JOIN {$itemsTable} mi ON mi.id = emi.menu_item_id
                 WHERE emi.event_id = %d
                   AND mi.type = %s
                   AND emi.menu_item_id IN ({$placeholders})",
                $eventId,
                $type,
                ...$menuItemIds
            )
        );

        $validLookup = array_flip(array_map('intval', $validIds ?? []));
        $position    = 1;

        foreach ($menuItemIds as $menuItemId) {
            if (!isset($validLookup[$menuItemId])) {
                continue;
            }

            $updated = $wpdb->update(
                $pivotTable,
                ['sort_order' => $position],
                ['event_id' => $eventId, 'menu_item_id' => $menuItemId],
                ['%d'],
                ['%d', '%d']
            );

            if ($updated === false) {
                return false;
            }

            $position++;
        }

        return true;
    }

    /**
     * Removes a single menu item from an event pivot.
     *
     * @param int $eventId    The event to remove the item from.
     * @param int $menuItemId The menu item to remove.
     * @return bool True on success.
     */
    public static function removeFromEvent(int $eventId, int $menuItemId): bool
    {
        global $wpdb;
        return $wpdb->delete(
            DatabaseManager::eventMenuItemsTable(),
            ['event_id' => $eventId, 'menu_item_id' => $menuItemId]
        ) !== false;
    }

    /**
     * Removes all menu item pivot rows for an event.
     *
     * Called when an event is deleted so orphaned pivot rows are cleaned up.
     *
     * @param int $eventId The event whose menu assignments should be deleted.
     * @return void
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::eventMenuItemsTable(), ['event_id' => $eventId]);
    }

    /**
     * Computes the next available sort_order for a menu item being added to an event.
     *
     * The new position is (max sort_order among same-type items in the event) + 1,
     * or 1 if the event has no items of that type yet.
     *
     * @param int $eventId    The target event.
     * @param int $menuItemId The menu item being added (used to look up its type).
     * @return int The next sort position.
     */
    private static function nextEventSortOrder(int $eventId, int $menuItemId): int
    {
        global $wpdb;

        $itemsTable = DatabaseManager::menuItemsTable();
        $pivotTable = DatabaseManager::eventMenuItemsTable();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(emi.sort_order), 0) + 1
                 FROM {$pivotTable} emi
                 INNER JOIN {$itemsTable} assigned ON assigned.id = emi.menu_item_id
                 INNER JOIN {$itemsTable} added ON added.id = %d
                 WHERE emi.event_id = %d
                   AND assigned.type = added.type",
                $menuItemId,
                $eventId
            )
        );
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    /**
     * Hydrates a MenuItem instance from a database result row.
     *
     * @param object $row Raw row object returned by $wpdb->get_row() / get_results().
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:          (int)  $row->id,
            type:               $row->type        ?? self::TYPE_FOOD,
            label:              $row->label       ?? '',
            description:        $row->description ?? '',
            priceCents:  (int)  ($row->price_cents ?? 0),
            vendorId:    isset($row->vendor_id) && $row->vendor_id !== null ? (int) $row->vendor_id : null,
            sortOrder:   (int)  ($row->sort_order  ?? 0),
            isActive:    (bool) ($row->is_active   ?? true),
            createdAt:          $row->created_at   ?? '',
            updatedAt:          $row->updated_at   ?? '',
        );
    }
}
