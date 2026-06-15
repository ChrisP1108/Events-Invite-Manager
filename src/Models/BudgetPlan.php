<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

/**
 * Represents a budget plan, which is the top-level container for event budgeting.
 *
 * A plan can span one or more existing events (via eim_budget_plan_events) and
 * holds multiple line items (via eim_budget_line_items).
 */
final class BudgetPlan
{
    /**
     * @param int    $id                Primary key.
     * @param string $name              Human-readable plan name.
     * @param string $description       Optional free-text description.
     * @param int    $targetAmountCents Overall budget target in cents.
     * @param string $currency          ISO 4217 currency code (e.g. "USD").
     * @param string $createdAt         Row creation timestamp (MySQL datetime string).
     * @param string $updatedAt         Row last-update timestamp (MySQL datetime string).
     */
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

    /**
     * Finds a single budget plan by primary key.
     *
     * @param int $id Primary key of the plan.
     * @return self|null The plan, or null if not found.
     */
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

    /**
     * Creates a new budget plan.
     *
     * Accepted keys in $data: name, description, target_amount_cents, currency.
     * Defaults: description = "", target_amount_cents = 0, currency = "USD".
     *
     * @param array<string,mixed> $data Column values for the new row.
     * @return self|null The newly created plan, or null on failure.
     */
    public static function create(array $data): ?self
    {
        global $wpdb;
        $result = $wpdb->insert(DatabaseManager::budgetPlansTable(), [
            'name'                => (string) ($data['name']                ?? ''),
            'description'         => (string) ($data['description']         ?? ''),
            'target_amount_cents' => (int)    ($data['target_amount_cents'] ?? 0),
            'currency'            => (string) ($data['currency']            ?? 'USD'),
        ]);
        $created = $result ? self::find((int) $wpdb->insert_id) : null;
        if ($created !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_BUDGET_PLAN, EimChangeEvent::ADDED, $created);
        }
        return $created;
    }

    /**
     * Updates one or more columns on an existing budget plan.
     *
     * Accepted keys in $data: name, description, target_amount_cents, currency.
     * Unknown keys are silently ignored.
     *
     * @param int                 $id   Primary key of the plan to update.
     * @param array<string,mixed> $data Fields to update.
     * @return bool True on success or when there are no fields to update.
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $fields = [];
        if (array_key_exists('name', $data))                $fields['name']                = (string) $data['name'];
        if (array_key_exists('description', $data))         $fields['description']         = (string) $data['description'];
        if (array_key_exists('target_amount_cents', $data)) $fields['target_amount_cents'] = (int)    $data['target_amount_cents'];
        if (array_key_exists('currency', $data))            $fields['currency']            = (string) $data['currency'];
        if (empty($fields)) return true;
        $ok = $wpdb->update(DatabaseManager::budgetPlansTable(), $fields, ['id' => $id]) !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_BUDGET_PLAN, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Deletes a budget plan along with all its line items and event pivot rows.
     *
     * @param int $id Primary key of the plan to delete.
     * @return bool True on success.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $snapshot = self::find($id);
        $wpdb->delete(DatabaseManager::budgetLineItemsTable(),  ['plan_id' => $id]);
        $wpdb->delete(DatabaseManager::budgetPlanEventsTable(), ['plan_id' => $id]);
        $ok = $wpdb->delete(DatabaseManager::budgetPlansTable(), ['id' => $id]) !== false;
        if ($ok && $snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_BUDGET_PLAN, EimChangeEvent::DELETED, $snapshot);
        }
        return $ok;
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

    /**
     * Replaces the full set of events linked to a budget plan.
     *
     * Events that were previously linked but are absent from $eventIds will have
     * their associated line items demoted to plan-wide (event_id = NULL) rather
     * than being deleted, so cost totals are preserved.
     *
     * @param int   $planId    The plan whose event associations should be replaced.
     * @param int[] $eventIds  New set of event IDs to link.
     * @return void
     */
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

    /**
     * Returns the sum of all line-item estimated amounts for this plan in cents.
     *
     * @return int Total estimated cost in cents.
     */
    public function estimatedCents(): int
    {
        return BudgetLineItem::sumEstimatedForPlan($this->id);
    }

    /**
     * Returns the sum of all line-item paid amounts for this plan in cents.
     *
     * @return int Total paid amount in cents.
     */
    public function paidCents(): int
    {
        return BudgetLineItem::sumPaidForPlan($this->id);
    }

    /**
     * Returns the remaining balance (estimated minus paid) in cents, floored at zero.
     *
     * @return int Remaining amount in cents.
     */
    public function remainingCents(): int
    {
        return max(0, $this->estimatedCents() - $this->paidCents());
    }

    /**
     * Returns the difference between the target budget and the estimated total, in cents.
     *
     * Positive when the estimate is under target, negative when over target.
     *
     * @return int Difference amount in cents.
     */
    public function differenceCents(): int
    {
        return $this->targetAmountCents - $this->estimatedCents();
    }

    /**
     * Returns the formatted target amount (e.g. "$5,000.00").
     *
     * @return string
     */
    public function formattedTarget(): string   { return self::formatCents($this->targetAmountCents); }

    /**
     * Returns the formatted estimated total (e.g. "$3,200.00").
     *
     * @return string
     */
    public function formattedEstimated(): string { return self::formatCents($this->estimatedCents()); }

    /**
     * Returns the formatted paid total (e.g. "$1,500.00").
     *
     * @return string
     */
    public function formattedPaid(): string      { return self::formatCents($this->paidCents()); }

    /**
     * Returns the formatted remaining balance (e.g. "$1,700.00").
     *
     * @return string
     */
    public function formattedRemaining(): string { return self::formatCents($this->remainingCents()); }

    /**
     * Returns the formatted target/estimated difference, with a leading "-" for negative values
     * (e.g. "$1,800.00" when under target, "-$300.00" when over target).
     *
     * @return string
     */
    public function formattedDifference(): string
    {
        $cents = $this->differenceCents();

        return ($cents < 0 ? '-' : '') . self::formatCents(abs($cents));
    }

    /**
     * Converts an integer cent amount to a formatted dollar string.
     *
     * @param int $cents Amount in cents.
     * @return string e.g. "$1,234.56".
     */
    public static function formatCents(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    /**
     * Hydrates a BudgetPlan instance from a database result row.
     *
     * @param object $row Raw row object returned by $wpdb->get_row() / get_results().
     * @return self
     */
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
