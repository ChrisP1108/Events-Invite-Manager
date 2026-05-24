<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a unified category in eim_categories.
 *
 * Categories support one level of parent/child hierarchy and can be assigned
 * to any entity type (event, invitee, connection_group, location, menu_item,
 * budget_plan, vendor, newsletter) via the eim_category_map pivot table.
 */
final class Category
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly ?int    $parentId,
        public readonly ?string $parentName,
        public readonly string  $createdAt,
    ) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /** Returns all categories ordered: parents first (alphabetically), then children under their parent. */
    public static function all(): array
    {
        global $wpdb;

        $t   = DatabaseManager::categoriesTable();
        $rows = $wpdb->get_results(
            "SELECT c.*, p.name AS parent_name
             FROM {$t} c
             LEFT JOIN {$t} p ON p.id = c.parent_id
             ORDER BY COALESCE(p.name, c.name) ASC, c.parent_id IS NOT NULL ASC, c.name ASC" // phpcs:ignore
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /** Returns all top-level categories (no parent) ordered alphabetically. */
    public static function roots(): array
    {
        global $wpdb;

        $t    = DatabaseManager::categoriesTable();
        $rows = $wpdb->get_results(
            "SELECT c.*, NULL AS parent_name FROM {$t} c WHERE c.parent_id IS NULL ORDER BY c.name ASC" // phpcs:ignore
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /** Returns all categories grouped as [ parent => [ children ], ... ] for the Categories page UI. */
    public static function allGrouped(): array
    {
        $all     = self::all();
        $parents = [];
        $children = [];

        foreach ($all as $cat) {
            if ($cat->parentId === null) {
                $parents[$cat->id] = ['cat' => $cat, 'children' => []];
            } else {
                $children[$cat->parentId][] = $cat;
            }
        }

        // Attach orphaned children (parent deleted) as pseudo-roots.
        foreach ($children as $parentId => $kids) {
            if (!isset($parents[$parentId])) {
                foreach ($kids as $kid) {
                    $parents[$kid->id] = ['cat' => $kid, 'children' => []];
                }
            } else {
                $parents[$parentId]['children'] = $kids;
            }
        }

        return array_values($parents);
    }

    public static function find(int $id): ?self
    {
        global $wpdb;

        $t   = DatabaseManager::categoriesTable();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, p.name AS parent_name
             FROM {$t} c
             LEFT JOIN {$t} p ON p.id = c.parent_id
             WHERE c.id = %d LIMIT 1",
            $id
        ));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * AJAX typeahead search — returns plain arrays for JSON encoding.
     *
     * @return array<int, array{id:int, name:string, parent_name:string|null, label:string}>
     */
    public static function search(string $query, int $limit = 15): array
    {
        global $wpdb;

        $t    = DatabaseManager::categoriesTable();
        $like = '%' . $wpdb->esc_like($query) . '%';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.name AS parent_name
             FROM {$t} c
             LEFT JOIN {$t} p ON p.id = c.parent_id
             WHERE c.name LIKE %s
             ORDER BY c.parent_id IS NOT NULL ASC, c.name ASC
             LIMIT %d",
            $like,
            $limit
        ));

        return array_map(static function (object $r): array {
            $parentName = $r->parent_name ?? null;
            $label      = $parentName ? "{$parentName} › {$r->name}" : $r->name;
            return [
                'id'          => (int) $r->id,
                'name'        => (string) $r->name,
                'parent_name' => $parentName,
                'label'       => $label,
            ];
        }, $rows ?? []);
    }

    /**
     * Bulk-loads categories for multiple entities of the same type.
     *
     * @param  int[]  $entityIds
     * @return array<int, self[]>  Keyed by entity ID; IDs with no categories are absent.
     */
    public static function forEntities(string $entityType, array $entityIds): array
    {
        if (empty($entityIds)) {
            return [];
        }

        global $wpdb;

        $t   = DatabaseManager::categoriesTable();
        $m   = DatabaseManager::categoryMapTable();

        $ids      = implode(',', array_map('intval', $entityIds));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.name AS parent_name, cm.entity_id
             FROM {$t} c
             LEFT JOIN {$t} p ON p.id = c.parent_id
             INNER JOIN {$m} cm ON cm.category_id = c.id
             WHERE cm.entity_type = %s AND cm.entity_id IN ({$ids})
             ORDER BY c.name ASC",
            $entityType
        ));

        $result = [];
        foreach ($rows ?? [] as $row) {
            $entityId            = (int) $row->entity_id;
            $result[$entityId][] = self::fromRow($row);
        }
        return $result;
    }

    /**
     * Returns all categories assigned to a given entity.
     *
     * @return self[]
     */
    public static function forEntity(string $entityType, int $entityId): array
    {
        global $wpdb;

        $t   = DatabaseManager::categoriesTable();
        $m   = DatabaseManager::categoryMapTable();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.name AS parent_name
             FROM {$t} c
             LEFT JOIN {$t} p ON p.id = c.parent_id
             INNER JOIN {$m} cm ON cm.category_id = c.id
             WHERE cm.entity_type = %s AND cm.entity_id = %d
             ORDER BY c.name ASC",
            $entityType,
            $entityId
        ));

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Replaces all category assignments for an entity with the given set of IDs.
     *
     * @param int[] $categoryIds
     */
    public static function syncToEntity(string $entityType, int $entityId, array $categoryIds): void
    {
        global $wpdb;

        $m           = DatabaseManager::categoryMapTable();
        $t           = DatabaseManager::categoriesTable();
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

        // Strip IDs that don't exist in the categories table.
        if (!empty($categoryIds)) {
            $inList      = implode(',', $categoryIds);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $valid       = $wpdb->get_col("SELECT id FROM {$t} WHERE id IN ({$inList})");
            $categoryIds = array_map('intval', $valid);
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->delete($m, ['entity_type' => $entityType, 'entity_id' => $entityId]);

        foreach ($categoryIds as $catId) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$m} (category_id, entity_type, entity_id) VALUES (%d, %s, %d)",
                $catId,
                $entityType,
                $entityId
            ));
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /** Creates a new category. Returns the new ID or false on failure. */
    public static function create(string $name, ?int $parentId = null): int|false
    {
        global $wpdb;

        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $slug = sanitize_title($name);
        // Make slug unique by appending a counter if needed.
        $base  = $slug;
        $n     = 1;
        $t     = DatabaseManager::categoriesTable();
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE slug = %s LIMIT 1", $slug))) { // phpcs:ignore
            $slug = $base . '-' . $n++;
        }

        $result = $wpdb->insert(DatabaseManager::categoriesTable(), [
            'name'      => $name,
            'slug'      => $slug,
            'parent_id' => $parentId,
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Deletes a category, its children, and all entity assignments for any of those IDs.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $t = DatabaseManager::categoriesTable();
        $m = DatabaseManager::categoryMapTable();

        // Collect the category and any children.
        $childIds = $wpdb->get_col($wpdb->prepare( // phpcs:ignore
            "SELECT id FROM {$t} WHERE parent_id = %d",
            $id
        ));

        $allIds = array_map('intval', array_merge([$id], $childIds ?? []));

        foreach ($allIds as $delId) {
            $wpdb->delete($m, ['category_id' => $delId]);
            $wpdb->delete($t, ['id'          => $delId]);
        }

        return true;
    }

    /**
     * Returns the total number of categories.
     */
    public static function count(): int
    {
        global $wpdb;
        $t = DatabaseManager::categoriesTable();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"); // phpcs:ignore
    }

    /**
     * Returns all categories for the admin list table, with optional filtering.
     *
     * @return self[]
     */
    public static function listForAdmin(string $query = '', string $sort = 'name', string $order = 'asc', string $field = ''): array
    {
        global $wpdb;

        $t        = DatabaseManager::categoriesTable();
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $orderBy  = "ORDER BY COALESCE(p.name, c.name) ASC, c.parent_id IS NOT NULL ASC, c.name {$orderSql}"; // phpcs:ignore

        $base = "SELECT c.*, p.name AS parent_name
                 FROM {$t} c
                 LEFT JOIN {$t} p ON p.id = c.parent_id"; // phpcs:ignore

        if ($query === '') {
            $rows = $wpdb->get_results("{$base} {$orderBy}"); // phpcs:ignore
            return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        }

        $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';
        $rows  = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
            "{$base} WHERE LOWER(c.name) LIKE %s OR LOWER(p.name) LIKE %s {$orderBy}",
            $like,
            $like
        ));

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:         (int)   $row->id,
            name:               (string) ($row->name       ?? ''),
            slug:               (string) ($row->slug       ?? ''),
            parentId:   isset($row->parent_id) && $row->parent_id !== null ? (int) $row->parent_id : null,
            parentName: isset($row->parent_name) && $row->parent_name !== null ? (string) $row->parent_name : null,
            createdAt:          (string) ($row->created_at ?? ''),
        );
    }
}
