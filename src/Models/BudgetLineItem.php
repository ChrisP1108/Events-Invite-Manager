<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

/**
 * Represents a plan-specific usage of a global BudgetItem.
 *
 * The global/reusable fields (label, vendor, cost, image, notes, website URL)
 * are stored in eim_budget_items and exposed here via a JOIN.
 * Plan-specific fields (event, quantity, quantity_mode, paid, deadline) live
 * only in this row.
 *
 * quantity_mode:
 *   'fixed'         — estimated total = quantity × unit_cost_cents
 *   'per_attending' — estimated total = attending RSVP count × unit_cost_cents
 */
final class BudgetLineItem
{
    /** @var string Quantity mode where estimated total = quantity × unit_cost_cents. */
    public const QUANTITY_MODE_FIXED         = 'fixed';

    /** @var string Quantity mode where quantity is replaced by the event's attending RSVP count. */
    public const QUANTITY_MODE_PER_ATTENDING = 'per_attending';

    public function __construct(
        public readonly int     $id,
        public readonly int     $planId,
        public readonly int     $globalItemId,
        public readonly ?int    $eventId,
        public readonly ?int    $vendorId,
        public readonly string  $label,
        public readonly ?string $sourceType,
        public readonly ?int    $sourceId,
        public readonly float   $quantity,
        public readonly string  $quantityMode,
        public readonly int     $unitCostCents,
        public readonly ?int    $totalOverrideCents,
        public readonly int     $paidAmountCents,
        public readonly string  $websiteUrl,
        public readonly ?string $paymentDeadline,
        public readonly string  $notes,
        public readonly int     $imageAttachmentId,
        public readonly int     $sortOrder,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // SQL helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the SELECT + FROM + LEFT JOIN clause used by all read queries.
     *
     * Global fields are aliased with a `gi_` prefix to avoid collision with
     * the plan-usage columns that share the same names (label, vendor_id, etc.)
     * in the legacy pre-migration rows. COALESCE falls back to the old inline
     * columns if global_item_id has not been set yet (pre-migration rows).
     */
    private static function baseSelect(): string
    {
        $li = DatabaseManager::budgetLineItemsTable();
        $bi = DatabaseManager::budgetItemsTable();

        return "SELECT li.id, li.plan_id, li.global_item_id, li.event_id,
                       li.quantity, li.quantity_mode, li.total_override_cents,
                       li.paid_amount_cents, li.payment_deadline, li.sort_order,
                       li.created_at, li.updated_at,
                       COALESCE(bi.label,               li.label)               AS gi_label,
                       COALESCE(bi.vendor_id,           li.vendor_id)           AS gi_vendor_id,
                       COALESCE(bi.website_url,         li.website_url)         AS gi_website_url,
                       COALESCE(bi.notes,               li.notes)               AS gi_notes,
                       COALESCE(bi.image_attachment_id, li.image_attachment_id) AS gi_image_attachment_id,
                       COALESCE(bi.unit_cost_cents,     li.unit_cost_cents)     AS gi_unit_cost_cents,
                       bi.source_type AS gi_source_type,
                       bi.source_id   AS gi_source_id
                FROM {$li} li
                LEFT JOIN {$bi} bi ON bi.id = li.global_item_id";
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;
        $base = self::baseSelect();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row  = $wpdb->get_row($wpdb->prepare("{$base} WHERE li.id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function forPlan(int $planId): array
    {
        global $wpdb;
        $base = self::baseSelect();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("{$base} WHERE li.plan_id = %d ORDER BY li.sort_order ASC, li.id ASC", $planId)
        );
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns line items for a plan with optional search and sort.
     *
     * DB-sortable: label, vendor (join), quantity, unit_cost, paid, sort_order.
     * PHP-sorted:  event (relational), estimated (computed).
     *
     * @return self[]
     */
    public static function searchForPlan(
        int    $planId,
        string $search   = '',
        string $sort     = 'sort_order',
        string $order    = 'asc',
        string $field    = '',
        int    $vendorId = 0
    ): array {
        global $wpdb;

        $vt       = DatabaseManager::vendorsTable();
        $et       = DatabaseManager::eventsTable();
        $base     = self::baseSelect();
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        // Columns that can be sorted directly in SQL.
        $dbSortMap = [
            'label'      => 'gi_label',
            'quantity'   => 'li.quantity',
            'unit_cost'  => 'gi_unit_cost_cents',
            'paid'       => 'li.paid_amount_cents',
            'website_url'=> 'gi_website_url',
            'deadline'   => 'li.payment_deadline',
            'sort_order' => 'li.sort_order',
        ];

        // For computed/relational sorts we still fetch with a default DB order,
        // then apply usort afterwards via maybePhpSort().
        $sortCol = $dbSortMap[$sort] ?? 'li.sort_order';

        // Optional vendor ID filter — safe to interpolate since it's an integer.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $vWhere = $vendorId > 0 ? sprintf(' AND COALESCE(bi.vendor_id, li.vendor_id) = %d', $vendorId) : '';

        if ($search === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows  = $wpdb->get_results(
                $wpdb->prepare("{$base} WHERE li.plan_id = %d{$vWhere} ORDER BY {$sortCol} {$orderSql}, li.id ASC", $planId)
            );
            $items = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
            return self::maybePhpSort($items, $sort, $order);
        }

        $like = '%' . $wpdb->esc_like(strtolower($search)) . '%';

        if ($field === 'label') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "{$base}
                     WHERE li.plan_id = %d AND LOWER(COALESCE(bi.label, li.label)) LIKE %s{$vWhere}
                     ORDER BY {$sortCol} {$orderSql}, li.id ASC",
                    $planId, $like
                )
            );
        } elseif ($field === 'vendor') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "{$base}
                     LEFT JOIN {$vt} v ON v.id = COALESCE(bi.vendor_id, li.vendor_id)
                     WHERE li.plan_id = %d AND LOWER(COALESCE(v.company_name,'')) LIKE %s{$vWhere}
                     ORDER BY {$sortCol} {$orderSql}, li.id ASC",
                    $planId, $like
                )
            );
        } elseif ($field === 'event') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "{$base}
                     INNER JOIN {$et} e ON e.id = li.event_id
                     WHERE li.plan_id = %d AND LOWER(e.name) LIKE %s{$vWhere}
                     ORDER BY {$sortCol} {$orderSql}, li.id ASC",
                    $planId, $like
                )
            );
        } else {
            // Any — label, vendor company name, notes.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "{$base}
                     LEFT JOIN {$vt} v ON v.id = COALESCE(bi.vendor_id, li.vendor_id)
                     WHERE li.plan_id = %d
                       AND (LOWER(COALESCE(bi.label,               li.label))   LIKE %s
                            OR LOWER(COALESCE(v.company_name,''))                LIKE %s
                            OR LOWER(COALESCE(bi.notes, li.notes, ''))          LIKE %s){$vWhere}
                     ORDER BY {$sortCol} {$orderSql}, li.id ASC",
                    $planId, $like, $like, $like
                )
            );
        }

        $items = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        return self::maybePhpSort($items, $sort, $order);
    }

    /**
     * Applies PHP-level sorting for computed/relational columns (event, estimated, vendor).
     *
     * @param self[] $items
     * @return self[]
     */
    private static function maybePhpSort(array $items, string $sort, string $order): array
    {
        if (!in_array($sort, ['event', 'estimated', 'vendor'], true)) {
            return $items;
        }

        $mul     = $order === 'desc' ? -1 : 1;
        $keys    = [];
        $vendors = [];

        if ($sort === 'vendor') {
            $vendorIds = array_filter(array_map(static fn(self $i) => $i->vendorId, $items));
            $vendors   = Vendor::findMany(array_values($vendorIds));
        }

        foreach ($items as $item) {
            if ($sort === 'event') {
                $event = $item->eventId ? Event::find($item->eventId) : null;
                $keys[$item->id] = $event ? strtolower($event->name) : '';
            } elseif ($sort === 'vendor') {
                $vendor = $item->vendorId ? ($vendors[$item->vendorId] ?? null) : null;
                $keys[$item->id] = $vendor ? strtolower($vendor->companyName) : '';
            } else {
                $keys[$item->id] = $item->estimatedCents();
            }
        }

        usort($items, static function (self $a, self $b) use ($mul, $sort, $keys): int {
            if ($sort === 'estimated') {
                return $mul * (($keys[$a->id] ?? 0) <=> ($keys[$b->id] ?? 0));
            }
            return $mul * strcasecmp((string) ($keys[$a->id] ?? ''), (string) ($keys[$b->id] ?? ''));
        });

        return $items;
    }

    /**
     * Creates a new plan usage row.
     *
     * When $data['global_item_id'] > 0 the existing global item is reused (no
     * modification). When it is 0 (or absent) a new BudgetItem is created from
     * the global fields in $data.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): ?self
    {
        global $wpdb;

        $globalItemId = (int) ($data['global_item_id'] ?? 0);

        if ($globalItemId <= 0) {
            // Create a new global library item.
            $globalItem = BudgetItem::create([
                'label'               => $data['label']               ?? '',
                'vendor_id'           => $data['vendor_id']           ?? 0,
                'website_url'         => $data['website_url']         ?? '',
                'notes'               => $data['notes']               ?? '',
                'image_attachment_id' => $data['image_attachment_id'] ?? 0,
                'unit_cost_cents'     => $data['unit_cost_cents']     ?? 0,
                'source_type'         => $data['source_type']         ?? null,
                'source_id'           => $data['source_id']           ?? null,
            ]);
            if ($globalItem === null) return null;
            $globalItemId = $globalItem->id;
        }

        $table    = DatabaseManager::budgetLineItemsTable();
        $maxOrder = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM {$table} WHERE plan_id = %d", (int) ($data['plan_id'] ?? 0))
        );

        $result = $wpdb->insert($table, [
            'plan_id'              => (int)    ($data['plan_id']              ?? 0),
            'global_item_id'       => $globalItemId,
            'event_id'             => isset($data['event_id']) && (int) $data['event_id'] > 0 ? (int) $data['event_id'] : null,
            'quantity'             => (float)  ($data['quantity']             ?? 1),
            'quantity_mode'        => isset($data['quantity_mode']) && $data['quantity_mode'] === self::QUANTITY_MODE_PER_ATTENDING
                ? self::QUANTITY_MODE_PER_ATTENDING
                : self::QUANTITY_MODE_FIXED,
            'total_override_cents' => isset($data['total_override_cents']) && (int) $data['total_override_cents'] > 0 ? (int) $data['total_override_cents'] : null,
            'paid_amount_cents'    => (int)    ($data['paid_amount_cents']    ?? 0),
            'payment_deadline'     => !empty($data['payment_deadline']) ? (string) $data['payment_deadline'] : null,
            'sort_order'           => $maxOrder + 1,
        ]);

        $created = $result ? self::find((int) $wpdb->insert_id) : null;
        if ($created !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_BUDGET_LINE_ITEM, EimChangeEvent::ADDED, $created);
        }
        return $created;
    }

    /**
     * Updates a plan usage row AND the linked global item's shared fields.
     *
     * Global fields:      label, vendor_id, website_url, notes, image_attachment_id, unit_cost_cents.
     * Plan-only fields:   event_id, quantity, quantity_mode, total_override_cents,
     *                     paid_amount_cents, payment_deadline.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $item = self::find($id);
        if ($item === null) return false;

        // --- Update global item fields ---
        if ($item->globalItemId > 0) {
            $globalFields = [];
            if (array_key_exists('label', $data))               $globalFields['label']               = (string) $data['label'];
            if (array_key_exists('vendor_id', $data))           $globalFields['vendor_id']           = (int) $data['vendor_id'];
            if (array_key_exists('website_url', $data))         $globalFields['website_url']         = (string) $data['website_url'];
            if (array_key_exists('notes', $data))               $globalFields['notes']               = (string) $data['notes'];
            if (array_key_exists('image_attachment_id', $data)) $globalFields['image_attachment_id'] = (int) $data['image_attachment_id'];
            if (array_key_exists('unit_cost_cents', $data))     $globalFields['unit_cost_cents']     = (int) $data['unit_cost_cents'];
            if (!empty($globalFields)) {
                BudgetItem::update($item->globalItemId, $globalFields);
            }
        }

        // --- Update plan-specific fields ---
        $planFields = [];
        if (array_key_exists('event_id', $data))             $planFields['event_id']             = (int) $data['event_id'] > 0 ? (int) $data['event_id'] : null;
        if (array_key_exists('quantity', $data))             $planFields['quantity']             = (float) $data['quantity'];
        if (array_key_exists('quantity_mode', $data))        $planFields['quantity_mode']        = $data['quantity_mode'] === self::QUANTITY_MODE_PER_ATTENDING ? self::QUANTITY_MODE_PER_ATTENDING : self::QUANTITY_MODE_FIXED;
        if (array_key_exists('total_override_cents', $data)) $planFields['total_override_cents'] = (int) $data['total_override_cents'] > 0 ? (int) $data['total_override_cents'] : null;
        if (array_key_exists('paid_amount_cents', $data))    $planFields['paid_amount_cents']    = (int) $data['paid_amount_cents'];
        if (array_key_exists('payment_deadline', $data))     $planFields['payment_deadline']     = !empty($data['payment_deadline']) ? (string) $data['payment_deadline'] : null;

        if (empty($planFields)) return true;
        $ok = $wpdb->update(DatabaseManager::budgetLineItemsTable(), $planFields, ['id' => $id]) !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_BUDGET_LINE_ITEM, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Deletes the plan usage row only — the global item is preserved.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $snapshot = self::find($id);
        $ok       = $wpdb->delete(DatabaseManager::budgetLineItemsTable(), ['id' => $id]) !== false;
        if ($ok && $snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_BUDGET_LINE_ITEM, EimChangeEvent::DELETED, $snapshot);
        }
        return $ok;
    }

    // -------------------------------------------------------------------------
    // Totals
    // -------------------------------------------------------------------------

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

    /** Returns the vendor for this item, or null if no vendor is linked. */
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
            id:                  (int)   $row->id,
            planId:              (int)   $row->plan_id,
            globalItemId:        isset($row->global_item_id) && $row->global_item_id !== null ? (int) $row->global_item_id : 0,
            eventId:             isset($row->event_id) && $row->event_id !== null ? (int) $row->event_id : null,
            vendorId:            isset($row->gi_vendor_id) && $row->gi_vendor_id !== null ? (int) $row->gi_vendor_id : null,
            label:                       $row->gi_label             ?? '',
            sourceType:          isset($row->gi_source_type) && $row->gi_source_type !== null ? (string) $row->gi_source_type : null,
            sourceId:            isset($row->gi_source_id) && $row->gi_source_id !== null ? (int) $row->gi_source_id : null,
            quantity:            (float) ($row->quantity             ?? 1),
            quantityMode:                $row->quantity_mode         ?? self::QUANTITY_MODE_FIXED,
            unitCostCents:       (int)   ($row->gi_unit_cost_cents   ?? 0),
            totalOverrideCents:  isset($row->total_override_cents) && $row->total_override_cents !== null ? (int) $row->total_override_cents : null,
            paidAmountCents:     (int)   ($row->paid_amount_cents    ?? 0),
            websiteUrl:                  $row->gi_website_url        ?? '',
            paymentDeadline:     isset($row->payment_deadline) && $row->payment_deadline !== null ? (string) $row->payment_deadline : null,
            notes:                       $row->gi_notes              ?? '',
            imageAttachmentId:   (int)   ($row->gi_image_attachment_id ?? 0),
            sortOrder:           (int)   ($row->sort_order            ?? 0),
            createdAt:                   $row->created_at             ?? '',
            updatedAt:                   $row->updated_at             ?? '',
        );
    }
}
