<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single cost line item within a budget plan.
 *
 * quantity_mode:
 *   'fixed'        — estimated total = quantity × unit_cost_cents
 *   'per_attending' — estimated total = attending RSVP count × unit_cost_cents
 *                    (event_id must be set; falls back to fixed if no RSVP data)
 *
 * source_type / source_id:
 *   When source_type = 'menu_item', source_id is a MenuItem id and unit_cost_cents
 *   is seeded from that item's price_cents on creation.
 */
final class BudgetLineItem
{
    public const QUANTITY_MODE_FIXED        = 'fixed';
    public const QUANTITY_MODE_PER_ATTENDING = 'per_attending';

    public const CATEGORIES = [
        'catering'       => 'Catering',
        'venue'          => 'Venue',
        'lodging'        => 'Lodging',
        'rentals'        => 'Rentals',
        'music'          => 'Music / Entertainment',
        'photography'    => 'Photography / Video',
        'flowers'        => 'Flowers / Décor',
        'gifts'          => 'Gifts / Favors',
        'transportation' => 'Transportation',
        'staffing'       => 'Staffing',
        'attire'         => 'Attire',
        'other'          => 'Other',
    ];

    public function __construct(
        public readonly int     $id,
        public readonly int     $planId,
        public readonly ?int    $eventId,
        public readonly string  $category,
        public readonly string  $label,
        public readonly ?string $sourceType,
        public readonly ?int    $sourceId,
        public readonly float   $quantity,
        public readonly string  $quantityMode,
        public readonly int     $unitCostCents,
        public readonly ?int    $totalOverrideCents,
        public readonly int     $paidAmountCents,
        public readonly string  $vendorName,
        public readonly string  $notes,
        public readonly int     $sortOrder,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::budgetLineItemsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function forPlan(int $planId): array
    {
        global $wpdb;
        $table = DatabaseManager::budgetLineItemsTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE plan_id = %d ORDER BY sort_order ASC, id ASC",
                $planId
            )
        );
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns line items for a plan with optional search and sort.
     *
     * DB-sortable: label, category, quantity, unit_cost, paid, sort_order.
     * PHP-sorted:  event (relational), estimated (computed).
     *
     * @return self[]
     */
    public static function searchForPlan(
        int    $planId,
        string $search = '',
        string $sort   = 'sort_order',
        string $order  = 'asc',
        string $field  = ''
    ): array {
        global $wpdb;

        $table    = DatabaseManager::budgetLineItemsTable();
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        $dbSortMap = [
            'label'      => 'label',
            'category'   => 'category',
            'quantity'   => 'quantity',
            'unit_cost'  => 'unit_cost_cents',
            'paid'       => 'paid_amount_cents',
            'sort_order' => 'sort_order',
        ];
        $sortCol = $dbSortMap[$sort] ?? 'sort_order';

        if ($search === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows  = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE plan_id = %d ORDER BY {$sortCol} {$orderSql}, id ASC", $planId)
            );
            $items = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
            return self::maybePhpSort($items, $sort, $order);
        }

        $like        = '%' . $wpdb->esc_like(strtolower($search)) . '%';
        $eventsTable = DatabaseManager::eventsTable();

