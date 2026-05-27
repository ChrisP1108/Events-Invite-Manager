<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single global budget line item in the shared library.
 *
 * Global items hold the reusable metadata — label, vendor, cost, image, notes —
 * while plan-specific usage data (quantity, paid amount, deadline) lives in
 * BudgetLineItem (eim_budget_line_items).
 */
final class BudgetItem
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $label,
        public readonly ?int    $vendorId,
        public readonly string  $websiteUrl,
        public readonly string  $notes,
        public readonly int     $imageAttachmentId,
        public readonly int     $unitCostCents,
        public readonly ?string $sourceType,
        public readonly ?int    $sourceId,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::budgetItemsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): ?self
    {
        global $wpdb;
        $result = $wpdb->insert(DatabaseManager::budgetItemsTable(), [
            'label'               => (string) ($data['label']               ?? ''),
            'vendor_id'           => isset($data['vendor_id']) && (int) $data['vendor_id'] > 0 ? (int) $data['vendor_id'] : null,
            'website_url'         => (string) ($data['website_url']         ?? ''),
            'notes'               => (string) ($data['notes']               ?? ''),
            'image_attachment_id' => (int)    ($data['image_attachment_id'] ?? 0),
            'unit_cost_cents'     => (int)    ($data['unit_cost_cents']     ?? 0),
            'source_type'         => isset($data['source_type']) && $data['source_type'] !== '' ? (string) $data['source_type'] : null,
            'source_id'           => isset($data['source_id']) && (int) $data['source_id'] > 0 ? (int) $data['source_id'] : null,
        ]);
        return $result ? self::find((int) $wpdb->insert_id) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $fields = [];
        if (array_key_exists('label', $data))               $fields['label']               = (string) $data['label'];
        if (array_key_exists('vendor_id', $data))           $fields['vendor_id']           = (int) $data['vendor_id'] > 0 ? (int) $data['vendor_id'] : null;
        if (array_key_exists('website_url', $data))         $fields['website_url']         = (string) $data['website_url'];
        if (array_key_exists('notes', $data))               $fields['notes']               = (string) $data['notes'];
        if (array_key_exists('image_attachment_id', $data)) $fields['image_attachment_id'] = (int) $data['image_attachment_id'];
        if (array_key_exists('unit_cost_cents', $data))     $fields['unit_cost_cents']     = (int) $data['unit_cost_cents'];
        if (empty($fields)) return true;
        return $wpdb->update(DatabaseManager::budgetItemsTable(), $fields, ['id' => $id]) !== false;
    }

    /**
     * Deletes the global item AND all plan-usage rows that reference it.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        // Remove category associations for every usage row first.
        $lineTable = DatabaseManager::budgetLineItemsTable();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $usageIds  = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$lineTable} WHERE global_item_id = %d", $id));
        foreach (array_map('intval', $usageIds ?? []) as $usageId) {
            Category::syncToEntity('budget_line_item', $usageId, []);
        }
        $wpdb->delete($lineTable, ['global_item_id' => $id]);
        return $wpdb->delete(DatabaseManager::budgetItemsTable(), ['id' => $id]) !== false;
    }

    // -------------------------------------------------------------------------
    // List / search
    // -------------------------------------------------------------------------

    /**
     * Returns global items filtered and sorted for the admin list table.
     *
     * DB-sortable: label, unit_cost.
     * PHP-sorted:  budget_plans (computed count).
     *
     * @return self[]
     */
    public static function listForAdmin(
        string $search = '',
        string $sort   = 'label',
        string $order  = 'asc',
        string $field  = ''
    ): array {
        global $wpdb;

        $table        = DatabaseManager::budgetItemsTable();
        $vendorsTable = DatabaseManager::vendorsTable();
        $orderSql     = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $sortCol      = $sort === 'unit_cost' ? 'unit_cost_cents' : 'label';

        if ($search === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY {$sortCol} {$orderSql}, id ASC");
            $items = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
            return self::maybePhpSort($items, $sort, $order);
        }

        $like = '%' . $wpdb->esc_like(strtolower($search)) . '%';

        if ($field === 'label') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE LOWER(label) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $like)
            );
        } elseif ($field === 'vendor') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT bi.* FROM {$table} bi
                     LEFT JOIN {$vendorsTable} v ON v.id = bi.vendor_id
                     WHERE LOWER(COALESCE(v.company_name,'')) LIKE %s
                     ORDER BY bi.{$sortCol} {$orderSql}, bi.id ASC",
                    $like
                )
            );
        } elseif ($field === 'notes') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE LOWER(COALESCE(notes,'')) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $like)
            );
        } else {
            // Any: label, notes, or vendor company name.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT bi.* FROM {$table} bi
                     LEFT JOIN {$vendorsTable} v ON v.id = bi.vendor_id
                     WHERE LOWER(bi.label) LIKE %s
                        OR LOWER(COALESCE(bi.notes,'')) LIKE %s
                        OR LOWER(COALESCE(v.company_name,'')) LIKE %s
                     ORDER BY bi.{$sortCol} {$orderSql}, bi.id ASC",
                    $like, $like, $like
                )
            );
        }

        $items = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        return self::maybePhpSort($items, $sort, $order);
    }

    /**
     * Applies PHP-level sorting for computed columns (budget_plans count).
     *
     * @param self[] $items
     * @return self[]
     */
    private static function maybePhpSort(array $items, string $sort, string $order): array
    {
        if ($sort !== 'budget_plans') {
            return $items;
        }
        $mul    = $order === 'desc' ? -1 : 1;
        $ids    = array_map(static fn(self $i) => $i->id, $items);
        $counts = self::planCountsForIds($ids);

        usort($items, static fn(self $a, self $b) => $mul * (($counts[$a->id] ?? 0) <=> ($counts[$b->id] ?? 0)));
        return $items;
    }

    // -------------------------------------------------------------------------
    // Plan-usage helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the IDs of budget plans that use this global item.
     *
     * @return int[]
     */
    public function planIds(): array
    {
        global $wpdb;
        $table = DatabaseManager::budgetLineItemsTable();
        $ids   = $wpdb->get_col(
            $wpdb->prepare("SELECT DISTINCT plan_id FROM {$table} WHERE global_item_id = %d ORDER BY plan_id ASC", $this->id)
        );
        return array_map('intval', $ids ?? []);
    }

    /** Returns the number of distinct budget plans that reference this item. */
    public function planCount(): int
    {
        return count($this->planIds());
    }

    /**
     * Returns a map of globalItemId → planId[] for a batch of item IDs.
     * Avoids N+1 queries when rendering the list table.
     *
     * @param int[] $itemIds
     * @return array<int,int[]>
     */
    public static function planIdsForItems(array $itemIds): array
    {
        if (empty($itemIds)) return [];
        global $wpdb;
        $table        = DatabaseManager::budgetLineItemsTable();
        $placeholders = implode(',', array_fill(0, count($itemIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT global_item_id, plan_id FROM {$table} WHERE global_item_id IN ({$placeholders}) GROUP BY global_item_id, plan_id",
                ...$itemIds
            )
        );
        $result = [];
        foreach ($rows ?? [] as $row) {
            $result[(int) $row->global_item_id][] = (int) $row->plan_id;
        }
        return $result;
    }

    /**
     * Returns a map of globalItemId → plan-count for a batch of item IDs.
     *
     * @param int[] $itemIds
     * @return array<int,int>
     */
    public static function planCountsForIds(array $itemIds): array
    {
        if (empty($itemIds)) return [];
        global $wpdb;
        $table        = DatabaseManager::budgetLineItemsTable();
        $placeholders = implode(',', array_fill(0, count($itemIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT global_item_id, COUNT(DISTINCT plan_id) as cnt FROM {$table} WHERE global_item_id IN ({$placeholders}) GROUP BY global_item_id",
                ...$itemIds
            )
        );
        $result = [];
        foreach ($rows ?? [] as $row) {
            $result[(int) $row->global_item_id] = (int) $row->cnt;
        }
        return $result;
    }

    /**
     * Returns matching global items for the suggest autocomplete.
     *
     * @return self[]
     */
    public static function suggest(string $query, int $limit = 10): array
    {
        if ($query === '') return [];
        global $wpdb;
        $table        = DatabaseManager::budgetItemsTable();
        $vendorsTable = DatabaseManager::vendorsTable();
        $like         = '%' . $wpdb->esc_like(strtolower($query)) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT bi.* FROM {$table} bi
                 LEFT JOIN {$vendorsTable} v ON v.id = bi.vendor_id
                 WHERE LOWER(bi.label) LIKE %s OR LOWER(COALESCE(v.company_name,'')) LIKE %s
                 ORDER BY bi.label ASC
                 LIMIT %d",
                $like, $like, $limit
            )
        );
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    public function formattedUnitCost(): string
    {
        return BudgetPlan::formatCents($this->unitCostCents);
    }

    /** Returns the Vendor for this item, or null if none linked. */
    public function vendor(): ?Vendor
    {
        return $this->vendorId ? Vendor::find($this->vendorId) : null;
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:                 (int)   $row->id,
            label:                      $row->label               ?? '',
            vendorId:           isset($row->vendor_id) && $row->vendor_id !== null ? (int) $row->vendor_id : null,
            websiteUrl:                 $row->website_url         ?? '',
            notes:                      $row->notes               ?? '',
            imageAttachmentId:  (int)  ($row->image_attachment_id ?? 0),
            unitCostCents:      (int)  ($row->unit_cost_cents     ?? 0),
            sourceType:         isset($row->source_type) && $row->source_type !== null ? (string) $row->source_type : null,
            sourceId:           isset($row->source_id) && $row->source_id !== null ? (int) $row->source_id : null,
            createdAt:                  $row->created_at          ?? '',
            updatedAt:                  $row->updated_at          ?? '',
        );
    }
}
