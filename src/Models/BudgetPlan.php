<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a budget plan, which is the top-level container for event budgeting.
 *
 * A plan can span one or more existing events (via eim_budget_plan_events) and
 * holds multiple line items (via eim_budget_line_items).
 */
final class BudgetPlan
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $description,
        public readonly int    $targetAmountCents,
        public readonly string $currency,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::budgetPlansTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function all(): array
    {
        global $wpdb;
        $table = DatabaseManager::budgetPlansTable();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns plans filtered by an optional search string and sorted by column.
     *
     * Supports searching by name, description, or linked event names.
     * Sorting by 'name' and 'target' is done in the DB; 'events' falls back to name.
     *
     * @return self[]
     */
    public static function listForAdmin(
        string $search = '',
        string $sort   = 'name',
        string $order  = 'asc',
        string $field  = ''
    ): array {
        global $wpdb;

        $table    = DatabaseManager::budgetPlansTable();
        $sortCol  = $sort === 'target' ? 'target_amount_cents' : 'name';
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        if ($search === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY {$sortCol} {$orderSql}, id ASC");
            $plans = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
            return self::sortPlans($plans, $sort, $order);
        }

        $like        = '%' . $wpdb->esc_like(strtolower($search)) . '%';
        $pivotTable  = DatabaseManager::budgetPlanEventsTable();
        $eventsTable = DatabaseManager::eventsTable();

        // When a specific field is selected, search only that column.
        if ($field === 'name') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE LOWER(name) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $like)
            );
            return self::sortPlans(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []), $sort, $order);
        }

        if ($field === 'description') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE LOWER(COALESCE(description,'')) LIKE %s ORDER BY {$sortCol} {$orderSql}, id ASC", $like)
            );
            return self::sortPlans(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []), $sort, $order);
        }

        if ($field === 'events') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT p.* FROM {$table} p
                     INNER JOIN {$pivotTable} pe ON pe.plan_id = p.id
                     INNER JOIN {$eventsTable} e  ON e.id = pe.event_id
                     WHERE LOWER(e.name) LIKE %s
                     ORDER BY p.{$sortCol} {$orderSql}, p.id ASC",
                    $like
                )
            );
            return self::sortPlans(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []), $sort, $order);
        }

        // Default: search name + description in DB, then merge event-name matches.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE LOWER(name) LIKE %s OR LOWER(COALESCE(description,'')) LIKE %s
                 ORDER BY {$sortCol} {$orderSql}, id ASC",
                $like,
                $like
            )
        );
        $plans = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);

        // Additionally include plans whose linked event names match.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $extraRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.* FROM {$table} p
                 INNER JOIN {$pivotTable} pe ON pe.plan_id = p.id
                 INNER JOIN {$eventsTable} e  ON e.id = pe.event_id
                 WHERE LOWER(e.name) LIKE %s
                 ORDER BY p.{$sortCol} {$orderSql}, p.id ASC",
                $like
            )
        );
        $extraPlans = array_map(static fn(object $r) => self::fromRow($r), $extraRows ?? []);

        $seen = array_flip(array_map(static fn(self $p) => $p->id, $plans));
        foreach ($extraPlans as $ep) {
            if (!isset($seen[$ep->id])) {
                $plans[]       = $ep;
                $seen[$ep->id] = true;
            }
        }

        return self::sortPlans($plans, $sort, $order);
    }

    /**
     * Applies PHP-level sorting for computed/relational columns.
     *
     * DB-sortable columns (name, target) are already sorted by the query.
     * Events, estimated, and paid are computed per-plan and sorted here.
     *
     * @param self[] $plans
     * @return self[]
     */
    private static function sortPlans(array $plans, string $sort, string $order): array
    {
        if (!in_array($sort, ['events', 'estimated', 'paid'], true)) {
            return $plans;
        }

        $mul = $order === 'desc' ? -1 : 1;

        // Pre-compute sort values to avoid repeated DB calls inside usort.
        $keys = [];
        foreach ($plans as $plan) {
            if ($sort === 'events') {
                $events = $plan->events();
                $keys[$plan->id] = empty($events)
                    ? ''
                    : strtolower(implode(', ', array_map(static fn(Event $e) => $e->name, $events)));
            } elseif ($sort === 'estimated') {
                $keys[$plan->id] = $plan->estimatedCents();
            } else {
                $keys[$plan->id] = $plan->paidCents();
            }
        }

        usort($plans, static function (self $a, self $b) use ($mul, $keys, $sort): int {
            if ($sort === 'events') {
                return $mul * strcasecmp((string) ($keys[$a->id] ?? ''), (string) ($keys[$b->id] ?? ''));
            }
            return $mul * (($keys[$a->id] ?? 0) <=> ($keys[$b->id] ?? 0));
        });

        return $plans;
    }

    public static function create(array $data): ?self
    {
        global $wpdb;
        $result = $wpdb->insert(DatabaseManager::budgetPlansTable(), [
            'name'                => (string) ($data['name']                ?? ''),
            'description'         => (string) ($data['description']         ?? ''),
            'target_amount_cents' => (int)    ($data['target_amount_cents'] ?? 0),
            'currency'            => (string) ($data['currency']            ?? 'USD'),
        ]);
        return $result ? self::find((int) $wpdb->insert_id) : null;
    }

    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $fields = [];
        if (array_key_exists('name', $data))                $fields['name']                = (string) $data['name'];
        if (array_key_exists('description', $data))         $fields['description']         = (string) $data['description'];
        if (array_key_exists('target_amount_cents', $data)) $fields['target_amount_cents'] = (int)    $data['target_amount_cents'];
        if (array_key_exists('currency', $data))            $fields['currency']            = (string) $data['currency'];
        if (empty($fields)) return true;
        return $wpdb->update(DatabaseManager::budgetPlansTable(), $fields, ['id' => $id]) !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::budgetLineItemsTable(),  ['plan_id' => $id]);
        $wpdb->delete(DatabaseManager::budgetPlanEventsTable(), ['plan_id' => $id]);
        return $wpdb->delete(DatabaseManager::budgetPlansTable(), ['id' => $id]) !== false;
    }

    // -------------------------------------------------------------------------
    // Event pivot
    // -------------------------------------------------------------------------

    /** @return int[] Event IDs linked to this plan. */
    public function eventIds(): array
    {
        global $wpdb;
        $table = DatabaseManager::budgetPlanEventsTable();
        $ids   = $wpdb->get_col($wpdb->prepare("SELECT event_id FROM {$table} WHERE plan_id = %d ORDER BY event_id ASC", $this->id));
        return array_map('intval', $ids ?? []);
    }

    /** @return Event[] */
    public function events(): array
    {
        $ids = $this->eventIds();
        if (empty($ids)) return [];
        return array_values(array_filter(array_map(static fn(int $id) => Event::find($id), $ids)));
    }

    public static function setEvents(int $planId, array $eventIds): void
    {
        global $wpdb;
        $pivot = DatabaseManager::budgetPlanEventsTable();

        // Capture the current set before replacing so we can detect removals.
        $currentIds = array_map('intval', $wpdb->get_col(
            $wpdb->prepare("SELECT event_id FROM {$pivot} WHERE plan_id = %d", $planId)
        ) ?? []);

        $newIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

        $wpdb->delete($pivot, ['plan_id' => $planId]);
        foreach ($newIds as $eid) {
            if ($eid > 0) {
                $wpdb->insert($pivot, ['plan_id' => $planId, 'event_id' => $eid]);
            }
        }

        // Demote line items whose event was removed from the plan: set event_id = NULL
        // so they remain in the plan totals as plan-wide costs rather than disappearing.
        $removedIds = array_values(array_diff($currentIds, $newIds));
        if (!empty($removedIds)) {
            $lineItemsTable = DatabaseManager::budgetLineItemsTable();
            $placeholders   = implode(', ', array_fill(0, count($removedIds), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$lineItemsTable} SET event_id = NULL WHERE plan_id = %d AND event_id IN ({$placeholders})",
                    $planId,
                    ...$removedIds
                )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Totals (delegates to BudgetLineItem)
    // -------------------------------------------------------------------------

    public function estimatedCents(): int
    {
        return BudgetLineItem::sumEstimatedForPlan($this->id);
    }

    public function paidCents(): int
    {
        return BudgetLineItem::sumPaidForPlan($this->id);
    }

    public function remainingCents(): int
    {
        return max(0, $this->estimatedCents() - $this->paidCents());
    }

    public function formattedTarget(): string   { return self::formatCents($this->targetAmountCents); }
    public function formattedEstimated(): string { return self::formatCents($this->estimatedCents()); }
    public function formattedPaid(): string      { return self::formatCents($this->paidCents()); }
    public function formattedRemaining(): string { return self::formatCents($this->remainingCents()); }

    public static function formatCents(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:                 (int)  $row->id,
            name:                      $row->name                ?? '',
            description:               $row->description         ?? '',
            targetAmountCents:  (int)  ($row->target_amount_cents ?? 0),
            currency:                  $row->currency            ?? 'USD',
            createdAt:                 $row->created_at          ?? '',
            updatedAt:                 $row->updated_at          ?? '',
        );
    }
}