        if ($field === 'label') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE plan_id = %d AND LOWER(label) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $planId, $like)
            );
        } elseif ($field === 'category') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE plan_id = %d AND LOWER(category) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $planId, $like)
            );
        } elseif ($field === 'vendor') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE plan_id = %d AND LOWER(vendor_name) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $planId, $like)
            );
        } elseif ($field === 'event') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT li.* FROM {$table} li
                     INNER JOIN {$eventsTable} e ON e.id = li.event_id
                     WHERE li.plan_id = %d AND LOWER(e.name) LIKE %s
                     ORDER BY li.{$sortCol} {$orderSql}, li.id ASC",
                    $planId, $like
                )
            );
        } else {
            // Any — label, category, vendor_name, notes
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE plan_id = %d
                       AND (LOWER(label) LIKE %s OR LOWER(category) LIKE %s
                            OR LOWER(vendor_name) LIKE %s OR LOWER(COALESCE(notes,'')) LIKE %s)
                     ORDER BY {$sortCol} {$orderSql}, id ASC",
                    $planId, $like, $like, $like, $like
                )
            );
        }

        $items = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        return self::maybePhpSort($items, $sort, $order);
    }

    /**
     * Applies PHP-level sorting for computed/relational columns (event, estimated).
     *
     * @param self[] $items
     * @return self[]
     */
    private static function maybePhpSort(array $items, string $sort, string $order): array
    {
        if (!in_array($sort, ['event', 'estimated'], true)) {
            return $items;
        }

        $mul  = $order === 'desc' ? -1 : 1;
        $keys = [];

        foreach ($items as $item) {
            if ($sort === 'event') {
                $event = $item->eventId ? Event::find($item->eventId) : null;
                $keys[$item->id] = $event ? strtolower($event->name) : '';
            } else {
                $keys[$item->id] = $item->estimatedCents();
            }
        }

        usort($items, static function (self $a, self $b) use ($mul, $sort, $keys): int {
            if ($sort === 'event') {
                return $mul * strcasecmp((string)($keys[$a->id] ?? ''), (string)($keys[$b->id] ?? ''));
            }
            return $mul * (($keys[$a->id] ?? 0) <=> ($keys[$b->id] ?? 0));
        });

        return $items;
    }

    public static function create(array $data): ?self
    {
        global $wpdb;
        $table     = DatabaseManager::budgetLineItemsTable();
        $maxOrder  = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM {$table} WHERE plan_id = %d", (int) ($data['plan_id'] ?? 0))
        );

        $result = $wpdb->insert($table, [
            'plan_id'              => (int)    ($data['plan_id']              ?? 0),
            'event_id'             => isset($data['event_id']) && (int) $data['event_id'] > 0 ? (int) $data['event_id'] : null,
            'category'             => self::sanitizeCategory((string) ($data['category'] ?? 'other')),
            'label'                => (string) ($data['label']                ?? ''),
            'source_type'          => isset($data['source_type']) ? (string) $data['source_type'] : null,
            'source_id'            => isset($data['source_id']) && (int) $data['source_id'] > 0 ? (int) $data['source_id'] : null,
            'quantity'             => (float)  ($data['quantity']             ?? 1),
            'quantity_mode'        => isset($data['quantity_mode']) && $data['quantity_mode'] === self::QUANTITY_MODE_PER_ATTENDING
                ? self::QUANTITY_MODE_PER_ATTENDING
                : self::QUANTITY_MODE_FIXED,
            'unit_cost_cents'      => (int)    ($data['unit_cost_cents']      ?? 0),
            'total_override_cents' => isset($data['total_override_cents']) && (int) $data['total_override_cents'] > 0 ? (int) $data['total_override_cents'] : null,
            'paid_amount_cents'    => (int)    ($data['paid_amount_cents']    ?? 0),
            'vendor_name'          => (string) ($data['vendor_name']          ?? ''),
            'notes'                => (string) ($data['notes']                ?? ''),
            'sort_order'           => $maxOrder + 1,
        ]);

        return $result ? self::find((int) $wpdb->insert_id) : null;
    }

    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $fields = [];
        if (array_key_exists('event_id', $data))             $fields['event_id']             = $data['event_id'] > 0 ? (int) $data['event_id'] : null;
        if (array_key_exists('category', $data))             $fields['category']             = self::sanitizeCategory((string) $data['category']);
        if (array_key_exists('label', $data))                $fields['label']                = (string) $data['label'];
        if (array_key_exists('quantity', $data))             $fields['quantity']             = (float)  $data['quantity'];
        if (array_key_exists('quantity_mode', $data))        $fields['quantity_mode']        = $data['quantity_mode'] === self::QUANTITY_MODE_PER_ATTENDING ? self::QUANTITY_MODE_PER_ATTENDING : self::QUANTITY_MODE_FIXED;
        if (array_key_exists('unit_cost_cents', $data))      $fields['unit_cost_cents']      = (int)    $data['unit_cost_cents'];
        if (array_key_exists('total_override_cents', $data)) $fields['total_override_cents'] = $data['total_override_cents'] > 0 ? (int) $data['total_override_cents'] : null;
        if (array_key_exists('paid_amount_cents', $data))    $fields['paid_amount_cents']    = (int)    $data['paid_amount_cents'];
        if (array_key_exists('vendor_name', $data))          $fields['vendor_name']          = (string) $data['vendor_name'];
        if (array_key_exists('notes', $data))                $fields['notes']                = (string) $data['notes'];
        if (empty($fields)) return true;
        return $wpdb->update(DatabaseManager::budgetLineItemsTable(), $fields, ['id' => $id]) !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete(DatabaseManager::budgetLineItemsTable(), ['id' => $id]) !== false;
    }

    // -------------------------------------------------------------------------
    // Totals
    // -------------------------------------------------------------------------

    /**
     * Returns the estimated total for this line item in cents.
     * If a total_override is set, that wins. Otherwise quantity × unit_cost.
     * For per_attending mode, quantity is replaced by the RSVP attending count.
     */
    public function estimatedCents(): int
    {
        if ($this->totalOverrideCents !== null) {
            return $this->totalOverrideCents;
        }

        $qty = $this->quantity;

        if ($this->quantityMode === self::QUANTITY_MODE_PER_ATTENDING && $this->eventId !== null) {
            $event = Event::find($this->eventId);
            if ($event !== null) {
                $qty = (float) $event->registeredCount();
            }
        }

        return (int) round($qty * $this->unitCostCents);
    }

    public static function sumEstimatedForPlan(int $planId): int
    {
        $items = self::forPlan($planId);
        return array_sum(array_map(static fn(self $i) => $i->estimatedCents(), $items));
    }

    public static function sumPaidForPlan(int $planId): int
    {
        global $wpdb;
        $table = DatabaseManager::budgetLineItemsTable();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(SUM(paid_amount_cents), 0) FROM {$table} WHERE plan_id = %d", $planId)
        );
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    public function formattedEstimated(): string { return BudgetPlan::formatCents($this->estimatedCents()); }
    public function formattedPaid(): string       { return BudgetPlan::formatCents($this->paidAmountCents); }
    public function formattedUnitCost(): string   { return BudgetPlan::formatCents($this->unitCostCents); }
    public function categoryLabel(): string       { return self::CATEGORIES[$this->category] ?? ucfirst($this->category); }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function sanitizeCategory(string $cat): string
    {
        return array_key_exists($cat, self::CATEGORIES) ? $cat : 'other';
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:                  (int)   $row->id,
            planId:              (int)   $row->plan_id,
            eventId:             isset($row->event_id) && $row->event_id !== null ? (int) $row->event_id : null,
            category:                    $row->category          ?? 'other',
            label:                       $row->label             ?? '',
            sourceType:          isset($row->source_type) ? (string) $row->source_type : null,
            sourceId:            isset($row->source_id) && $row->source_id !== null ? (int) $row->source_id : null,
            quantity:            (float) ($row->quantity         ?? 1),
            quantityMode:                $row->quantity_mode     ?? self::QUANTITY_MODE_FIXED,
            unitCostCents:       (int)   ($row->unit_cost_cents  ?? 0),
            totalOverrideCents:  isset($row->total_override_cents) && $row->total_override_cents !== null ? (int) $row->total_override_cents : null,
            paidAmountCents:     (int)   ($row->paid_amount_cents ?? 0),
            vendorName:                  $row->vendor_name        ?? '',
            notes:                       $row->notes              ?? '',
            sortOrder:           (int)   ($row->sort_order        ?? 0),
            createdAt:                   $row->created_at         ?? '',
            updatedAt:                   $row->updated_at         ?? '',
        );
    }
}
