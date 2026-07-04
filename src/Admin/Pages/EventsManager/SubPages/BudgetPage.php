<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\BudgetItem;
use EventsInviteManager\Models\BudgetLineItem;
use EventsInviteManager\Models\BudgetPlan;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\Vendor;

/**
 * Budget planning admin page.
 *
 * Actions handled:
 *   save_budget_plan      — create or update a plan + its event assignments
 *   delete_budget_plan    — delete a plan (cascades to line items)
 *   save_budget_line_item — create or update a line item
 *   delete_budget_line_item — delete a line item
 */
final class BudgetPage extends AbstractAdminPage
{
    /**
     * AJAX: searches line items within a single budget plan.
     *
     * Expected GET params: nonce, plan_id, query, sort, order, field.
     */
    public function handleAjaxSearchLineItems(): void
    {
        check_ajax_referer('eim_search_budget_line_items_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $planId   = (int) ($_GET['plan_id']   ?? 0);
        $query    = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort     = sanitize_key($_GET['sort']     ?? 'sort_order');
        $order    = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $field    = sanitize_key($_GET['field']    ?? '');
        $vendorId = max(0, (int) ($_GET['vendor_id'] ?? 0));

        $plan  = $planId > 0 ? BudgetPlan::find($planId) : null;
        if ($plan === null) {
            wp_send_json_error('Plan not found.', 404);
        }

        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = $this->perPageParam();

        $all   = BudgetLineItem::searchForPlan($planId, $query, $sort, $order, $field, $vendorId);
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderLineItemRows($items, $plan, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * AJAX: searches the budget plans list table.
     *
     * Expected GET params: nonce, query, sort, order.
     */
    public function handleAjaxSearchPlans(): void
    {
        check_ajax_referer('eim_search_budget_plans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort  = $this->sanitizeBudgetSortKey(sanitize_key($_GET['sort']  ?? 'name'));
        $order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $field = sanitize_key($_GET['field']  ?? '');
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = $this->perPageParam();

        $all   = BudgetPlan::listForAdmin($query, $sort, $order, $field);
        $total = count($all);
        $plans = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderPlanRows($plans, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * Dispatches budget-page form submissions and GET actions.
     *
     * @param string $action The action slug from eim_action / action param.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_budget_plan'        => $this->handleSavePlan(),
            'delete_budget_plan'      => $this->handleDeletePlan(),
            'bulk_delete_budget_plans' => $this->handleBulkDeletePlans(),
            'save_budget_line_item'   => $this->handleSaveLineItem(),
            'delete_budget_line_item' => $this->handleDeleteLineItem(),
            'bulk_delete_budget_line_items' => $this->handleBulkDeleteLineItems(),
            'export_budget_csv'             => $this->handleExportBudgetCsv(),
            'export_budget_json'            => $this->handleExportBudgetJson(),
            default                         => null,
        };
    }

    /** Renders the Budget admin page, routing to the list, add form, or plan detail view. */
    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderPlanForm(null),
            'edit'  => $this->renderPlanDetail(BudgetPlan::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderPlanList(),
        };
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    /** Handles creating or updating a budget plan from the admin form. */
    private function handleSavePlan(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_budget_plan')) {
            wp_die('Security check failed.');
        }

        $id          = (int) ($_POST['plan_id'] ?? 0);
        $name        = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $targetRaw   = str_replace(['$', ',', ' '], '', wp_unslash($_POST['target_amount'] ?? '0'));
        $targetCents = max(0, (int) round((float) $targetRaw * 100));

        $rawEventIds = wp_unslash($_POST['event_ids'] ?? []);
        $eventIds    = is_array($rawEventIds) ? array_map('intval', $rawEventIds) : [];
        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        if (empty($name)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'budget_name_required',
            ]));
            exit;
        }

        $data = ['name' => $name, 'description' => $description, 'target_amount_cents' => $targetCents];

        if ($id > 0) {
            BudgetPlan::update($id, $data);
            if (array_key_exists('event_ids', $_POST)) {
                BudgetPlan::setEvents($id, $eventIds);
            }
            if (array_key_exists('category_ids', $_POST)) {
                Category::syncToEntity('budget_plan', $id, array_map('intval', (array) $_POST['category_ids']));
            }
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'      => 'edit',
                'id'          => $id,
                'eim_message' => 'budget_plan_updated',
            ]));
        } else {
            $plan = BudgetPlan::create($data);
            if ($plan === null) {
                wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                    'action'    => 'add',
                    'eim_error' => 'budget_save_failed',
                ]));
                exit;
            }
            BudgetPlan::setEvents($plan->id, $eventIds);
            Category::syncToEntity('budget_plan', $plan->id, $categoryIds);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'      => 'edit',
                'id'          => $plan->id,
                'eim_message' => 'budget_plan_created',
            ]));
        }
        exit;
    }

    /** Handles deleting a budget plan (cascades to its line items) via a GET nonce link. */
    private function handleDeletePlan(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'eim_delete_budget_plan_' . $id)) {
            wp_die('Security check failed.');
        }
        Category::syncToEntity('budget_plan', $id, []);
        BudgetPlan::delete($id);
        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_message' => 'budget_plan_deleted']));
        exit;
    }

    private function handleBulkDeletePlans(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_budget_plans')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('budget_plan', $id, []);
            BudgetPlan::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    /** Handles creating or updating a budget line item from the admin form. */
    private function handleSaveLineItem(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_budget_line_item')) {
            wp_die('Security check failed.');
        }

        $itemId       = (int) ($_POST['line_item_id'] ?? 0);
        $planId       = (int) ($_POST['plan_id']       ?? 0);
        $eventId      = (int) ($_POST['event_id']      ?? 0);
        $quantityMode = sanitize_key($_POST['quantity_mode'] ?? 'fixed');

        // Ownership: the plan must exist and be accessible.
        $plan = $planId > 0 ? BudgetPlan::find($planId) : null;
        if ($plan === null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_error' => 'invalid_request']));
            exit;
        }

        // When editing an existing item, verify it belongs to the posted plan.
        $existing = null;
        if ($itemId > 0) {
            $existing = BudgetLineItem::find($itemId);
            if ($existing === null || $existing->planId !== $planId) {
                wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                    'action'    => 'edit',
                    'id'        => $planId,
                    'eim_error' => 'invalid_request',
                ]));
                exit;
            }
        }

        // Validate that the posted event_id is actually linked to this plan.
        if ($eventId > 0 && !in_array($eventId, $plan->eventIds(), true)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'    => 'edit',
                'id'        => $planId,
                'eim_error' => 'invalid_request',
            ]));
            exit;
        }

        // per_attending mode requires an event to be selected.
        if ($quantityMode === BudgetLineItem::QUANTITY_MODE_PER_ATTENDING && $eventId <= 0) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'    => 'edit',
                'id'        => $planId,
                'eim_error' => 'per_attending_needs_event',
            ]) . '#eim-btab-line-items');
            exit;
        }

        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        if ($label === '') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'    => 'edit',
                'id'        => $planId,
                'eim_error' => 'line_item_label_required',
            ]) . '#eim-btab-line-items');
            exit;
        }

        $unitRaw       = str_replace(['$', ',', ' '], '', wp_unslash($_POST['unit_cost']      ?? '0'));
        $unitCents     = max(0, (int) round((float) $unitRaw * 100));
        $paidRaw       = str_replace(['$', ',', ' '], '', wp_unslash($_POST['paid_amount']    ?? '0'));
        $paidCents     = max(0, (int) round((float) $paidRaw * 100));
        $overrideRaw   = str_replace(['$', ',', ' '], '', wp_unslash($_POST['total_override'] ?? ''));
        $overrideCents = $overrideRaw !== '' ? max(0, (int) round((float) $overrideRaw * 100)) : 0;

        $rawDeadline      = sanitize_text_field(wp_unslash($_POST['payment_deadline'] ?? ''));
        $deadlineLocal    = str_replace('T', ' ', $rawDeadline);
        if (strlen($deadlineLocal) === 16) { $deadlineLocal .= ':00'; }
        $paymentDeadline  = ($deadlineLocal !== '' && strtotime($deadlineLocal)) ? $deadlineLocal : '';
        $categoryIds      = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        // global_item_id > 0 means the user picked an existing library item;
        // we reuse it without modifying its global fields.
        $globalItemId = max(0, (int) ($_POST['global_item_id'] ?? 0));

        $data = [
            'plan_id'              => $planId,
            'global_item_id'       => $globalItemId,
            'event_id'             => $eventId,
            'vendor_id'            => (int) ($_POST['vendor_id'] ?? 0),
            'label'                => $label,
            'quantity'             => max(0.01, (float) ($_POST['quantity'] ?? 1)),
            'quantity_mode'        => $quantityMode,
            'unit_cost_cents'      => $unitCents,
            'total_override_cents' => $overrideCents,
            'paid_amount_cents'    => $paidCents,
            'website_url'          => esc_url_raw(wp_unslash($_POST['website_url'] ?? '')),
            'payment_deadline'     => $paymentDeadline,
            'notes'                => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
            'image_attachment_id'  => $this->sanitizeLineItemImageAttachmentId((int) ($_POST['image_attachment_id'] ?? 0)),
        ];

        if ($itemId > 0) {
            BudgetLineItem::update($itemId, $data);
            // Sync categories to the global item so they're shared across all plans.
            // $existing is guaranteed non-null here — validated and redirected-on-null above.
            if ($existing !== null && $existing->globalItemId > 0) {
                Category::syncToEntity('budget_item', $existing->globalItemId, $categoryIds);
            }
        } else {
            $newItem = BudgetLineItem::create($data);
            if ($newItem && $newItem->globalItemId > 0) {
                Category::syncToEntity('budget_item', $newItem->globalItemId, $categoryIds);
            }
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
            'action'      => 'edit',
            'id'          => $planId,
            'eim_message' => 'line_item_saved',
        ]) . '#eim-btab-line-items');
        exit;
    }

    /** Handles deleting a budget line item after verifying ownership of the plan. */
    private function handleDeleteLineItem(): void
    {
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $planId = (int) ($_GET['plan_id'] ?? 0);
        $nonce  = (string) ($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'eim_delete_budget_line_item_' . $itemId)) {
            wp_die('Security check failed.');
        }

        // Ownership: verify the item belongs to the stated plan before deleting.
        $item = $itemId > 0 ? BudgetLineItem::find($itemId) : null;
        if ($item === null || $item->planId !== $planId) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'    => 'edit',
                'id'        => $planId,
                'eim_error' => 'invalid_request',
            ]));
            exit;
        }

        // Do NOT clear global-item categories here — the global item still exists
        // and may be used in other plans. Category clearing happens only when the
        // global item itself is deleted from the library.
        BudgetLineItem::delete($itemId);
        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
            'action'      => 'edit',
            'id'          => $planId,
            'eim_message' => 'line_item_deleted',
        ]) . '#eim-btab-line-items');
        exit;
    }

    private function handleBulkDeleteLineItems(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_budget_line_items')) {
            wp_die('Security check failed.');
        }

        $planId = (int) ($_POST['plan_id'] ?? 0);
        $plan   = $planId > 0 ? BudgetPlan::find($planId) : null;
        if ($plan === null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_error' => 'invalid_request']));
            exit;
        }

        $redirectUrl = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'edit', 'id' => $planId]);

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect($redirectUrl . '&eim_error=bulk_invalid_action#eim-btab-line-items');
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect($redirectUrl . '&eim_error=bulk_no_selection#eim-btab-line-items');
            exit;
        }

        foreach ($ids as $id) {
            $item = BudgetLineItem::find($id);
            if ($item !== null && $item->planId === $planId) {
                // Don't clear global-item categories — the global item may still be used elsewhere.
                BudgetLineItem::delete($id);
            }
        }

        wp_redirect($redirectUrl . '&eim_message=bulk_deleted#eim-btab-line-items');
        exit;
    }

    /** Streams a CSV export of a budget plan and all its line items. */
    private function handleExportBudgetCsv(): void
    {
        $planId = (int) ($_GET['plan_id'] ?? 0);
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'eim_export_budget_' . $planId)) {
            wp_die('Security check failed.');
        }

        $plan = BudgetPlan::find($planId);
        if ($plan === null) {
            wp_die('Budget plan not found.');
        }

        $items      = BudgetLineItem::searchForPlan($planId, '', 'sort_order', 'asc');
        $events     = $plan->events();
        $vendorMap  = $this->buildVendorMapForItems($items);

        $filename = sanitize_file_name('budget-plan-' . $planId . '-' . $plan->name . '-export.csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');

        // ── Plan Details ───────────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'BUDGET PLAN']);
        fputcsv($out, ['Plan Name',      $plan->name]);
        fputcsv($out, ['Description',    $plan->description]);
        fputcsv($out, ['Currency',       $plan->currency]);
        fputcsv($out, ['Target Amount',  $plan->targetAmountCents > 0 ? $plan->formattedTarget() : '']);
        fputcsv($out, ['Estimated Total', $plan->formattedEstimated()]);
        fputcsv($out, ['Paid',           $plan->formattedPaid()]);
        fputcsv($out, ['Remaining',      $plan->formattedRemaining()]);
        fputcsv($out, ['Linked Events',  implode(', ', array_map(static fn(Event $e) => $e->name, $events))]);
        fputcsv($out, []);

        // ── Vendors ────────────────────────────────────────────────────────────
        if (!empty($vendorMap)) {
            fputcsv($out, ['SECTION', 'VENDORS']);
            fputcsv($out, ['Vendor ID', 'Company Name', 'Contact Name', 'Email', 'Phone', 'Website URL']);
            foreach ($vendorMap as $vid => $vendor) {
                fputcsv($out, [$vid, $vendor->companyName, $vendor->contactName, $vendor->email, $vendor->phone, $vendor->websiteUrl]);
            }
            fputcsv($out, []);
        }

        // ── Line Items ─────────────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'LINE ITEMS']);
        fputcsv($out, [
            'Label', 'Vendor ID', 'Vendor Name', 'Quantity', 'Quantity Mode',
            'Unit Cost', 'Total Override', 'Estimated Cost',
            'Paid Amount', 'Remaining', 'Payment Deadline', 'Notes',
        ]);
        foreach ($items as $item) {
            $v = $item->vendorId ? ($vendorMap[$item->vendorId] ?? null) : null;
            fputcsv($out, [
                $item->label,
                $item->vendorId ?? '',
                $v?->companyName ?? '',
                number_format($item->quantity, 2),
                $item->quantityMode === BudgetLineItem::QUANTITY_MODE_PER_ATTENDING ? 'Per Attending Guest' : 'Fixed',
                $item->unitCostCents   > 0 ? '$' . number_format($item->unitCostCents   / 100, 2) : '',
                $item->totalOverrideCents !== null ? '$' . number_format($item->totalOverrideCents / 100, 2) : '',
                $item->estimatedCents() > 0 ? '$' . number_format($item->estimatedCents() / 100, 2) : '',
                $item->paidAmountCents  > 0 ? '$' . number_format($item->paidAmountCents  / 100, 2) : '',
                '$' . number_format(max(0, $item->estimatedCents() - $item->paidAmountCents) / 100, 2),
                $item->paymentDeadline ?? '',
                $item->notes ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /** Streams a JSON export of a budget plan and all its line items. */
    private function handleExportBudgetJson(): void
    {
        $planId = (int) ($_GET['plan_id'] ?? 0);
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'eim_export_budget_' . $planId)) {
            wp_die('Security check failed.');
        }

        $plan = BudgetPlan::find($planId);
        if ($plan === null) {
            wp_die('Budget plan not found.');
        }

        $items     = BudgetLineItem::searchForPlan($planId, '', 'sort_order', 'asc');
        $events    = $plan->events();
        $vendorMap = $this->buildVendorMapForItems($items);

        $payload = [
            'exported_at' => current_time('mysql'),
            'plan'        => [
                'id'                  => $plan->id,
                'name'                => $plan->name,
                'description'         => $plan->description,
                'currency'            => $plan->currency,
                'target_amount_cents' => $plan->targetAmountCents,
                'target_formatted'    => $plan->targetAmountCents > 0 ? $plan->formattedTarget() : null,
                'estimated_cents'     => $plan->estimatedCents(),
                'estimated_formatted' => $plan->formattedEstimated(),
                'paid_cents'          => $plan->paidCents(),
                'paid_formatted'      => $plan->formattedPaid(),
                'remaining_cents'     => max(0, $plan->estimatedCents() - $plan->paidCents()),
                'remaining_formatted' => $plan->formattedRemaining(),
                'linked_events'       => array_map(static fn(Event $e): array => [
                    'id'   => $e->id,
                    'name' => $e->name,
                ], $events),
            ],
            'vendors' => array_values(array_map(static fn(Vendor $v): array => [
                'id'           => $v->id,
                'company_name' => $v->companyName,
                'contact_name' => $v->contactName,
                'email'        => $v->email,
                'phone'        => $v->phone,
                'website_url'  => $v->websiteUrl,
            ], $vendorMap)),
            'line_items' => array_map(fn(BudgetLineItem $i): array => [
                'id'                    => $i->id,
                'label'                 => $i->label,
                'vendor_id'             => $i->vendorId,
                'vendor_name'           => $i->vendorId ? ($vendorMap[$i->vendorId]?->companyName ?? null) : null,
                'quantity'              => $i->quantity,
                'quantity_mode'         => $i->quantityMode,
                'unit_cost_cents'       => $i->unitCostCents,
                'unit_cost_formatted'   => $i->unitCostCents > 0 ? '$' . number_format($i->unitCostCents / 100, 2) : null,
                'total_override_cents'  => $i->totalOverrideCents,
                'estimated_cents'       => $i->estimatedCents(),
                'estimated_formatted'   => $i->estimatedCents() > 0 ? '$' . number_format($i->estimatedCents() / 100, 2) : null,
                'paid_amount_cents'     => $i->paidAmountCents,
                'paid_formatted'        => $i->paidAmountCents > 0 ? '$' . number_format($i->paidAmountCents / 100, 2) : null,
                'remaining_cents'       => max(0, $i->estimatedCents() - $i->paidAmountCents),
                'payment_deadline'      => $i->paymentDeadline ?: null,
                'notes'                 => $i->notes ?: null,
            ], $items),
        ];

        $filename = sanitize_file_name('budget-plan-' . $planId . '-' . $plan->name . '-export.json');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Builds a [vendor_id => Vendor] map for a set of line items.
     * Fetches all referenced vendors in a single query.
     *
     * @param BudgetLineItem[] $items
     * @return array<int,Vendor>
     */
    private function buildVendorMapForItems(array $items): array
    {
        $vendorIds = array_values(array_unique(array_filter(
            array_map(static fn(BudgetLineItem $i) => $i->vendorId, $items)
        )));

        if (empty($vendorIds)) {
            return [];
        }

        $map = [];
        foreach (Vendor::findMany($vendorIds) as $id => $vendor) {
            $map[(int) $id] = $vendor;
        }
        return $map;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    /** Renders the budget plans list table with search bar and sortable columns. */
    private function renderPlanList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s']     ?? ''));
        $sort    = sanitize_key($_GET['sort']  ?? 'name');
        $order   = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $all    = BudgetPlan::listForAdmin($search, $sort, $order);
        $total  = count($all);
        $plans  = array_slice($all, 0, 10);
        $addUrl = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'add']);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Budget Plans</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">New Budget Plan</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Create budget plans to track costs across one or more events. Each plan holds line items for
                catering, venues, rentals, gifts, and any other expenses.
            </p>

            <?php $this->renderSearchBar(
                'eim-budget-plan-search',
                'eim-budget-plan-count',
                'eim-budget-plan-loading',
                'Search plans or events…',
                $total,
                $search,
                [
                    ['value' => 'name',        'label' => 'Plan Name'],
                    ['value' => 'description', 'label' => 'Description'],
                    ['value' => 'events',      'label' => 'Events'],
                ],
                ''
            ); ?>

            <?php $this->renderBulkActions(
                'eim-budget-plans-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET),
                'bulk_delete_budget_plans',
                'eim_bulk_delete_budget_plans'
            ); ?>

            <table id="eim-budget-plans-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:8px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('budget-plans'); ?>
                        <th style="width:22%;"><?= $this->sortLink('Plan Name', 'name',      AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:18%;"><?= $this->sortLink('Events',    'events',    AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:11%;"><?= $this->sortLink('Target',    'target',    AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:11%;"><?= $this->sortLink('Estimated', 'estimated', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:10%;"><?= $this->sortLink('Paid',      'paid',      AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:14%;">Categories</th>
                        <th style="width:12%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-budget-plans-table-body">
                    <?php $this->renderPlanRows($plans, $search); ?>
                </tbody>
            </table>

            <?php $this->renderPaginationBar('eim-budget-plan-search'); ?>

            <?php if (empty($all) && $search === ''): ?>
                <p style="margin-top:12px;">No budget plans yet. <a href="<?= esc_url($addUrl); ?>">Create your first plan.</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders budget plan table rows — used for both the initial page load and AJAX responses.
     *
     * @param BudgetPlan[] $plans
     */
    private function renderPlanRows(array $plans, string $search = '', int $offset = 0): void
    {
        if (empty($plans)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No budget plans found.';
            echo $this->renderNoResultsRow($msg);
            return;
        }

        $planIds    = array_map(static fn(BudgetPlan $p): int => $p->id, $plans);
        $catsByPlan = Category::forEntities('budget_plan', $planIds);

        foreach ($plans as $i => $plan) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'edit', 'id' => $plan->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'delete_budget_plan', 'id' => $plan->id]),
                'eim_delete_budget_plan_' . $plan->id
            );
            $events = $plan->events();
            $cats   = $catsByPlan[$plan->id] ?? [];
            ?>
            <tr>
                <?= $this->renderLeadingCells('eim-budget-plans-bulk-form', 'budget-plans', $plan->id, $plan->name, $offset + $i + 1); ?>
                <td><strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($plan->name); ?></a></strong>
                    <?php if ($plan->description): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html(wp_trim_words($plan->description, 8, '…')); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($events)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <?= esc_html(implode(', ', array_map(static fn(Event $e) => $e->name, $events))); ?>
                    <?php endif; ?>
                </td>
                <td><?= esc_html($plan->targetAmountCents > 0 ? $plan->formattedTarget() : '—'); ?></td>
                <td><?= esc_html($plan->formattedEstimated()); ?></td>
                <td style="color:<?= $plan->paidCents() > 0 ? '#00a32a' : '#999'; ?>">
                    <?= esc_html($plan->paidCents() > 0 ? $plan->formattedPaid() : '—'); ?>
                </td>
                <td>
                    <?php foreach ($cats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($cats)): ?><span style="color:#999;">—</span><?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete budget plan &ldquo;<?= esc_js($plan->name); ?>&rdquo; and all its line items?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Renders the add / edit form for a budget plan.
     *
     * @param BudgetPlan|null $plan Existing plan to edit, or null when creating.
     */
    private function renderPlanForm(?BudgetPlan $plan): void
    {
        $isNew   = $plan === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $backUrl = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET);
        $title   = $isNew ? 'New Budget Plan' : 'Edit Plan: ' . $plan->name;
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Budget Plans</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET)); ?>">
                <?php wp_nonce_field('eim_save_budget_plan'); ?>
                <input type="hidden" name="eim_action" value="save_budget_plan">
                <input type="hidden" name="plan_id"    value="<?= esc_attr($isNew ? 0 : $plan->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_bp_name">Plan Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_bp_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $plan->name); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bp_desc">Description</label></th>
                        <td><textarea id="eim_bp_desc" name="description" class="large-text" rows="3"><?= esc_textarea($isNew ? '' : $plan->description); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bp_target">Target Budget</label></th>
                        <td>
                            <input type="text" id="eim_bp_target" name="target_amount" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : number_format($plan->targetAmountCents / 100, 2)); ?>"
                                   placeholder="0.00">
                            <p class="description">Optional overall budget ceiling (e.g. 15000.00).</p>
                        </td>
                    </tr>
                    <?php
                    // Build pre-formatted event data for the picker.
                    $linkedEvents    = $isNew ? [] : $plan->events();
                    $dateFormat      = (string) get_option('date_format', 'M j, Y');
                    $formatDt = static function (?string $utcDt, string $tz) use ($dateFormat): string {
                        if (!$utcDt) return '';
                        $dt = new \DateTime($utcDt, new \DateTimeZone('UTC'));
                        if ($tz !== '') { try { $dt->setTimezone(new \DateTimeZone($tz)); } catch (\Throwable) {} }
                        return $dt->format($dateFormat . ', g:i A');
                    };
                    $linkedEventData = array_map(static fn(Event $e): array => [
                        'id'          => $e->id,
                        'name'        => $e->name,
                        'start_label' => $formatDt($e->startDatetime, $e->timezone),
                        'end_label'   => $e->endDatetime ? $formatDt($e->endDatetime, $e->timezone) : '',
                        'start_raw'   => $e->startDatetime ?? '',
                        'end_raw'     => $e->endDatetime   ?? '',
                    ], $linkedEvents);
                    ?>
                    <tr>
                        <th scope="row">Events</th>
                        <td>
                            <?php $this->renderEventPicker('eim-budget-event-picker', $linkedEventData, 'event_ids[]'); ?>
                            <p class="description" style="margin-top:8px;">Associate this budget plan with one or more events.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php
                            $selCats  = [];
                            $catNonce = wp_create_nonce('eim_suggest_categories_nonce');
                            if (!$isNew) {
                                foreach (Category::forEntity('budget_plan', $plan->id) as $cat) {
                                    $selCats[] = [
                                        'id'          => $cat->id,
                                        'name'        => $cat->name,
                                        'parent_name' => $cat->parentName,
                                        'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                    ];
                                }
                            }
                            $this->renderCategoryPicker('eim-budget-plan-cat-picker', $selCats, $catNonce);
                            ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Create Plan' : 'Update Plan'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Renders the detailed view for a single budget plan, including summary, line items, and plan settings form.
     *
     * @param BudgetPlan|null $plan The plan to render, or null if not found.
     */
    private function renderPlanDetail(?BudgetPlan $plan): void
    {
        if ($plan === null) {
            $this->renderError('Budget plan not found.', AdminMenu::tabUrl(AdminMenu::TAB_BUDGET));
            return;
        }

        $message   = (string) ($_GET['eim_message'] ?? '');
        $error     = (string) ($_GET['eim_error']   ?? '');
        $backUrl   = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET);
        $events    = $plan->events();
        $allEvents = Event::all();

        // Initial line items with default sort
        $liSort  = $this->sanitizeLineItemSortKey(sanitize_key($_GET['li_sort']  ?? 'sort_order'));
        $liOrder = strtolower((string) ($_GET['li_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $liAll    = BudgetLineItem::searchForPlan($plan->id, '', $liSort, $liOrder);
        $liTotal  = count($liAll);
        $liItems  = array_slice($liAll, 0, 10);

        // Build vendor list for the filter dropdown (only vendors used by this plan's line items).
        $liVendorIds    = array_values(array_unique(array_filter(
            array_map(static fn(BudgetLineItem $i) => $i->vendorId, $liAll)
        )));
        $liVendorsMap   = !empty($liVendorIds) ? Vendor::findMany($liVendorIds) : [];
        $liVendorOptions = [];
        foreach ($liVendorIds as $vid) {
            $v = $liVendorsMap[$vid] ?? null;
            if ($v !== null) {
                $liVendorOptions[$vid] = $v->companyName;
            }
        }
        asort($liVendorOptions);
        $planCats = Category::forEntity('budget_plan', $plan->id);

        // Payments tab — counts only; the full lists are loaded via AJAX on accordion expand.
        $needsPaymentCount = count(array_filter(
            $liAll,
            static fn(BudgetLineItem $i): bool => $i->paidAmountCents < $i->estimatedCents()
        ));
        $paidCount = count(array_filter(
            $liAll,
            static fn(BudgetLineItem $i): bool => $i->estimatedCents() > 0 && $i->paidAmountCents >= $i->estimatedCents()
        ));

        // Count needs-payment items whose deadline falls within the next 30 days (or is already past).
        $thirtyDaysFromNow        = strtotime('+30 days');
        $needsPaymentDueSoonCount = count(array_filter(
            $liAll,
            static fn(BudgetLineItem $i): bool =>
                $i->paidAmountCents < $i->estimatedCents() &&
                !empty($i->paymentDeadline) &&
                strtotime($i->paymentDeadline) <= $thirtyDaysFromNow
        ));

        // Pre-format linked event data for the event picker.
        $dateFormat      = (string) get_option('date_format', 'M j, Y');
        $formatDt = static function (?string $utcDt, string $tz) use ($dateFormat): string {
            if (!$utcDt) return '';
            $dt = new \DateTime($utcDt, new \DateTimeZone('UTC'));
            if ($tz !== '') { try { $dt->setTimezone(new \DateTimeZone($tz)); } catch (\Throwable) {} }
            return $dt->format($dateFormat . ', g:i A');
        };
        $linkedEventData = array_map(static fn(Event $e): array => [
            'id'          => $e->id,
            'name'        => $e->name,
            'start_label' => $formatDt($e->startDatetime, $e->timezone),
            'end_label'   => $e->endDatetime ? $formatDt($e->endDatetime, $e->timezone) : '',
            'start_raw'   => $e->startDatetime ?? '',
            'end_raw'     => $e->endDatetime   ?? '',
        ], $events);

        ?>
        <div class="wrap">
            <h1><?= esc_html('Budget Plan: ' . $plan->name); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Budget Plans</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <?php /* Plan name edit bar */ ?>
            <div style="display:flex;align-items:center;gap:12px;margin:12px 0;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex-wrap:wrap;">
                <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET)); ?>" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php wp_nonce_field('eim_save_budget_plan'); ?>
                    <input type="hidden" name="eim_action"    value="save_budget_plan">
                    <input type="hidden" name="plan_id"       value="<?= esc_attr($plan->id); ?>">
                    <input type="hidden" name="description"   value="<?= esc_attr($plan->description); ?>">
                    <input type="hidden" name="target_amount" value="<?= esc_attr($plan->targetAmountCents > 0 ? number_format($plan->targetAmountCents / 100, 2) : ''); ?>">
                    <label for="eim_bp_name_inline" style="font-weight:600;white-space:nowrap;">Plan Name:</label>
                    <input type="text" id="eim_bp_name_inline" name="name" class="regular-text"
                           value="<?= esc_attr($plan->name); ?>" required style="min-width:260px;">
                    <?php submit_button('Rename', 'secondary small', 'submit', false); ?>
                </form>
            </div>

            <?php /* Plan summary bar */ ?>
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin:16px 0;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;">
                <?php if ($plan->targetAmountCents > 0): ?>
                    <div>
                        <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Target</div>
                        <div style="font-size:20px;font-weight:600;"><?= esc_html($plan->formattedTarget()); ?></div>
                    </div>
                <?php endif; ?>
                <div>
                    <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Estimated Total</div>
                    <div style="font-size:20px;font-weight:600;"><?= esc_html($plan->formattedEstimated()); ?></div>
                </div>
                <?php if ($plan->targetAmountCents > 0): ?>
                    <div>
                        <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Difference</div>
                        <div style="font-size:20px;font-weight:600;color:<?= $plan->differenceCents() < 0 ? '#d63638' : '#00a32a'; ?>;"><?= esc_html($plan->formattedDifference()); ?></div>
                    </div>
                <?php endif; ?>
                <div>
                    <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Paid</div>
                    <div style="font-size:20px;font-weight:600;color:#00a32a;"><?= esc_html($plan->formattedPaid()); ?></div>
                </div>
                <div>
                    <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Remaining</div>
                    <div style="font-size:20px;font-weight:600;color:#d63638;"><?= esc_html($plan->formattedRemaining()); ?></div>
                </div>
                <?php if (!empty($events)): ?>
                    <div style="margin-left:auto;align-self:center;">
                        <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Events</div>
                        <div style="font-size:13px;"><?= esc_html(implode(', ', array_map(static fn(Event $e) => $e->name, $events))); ?></div>
                    </div>
                <?php endif; ?>
            </div>


            <?php
            $exportBudgetCsvUrl  = wp_nonce_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'export_budget_csv',  'plan_id' => $plan->id]), 'eim_export_budget_' . $plan->id);
            $exportBudgetJsonUrl = wp_nonce_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'export_budget_json', 'plan_id' => $plan->id]), 'eim_export_budget_' . $plan->id);
            ?>
            <div style="display:flex;gap:8px;align-items:center;margin:12px 0 0;">
                <a href="<?= esc_url($exportBudgetCsvUrl); ?>"  class="button button-secondary">⬇ Export to CSV</a>
                <a href="<?= esc_url($exportBudgetJsonUrl); ?>" class="button button-secondary">⬇ Export to JSON</a>
            </div>

            <?php /* ── Budget plan sub-tabs ─────────────────────────────── */ ?>
            <nav class="nav-tab-wrapper eim-budget-plan-tabs" data-plan-id="<?= esc_attr($plan->id); ?>" style="margin-top:16px;">
                <a href="#eim-btab-settings"   class="nav-tab" data-btab="settings">Plan Settings</a>
                <a href="#eim-btab-line-items"  class="nav-tab" data-btab="line-items">Line Items</a>
                <a href="#eim-btab-payments"    class="nav-tab" data-btab="payments">Payments</a>
            </nav>

            <?php /* ── Plan Settings panel ──────────────────────────────── */ ?>
            <div id="eim-btab-settings" class="eim-btab-panel">
                <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET)); ?>">
                    <?php wp_nonce_field('eim_save_budget_plan'); ?>
                    <input type="hidden" name="eim_action" value="save_budget_plan">
                    <input type="hidden" name="plan_id"    value="<?= esc_attr($plan->id); ?>">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="eim_bp_name2">Plan Name</label></th>
                            <td><input type="text" id="eim_bp_name2" name="name" class="large-text"
                                       value="<?= esc_attr($plan->name); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_bp_desc2">Description</label></th>
                            <td><textarea id="eim_bp_desc2" name="description" class="large-text" rows="2"><?= esc_textarea($plan->description); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_bp_target2">Target Budget</label></th>
                            <td><input type="text" id="eim_bp_target2" name="target_amount" class="large-text"
                                       value="<?= esc_attr($plan->targetAmountCents > 0 ? number_format($plan->targetAmountCents / 100, 2) : ''); ?>"
                                       placeholder="0.00"></td>
                        </tr>
                        <tr>
                            <th scope="row">Events</th>
                            <td>
                                <?php $this->renderEventPicker('eim-budget-event-picker', $linkedEventData, 'event_ids[]'); ?>
                                <p class="description" style="margin-top:8px;">Associate this budget plan with one or more events.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Categories</label></th>
                            <td>
                                <?php
                                $selCats2 = [];
                                foreach ($planCats as $cat) {
                                    $selCats2[] = [
                                        'id'          => $cat->id,
                                        'name'        => $cat->name,
                                        'parent_name' => $cat->parentName,
                                        'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                    ];
                                }
                                $this->renderCategoryPicker('eim-budget-plan-cat-picker2', $selCats2, wp_create_nonce('eim_suggest_categories_nonce'));
                                ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Update Plan Settings', 'secondary'); ?>
                </form>
            </div>

            <?php /* ── Line Items panel ───────────────────────────────────── */ ?>
            <div id="eim-btab-line-items" class="eim-btab-panel">

                <?php $this->renderSearchBar(
                    'eim-line-item-search',
                    'eim-line-item-count',
                    'eim-line-item-loading',
                    'Search line items…',
                    $liTotal,
                    '',
                    [
                        ['value' => 'label',  'label' => 'Label'],
                        ['value' => 'event',  'label' => 'Event'],
                        ['value' => 'vendor', 'label' => 'Vendor'],
                    ],
                    ''
                ); ?>

                <?php if (!empty($liVendorOptions)): ?>
                <div style="display:flex;align-items:center;gap:8px;margin:6px 0 10px;">
                    <label for="eim-line-item-vendor-filter" style="font-weight:600;white-space:nowrap;">Filter by Vendor:</label>
                    <select id="eim-line-item-vendor-filter" class="eim-search-field-select">
                        <option value="">— All Vendors —</option>
                        <?php foreach ($liVendorOptions as $vid => $vName): ?>
                            <option value="<?= esc_attr($vid); ?>"><?= esc_html($vName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="eim-li-vendor-clear" class="button button-small" style="display:none;">Clear</button>
                </div>
                <?php endif; ?>

                <?php $this->renderBulkActions(
                    'eim-line-items-bulk-form',
                    AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'edit', 'id' => $plan->id]),
                    'bulk_delete_budget_line_items',
                    'eim_bulk_delete_budget_line_items',
                    ['plan_id' => $plan->id]
                ); ?>

                <table id="eim-line-items-table"
                       class="wp-list-table widefat fixed striped"
                       style="margin-top:8px;margin-bottom:20px;"
                       data-plan-id="<?= esc_attr($plan->id); ?>"
                       data-sort="<?= esc_attr($liSort); ?>"
                       data-order="<?= esc_attr($liOrder); ?>"
                       data-total="<?= esc_attr($liTotal); ?>">
                    <thead>
                        <tr>
                            <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('budget-line-items-' . $plan->id); ?></th>
                            <th class="eim-li-image-column" style="width:52px;">Image</th>
                            <th style="width:14%;"><?= $this->lineItemSortLink('Label',      'label',      $liSort, $liOrder); ?></th>
                            <th style="width:9%;">Categories</th>
                            <th style="width:9%;"><?= $this->lineItemSortLink('Event',       'event',      $liSort, $liOrder); ?></th>
                            <th style="width:6%;"><?=  $this->lineItemSortLink('Qty',        'quantity',   $liSort, $liOrder); ?></th>
                            <th style="width:8%;"><?=  $this->lineItemSortLink('Unit Cost',  'unit_cost',  $liSort, $liOrder); ?></th>
                            <th style="width:8%;"><?=  $this->lineItemSortLink('Estimated',  'estimated',  $liSort, $liOrder); ?></th>
                            <th style="width:6%;"><?=  $this->lineItemSortLink('Paid',       'paid',       $liSort, $liOrder); ?></th>
                            <th style="width:5%;"><?=  $this->lineItemSortLink('Website',    'website_url',$liSort, $liOrder); ?></th>
                            <th style="width:9%;"><?=  $this->lineItemSortLink('Deadline',   'deadline',   $liSort, $liOrder); ?></th>
                            <th style="width:7%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="eim-line-items-table-body">
                        <?php $this->renderLineItemRows($liItems, $plan); ?>
                    </tbody>
                </table>

                <?php $this->renderPaginationBar('eim-line-item-search'); ?>

                <h2 style="margin-top:24px;" id="eim-li-form-title">Add Line Item</h2>
                <?php $this->renderAddLineItemForm($plan, $allEvents); ?>

            </div><!-- /.eim-btab-line-items -->

            <?php /* ── Payments panel ───────────────────────────────────── */ ?>
            <div id="eim-btab-payments" class="eim-btab-panel">

                <?php $this->renderPaymentAccordionSection('needs_payment', 'Needs Payment', $needsPaymentCount, $plan->id, $needsPaymentDueSoonCount, $liVendorOptions); ?>

                <?php $this->renderPaymentAccordionSection('paid', 'Paid', $paidCount, $plan->id, 0, $liVendorOptions); ?>

            </div><!-- /.eim-btab-payments -->

        </div><!-- /.wrap -->

        <style>
        .eim-btab-panel { display: none; }
        .eim-btab-panel.eim-btab-active { display: block; }
        </style>
        <script>
        (() => {
            'use strict';
            const nav = document.querySelector('.eim-budget-plan-tabs');
            if (!nav) return;

            const planId     = nav.dataset.planId || '0';
            const storageKey = 'eim_budget_plan_tab_' + planId;
            const panels     = ['settings', 'line-items', 'payments'];
            const getEl      = (id) => document.getElementById('eim-btab-' + id);

            const activateTab = (slug) => {
                panels.forEach(s => getEl(s)?.classList.remove('eim-btab-active'));
                nav.querySelectorAll('[data-btab]').forEach(l => l.classList.remove('nav-tab-active'));
                getEl(slug)?.classList.add('eim-btab-active');
                nav.querySelector('[data-btab="' + slug + '"]')?.classList.add('nav-tab-active');
                try { localStorage.setItem(storageKey, slug); } catch (e) {}
            };

            // Expose so admin-budget.js can activate a tab (e.g. switching from Payments → Line Items).
            window.eimBudgetPlanActivateTab = activateTab;

            nav.addEventListener('click', (e) => {
                const link = e.target.closest('[data-btab]');
                if (!link) return;
                e.preventDefault();
                activateTab(link.dataset.btab);
            });

            // Activate from URL hash (e.g. #eim-btab-line-items after saving a line item).
            const hash = window.location.hash.slice(1);
            let initialTab = 'settings';
            if (panels.some(s => hash === 'eim-btab-' + s)) {
                initialTab = hash.slice('eim-btab-'.length);
            } else {
                try {
                    const saved = localStorage.getItem(storageKey);
                    if (saved && panels.includes(saved)) initialTab = saved;
                } catch (e) {}
            }
            activateTab(initialTab);
        })();
        </script>
        <?php
    }

    /**
     * Renders the inline add/edit line item form below the plan detail table.
     *
     * @param BudgetPlan $plan      The parent budget plan.
     * @param Event[]    $allEvents All events in the system, for the event selector.
     */
    private function renderAddLineItemForm(BudgetPlan $plan, array $allEvents): void
    {
        $linkedEventIds = $plan->eventIds();
        $planEvents     = array_values(array_filter($allEvents, static fn(Event $e) => in_array($e->id, $linkedEventIds, true)));

        // Collect menu items from linked events for quick-add suggestions
        $menuSuggestions = [];
        foreach ($planEvents as $event) {
            foreach (MenuItem::forEvent($event->id) as $mi) {
                if (!isset($menuSuggestions[$mi->id])) {
                    $menuSuggestions[$mi->id] = $mi;
                }
            }
        }
        ?>
        <div id="eim-budget-line-item-form" style="border:1px solid #dcdcde;border-radius:4px;padding:20px;background:#f6f7f7;max-width:760px;">
            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET)); ?>">
                <?php wp_nonce_field('eim_save_budget_line_item'); ?>
                <input type="hidden" name="eim_action"    value="save_budget_line_item">
                <input type="hidden" name="plan_id"       value="<?= esc_attr($plan->id); ?>">
                <input type="hidden" name="line_item_id"  value="0">
                <input type="hidden" name="global_item_id" id="eim_li_global_item_id" value="0">

                <?php /* Library picker — pick an existing global item to reuse */ ?>
                <div id="eim-li-library-picker" style="margin-bottom:16px;padding:12px 14px;background:#fff;border:1px solid #dcdcde;border-radius:4px;">
                    <label style="font-weight:600;display:block;margin-bottom:6px;">
                        Pick from Line Items Library <span style="font-weight:400;color:#646970;font-size:12px;">(optional — or fill in the form below to create a new item)</span>
                    </label>
                    <div style="position:relative;">
                        <input type="text" id="eim_li_library_search"
                               class="regular-text"
                               placeholder="Search existing line items…"
                               autocomplete="off"
                               style="width:100%;max-width:440px;">
                        <div id="eim-li-library-dropdown"
                             style="display:none;position:absolute;background:#fff;border:1px solid #dcdcde;border-radius:4px;z-index:9999;min-width:440px;max-height:240px;overflow-y:auto;box-shadow:0 2px 8px rgba(0,0,0,.12);top:100%;left:0;"></div>
                    </div>
                    <div id="eim-li-library-selected" style="display:none;margin-top:8px;">
                        <span id="eim-li-library-selected-label" style="font-weight:600;"></span>
                        <a href="#" id="eim-li-library-clear" style="margin-left:8px;color:#646970;font-size:12px;">✕ Clear selection</a>
                        <span style="color:#646970;font-size:12px;margin-left:8px;">(global fields pre-filled from library — changes here will update the shared item)</span>
                    </div>
                </div>

                <table class="form-table" role="presentation" style="margin-bottom:0;">
                    <tr>
                        <th scope="row"><label for="eim_li_label">Label <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_li_label" name="label" class="regular-text" required placeholder="e.g. Catering deposit, DJ fee, Floral arrangements"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_vendor_search">Vendor</label></th>
                        <td>
                            <div class="eim-vendor-autocomplete" id="eim-li-vendor-picker">
                                <input type="text" id="eim_li_vendor_search"
                                       class="regular-text eim-vendor-search-input"
                                       placeholder="Search vendors…" autocomplete="off">
                                <input type="hidden" name="vendor_id" id="eim_li_vendor_id" value="0">
                                <div class="eim-vendor-selected" id="eim-li-vendor-selected" style="display:none;">
                                    <span class="eim-vendor-selected-name"></span>
                                    <a href="#" class="eim-vendor-clear" aria-label="Remove vendor" style="margin-left:6px;">&times;</a>
                                </div>
                                <div class="eim-vendor-dropdown" id="eim-li-vendor-dropdown" style="display:none;position:absolute;background:#fff;border:1px solid #dcdcde;border-radius:4px;z-index:9999;min-width:300px;max-height:220px;overflow-y:auto;box-shadow:0 2px 8px rgba(0,0,0,.12);"></div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php $this->renderCategoryPicker('eim-li-cat-picker', [], wp_create_nonce('eim_suggest_categories_nonce')); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_event">Event (optional)</label></th>
                        <td>
                            <select id="eim_li_event" name="event_id">
                                <option value="0">— Plan-wide (no specific event) —</option>
                                <?php foreach ($planEvents as $event): ?>
                                    <option value="<?= esc_attr($event->id); ?>"><?= esc_html($event->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Assign to a specific event, or leave as plan-wide.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_qty_mode">Quantity Mode</label></th>
                        <td>
                            <select id="eim_li_qty_mode" name="quantity_mode">
                                <option value="fixed">Fixed quantity</option>
                                <option value="per_attending">Per attending guest (auto from RSVP)</option>
                            </select>
                            <p class="description">"Per attending guest" multiplies the unit cost by the RSVP attending count for the selected event.</p>
                        </td>
                    </tr>
                    <tr id="eim_li_qty_row">
                        <th scope="row"><label for="eim_li_qty">Quantity</label></th>
                        <td><input type="number" id="eim_li_qty" name="quantity" class="small-text" value="1" min="0.01" step="0.01"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_unit_cost">Unit Cost</label></th>
                        <td>
                            <input type="text" id="eim_li_unit_cost" name="unit_cost" class="regular-text" placeholder="0.00">
                            <?php if (!empty($menuSuggestions)): ?>
                                <p class="description">
                                    Quick-fill from menu item price:
                                    <?php foreach ($menuSuggestions as $mi): ?>
                                        <?php if ($mi->priceCents > 0): ?>
                                            <a href="#" class="eim-budget-price-fill"
                                               data-label="<?= esc_attr($mi->label); ?>"
                                               data-price="<?= esc_attr(number_format($mi->priceCents / 100, 2)); ?>"
                                               style="margin-right:8px;">
                                                <?= esc_html($mi->label); ?> (<?= esc_html($mi->formattedPrice()); ?>)
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_paid">Amount Paid</label></th>
                        <td><input type="text" id="eim_li_paid" name="paid_amount" class="regular-text" value="0.00" placeholder="0.00"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_website_url">Website URL</label></th>
                        <td><input type="url" id="eim_li_website_url" name="website_url" class="regular-text" placeholder="https://"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_payment_deadline">Payment Deadline</label></th>
                        <td>
                            <input type="datetime-local" id="eim_li_payment_deadline" name="payment_deadline" class="regular-text">
                            <p class="description">Optional reminder deadline for this payment.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_notes">Notes</label></th>
                        <td><textarea id="eim_li_notes" name="notes" class="large-text" rows="2" placeholder="Optional notes"></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Image</th>
                        <td>
                            <input type="hidden" id="eim_li_image_attachment_id" name="image_attachment_id" value="0">
                            <div class="eim-li-image-picker">
                                <div id="eim_li_image_preview" class="eim-li-image-preview">
                                    <span class="description">No image selected.</span>
                                </div>
                                <p class="eim-li-image-actions">
                                    <button type="button" id="eim_li_image_select" class="button"
                                            data-select-label="Select Image" data-change-label="Change Image">Select Image</button>
                                    <button type="button" id="eim_li_image_remove" class="button" hidden>Remove Image</button>
                                </p>
                            </div>
                            <p class="description" style="margin-top:6px;">Optional. Choose an image from the WordPress Media Library.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Add Line Item', 'primary', 'submit', false, ['id' => 'eim-li-submit']); ?>
                <p id="eim-li-cancel-wrap" style="display:none;margin-top:4px;">
                    <a href="#" id="eim-li-cancel" style="color:#646970;">← Cancel edit, add new item instead</a>
                </p>
            </form>
        </div>

        <script>
        (() => {
            const qtyModeSelect = document.getElementById('eim_li_qty_mode');
            const qtyRow = document.getElementById('eim_li_qty_row');
            if (qtyModeSelect && qtyRow) {
                qtyModeSelect.addEventListener('change', () => {
                    qtyRow.style.display = qtyModeSelect.value === 'per_attending' ? 'none' : '';
                });
            }

            document.querySelectorAll('.eim-budget-price-fill').forEach(link => {
                link.addEventListener('click', e => {
                    e.preventDefault();
                    const labelInput = document.getElementById('eim_li_label');
                    const costInput  = document.getElementById('eim_li_unit_cost');
                    if (labelInput && !labelInput.value) labelInput.value = link.dataset.label;
                    if (costInput)  costInput.value = link.dataset.price;
                });
            });
        })();
        </script>
        <?php
    }

    private function sanitizeLineItemImageAttachmentId(int $attachmentId): int
    {
        if ($attachmentId <= 0) return 0;
        $post = get_post($attachmentId);
        return ($post && $post->post_type === 'attachment') ? $attachmentId : 0;
    }

    /**
     * Sanitizes a budget plan table sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'name'.
     */
    private function sanitizeBudgetSortKey(string $key): string
    {
        return in_array($key, ['name', 'events', 'target', 'estimated', 'paid'], true)
            ? $key
            : 'name';
    }

    /**
     * Sanitizes a line-item table sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'sort_order'.
     */
    private function sanitizeLineItemSortKey(string $key): string
    {
        return in_array($key, ['sort_order', 'label', 'vendor', 'event', 'quantity', 'unit_cost', 'estimated', 'paid', 'website_url', 'deadline'], true)
            ? $key
            : 'sort_order';
    }

    /**
     * AJAX: returns filtered + searched line items for the Payments tab accordion.
     *
     * GET params: nonce, plan_id, status (needs_payment|paid), query, sort, order, field, page, per_page.
     */
    public function handleAjaxSearchPaymentItems(): void
    {
        check_ajax_referer('eim_search_budget_payment_items_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $planId   = (int) ($_GET['plan_id']   ?? 0);
        $status   = in_array(($_GET['status'] ?? ''), ['needs_payment', 'paid'], true) ? $_GET['status'] : 'needs_payment';
        $query    = sanitize_text_field(wp_unslash($_GET['query']  ?? ''));
        $sort     = $this->sanitizeLineItemSortKey(sanitize_key($_GET['sort']  ?? 'deadline'));
        $order    = strtolower((string) ($_GET['order']  ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $field    = sanitize_key($_GET['field']    ?? '');
        $vendorId = max(0, (int) ($_GET['vendor_id'] ?? 0));
        $page     = max(1, (int) ($_GET['page']    ?? 1));
        $perPage  = $this->perPageParam();

        $plan = $planId > 0 ? BudgetPlan::find($planId) : null;
        if ($plan === null) {
            wp_send_json_error('Plan not found.', 404);
        }

        $all = BudgetLineItem::searchForPlan($planId, $query, $sort, $order, $field, $vendorId);

        $filtered = $status === 'paid'
            ? array_values(array_filter($all, static fn(BudgetLineItem $i): bool => $i->estimatedCents() > 0 && $i->paidAmountCents >= $i->estimatedCents()))
            : array_values(array_filter($all, static fn(BudgetLineItem $i): bool => $i->paidAmountCents < $i->estimatedCents()));

        $total = count($filtered);
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        // Count items in the filtered set due within 30 days (needs_payment only).
        $thirtyDaysFromNow = strtotime('+30 days');
        $dueSoonCount = $status === 'needs_payment'
            ? count(array_filter(
                $filtered,
                static fn(BudgetLineItem $i): bool =>
                    !empty($i->paymentDeadline) && strtotime($i->paymentDeadline) <= $thirtyDaysFromNow
            ))
            : 0;

        ob_start();
        $this->renderPaymentRows($items, $status);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total, 'due_soon_count' => $dueSoonCount]);
    }

    /**
     * Renders the accordion section for a payment status group.
     *
     * @param int $dueSoonCount Number of items whose deadline is within 30 days (needs_payment only).
     */
    private function renderPaymentAccordionSection(string $status, string $heading, int $count, int $planId, int $dueSoonCount = 0, array $vendorOptions = []): void
    {
        $topMargin = $status === 'needs_payment' ? '16px' : '8px';
        ?>
        <div class="eim-payment-section"
             data-status="<?= esc_attr($status); ?>"
             data-plan-id="<?= esc_attr($planId); ?>"
             data-due-soon="<?= esc_attr($dueSoonCount); ?>"
             style="margin-top:<?= esc_attr($topMargin); ?>;">

            <?php /* Accordion header */ ?>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;border:1px solid #dcdcde;border-radius:4px;">
                <button type="button" class="button-link eim-payment-accordion-toggle"
                        aria-expanded="false"
                        style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;padding:0;cursor:pointer;">
                    <span class="eim-payment-accordion-arrow" aria-hidden="true"
                          style="font-size:11px;min-width:12px;transition:transform .15s;">▶</span>
                    <?= esc_html($heading); ?>
                </button>
                <span class="eim-payment-section-count" style="font-size:13px;color:#646970;">
                    <?= esc_html($count); ?> item<?= $count !== 1 ? 's' : ''; ?>
                </span>
                <?php if ($status === 'needs_payment'): ?>
                    <span class="eim-payment-due-soon-count"
                          style="font-size:13px;color:#d63638;font-weight:600;<?= $dueSoonCount === 0 ? 'display:none;' : ''; ?>">
                        ⚠ <?= esc_html($dueSoonCount); ?> due within a month
                    </span>
                <?php endif; ?>
                <span class="spinner eim-payment-spinner" style="float:none;margin:0;"></span>
            </div>

            <?php /* Collapsible body */ ?>
            <div class="eim-payment-section-body" hidden style="border:1px solid #dcdcde;border-top:none;border-radius:0 0 4px 4px;padding:12px 14px 4px;">

                <?php /* Search row */ ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                    <input type="text" class="eim-payment-search-input regular-text"
                           placeholder="Search line items…" autocomplete="off">
                    <select class="eim-payment-search-field">
                        <option value="">All columns</option>
                        <option value="label">Label</option>
                        <option value="event">Event</option>
                        <option value="vendor">Vendor</option>
                    </select>
                    <select class="eim-payment-per-page" style="height:28px;">
                        <option value="5">5 / page</option>
                        <option value="10" selected>10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
                <?php if (!empty($vendorOptions)): ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                    <label style="font-weight:600;white-space:nowrap;font-size:13px;">Filter by Vendor:</label>
                    <select class="eim-payment-vendor-filter eim-search-field-select">
                        <option value="">— All Vendors —</option>
                        <?php foreach ($vendorOptions as $vid => $vName): ?>
                            <option value="<?= esc_attr($vid); ?>"><?= esc_html($vName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="eim-payment-vendor-clear button button-small" style="display:none;">Clear</button>
                </div>
                <?php endif; ?>

                <?php /* Table */ ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:8px;">
                    <thead>
                        <tr>
                            <th style="width:52px;">Image</th>
                            <th style="width:15%;"><?= $this->paymentSortLink('Label',     'label'); ?></th>
                            <th style="width:9%;">Categories</th>
                            <th style="width:9%;"><?= $this->paymentSortLink('Event',     'event'); ?></th>
                            <th style="width:6%;"><?=  $this->paymentSortLink('Qty',       'quantity'); ?></th>
                            <th style="width:8%;"><?=  $this->paymentSortLink('Unit Cost', 'unit_cost'); ?></th>
                            <th style="width:8%;"><?=  $this->paymentSortLink('Estimated', 'estimated'); ?></th>
                            <th style="width:7%;"><?=  $this->paymentSortLink('Paid',      'paid'); ?></th>
                            <th style="width:5%;"><?=  $this->paymentSortLink('Website',   'website_url'); ?></th>
                            <th style="width:9%;"><?= $this->paymentSortLink('Deadline',  'deadline'); ?></th>
                            <th style="width:7%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="eim-payment-tbody">
                        <tr><td colspan="11" style="color:#999;font-style:italic;">Loading…</td></tr>
                    </tbody>
                </table>

                <?php /* Pagination */ ?>
                <nav class="eim-payment-pagination" style="margin:4px 0 10px;"></nav>

            </div><!-- /.eim-payment-section-body -->

        </div><!-- /.eim-payment-section -->
        <?php
    }

    /**
     * Renders payment-tab rows (no bulk checkbox — 11 columns including Actions).
     *
     * @param BudgetLineItem[] $items
     */
    private function renderPaymentRows(array $items, string $status = ''): void
    {
        if (empty($items)) {
            $msg = $status === 'paid'
                ? 'No line items have been fully paid yet.'
                : 'All line items have been paid — nothing outstanding.';
            echo '<tr class="eim-no-results"><td colspan="11">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $vendorIds  = array_values(array_filter(array_map(static fn(BudgetLineItem $i) => $i->vendorId, $items)));
        $vendorsMap = Vendor::findMany($vendorIds);
        $globalIds  = array_values(array_filter(array_map(static fn(BudgetLineItem $i) => $i->globalItemId > 0 ? $i->globalItemId : null, $items)));
        $catsByGlobal = $globalIds ? Category::forEntities('budget_item', $globalIds) : [];
        $planCountMap = $globalIds ? BudgetItem::planCountsForIds($globalIds) : [];

        foreach ($items as $item) {
            $vendor       = $item->vendorId ? ($vendorsMap[$item->vendorId] ?? null) : null;
            $itemCats     = $catsByGlobal[$item->globalItemId] ?? [];
            $planUseCount = $item->globalItemId > 0 ? ($planCountMap[$item->globalItemId] ?? 0) : 0;
            $linkedEvent  = $item->eventId ? Event::find($item->eventId) : null;
            $qtyDisplay   = $item->quantityMode === BudgetLineItem::QUANTITY_MODE_PER_ATTENDING
                ? ($linkedEvent ? $linkedEvent->registeredCount() . ' (attending)' : '— (attending)')
                : number_format($item->quantity, $item->quantity == (int) $item->quantity ? 0 : 2);
            $deadlineLabel = '';
            if ($item->paymentDeadline) {
                try {
                    $dt = new \DateTime($item->paymentDeadline);
                    $deadlineLabel = $dt->format('M j, Y g:i a');
                } catch (\Throwable) {
                    $deadlineLabel = $item->paymentDeadline;
                }
            }
            $deadlinePast  = $item->paymentDeadline && strtotime($item->paymentDeadline) < time();
            $dueSoon       = $status === 'needs_payment' && !empty($item->paymentDeadline) && strtotime($item->paymentDeadline) <= strtotime('+30 days');
            $catsJson      = wp_json_encode(array_values(array_map(static fn(Category $c): array => [
                'id'          => $c->id,
                'name'        => $c->name,
                'parent_name' => $c->parentName,
                'label'       => $c->parentName ? $c->parentName . ' › ' . $c->name : $c->name,
            ], $itemCats)));
            $deadlineInput = $item->paymentDeadline
                ? substr(str_replace(' ', 'T', $item->paymentDeadline), 0, 16)
                : '';
            $imageThumbUrl = $item->imageAttachmentId > 0 ? (wp_get_attachment_image_url($item->imageAttachmentId, 'thumbnail') ?: '') : '';
            $imageFullUrl  = $item->imageAttachmentId > 0 ? (wp_get_attachment_image_url($item->imageAttachmentId, 'full') ?: '') : '';
            ?>
            <tr<?= $dueSoon ? ' class="eim-pay-due-soon"' : ''; ?>>
                <td><?= $this->lineItemImageThumbnailMarkup($item->imageAttachmentId, $item->label); ?></td>
                <td>
                    <strong><?= esc_html($item->label); ?></strong>
                    <?php if ($planUseCount > 1): ?>
                        <span title="Shared across <?= esc_attr((string) $planUseCount); ?> budget plans"
                              style="display:inline-block;margin-left:5px;padding:1px 5px;font-size:10px;font-weight:600;background:#dbe5f4;color:#2271b1;border-radius:3px;vertical-align:middle;">
                            Shared &times;<?= esc_html((string) $planUseCount); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($vendor): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html($vendor->companyName); ?></span>
                    <?php endif; ?>
                    <?php if ($item->notes): ?>
                        <br><span style="color:#999;font-size:11px;font-style:italic;"><?= esc_html(wp_trim_words($item->notes, 8, '…')); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php foreach ($itemCats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip" style="font-size:11px;display:inline-block;margin-bottom:2px;"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($itemCats)): ?><span style="color:#999;">—</span><?php endif; ?>
                </td>
                <td><?= esc_html($linkedEvent ? $linkedEvent->name : '—'); ?></td>
                <td><?= esc_html($qtyDisplay); ?></td>
                <td><?= esc_html($item->formattedUnitCost()); ?></td>
                <td><strong><?= esc_html($item->formattedEstimated()); ?></strong></td>
                <td style="color:<?= $item->paidAmountCents > 0 ? '#00a32a' : '#999'; ?>">
                    <?= esc_html($item->paidAmountCents > 0 ? $item->formattedPaid() : '—'); ?>
                </td>
                <td>
                    <?php if ($item->websiteUrl): ?>
                        <a href="<?= esc_url($item->websiteUrl); ?>" target="_blank" rel="noopener" style="font-size:12px;">Visit</a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="<?= $deadlinePast ? 'color:#d63638;' : ''; ?>font-size:12px;">
                    <?= $deadlineLabel ? esc_html($deadlineLabel) : '<span style="color:#999;">—</span>'; ?>
                </td>
                <td>
                    <a href="#eim-budget-line-item-form"
                       class="eim-edit-line-item"
                       data-id="<?= esc_attr($item->id); ?>"
                       data-global-item-id="<?= esc_attr((string) $item->globalItemId); ?>"
                       data-label="<?= esc_attr($item->label); ?>"
                       data-vendor-id="<?= esc_attr((string) ($item->vendorId ?? 0)); ?>"
                       data-vendor-name="<?= esc_attr($vendor ? $vendor->companyName : ''); ?>"
                       data-event-id="<?= esc_attr((string) ($item->eventId ?? 0)); ?>"
                       data-quantity-mode="<?= esc_attr($item->quantityMode); ?>"
                       data-quantity="<?= esc_attr((string) $item->quantity); ?>"
                       data-unit-cost="<?= esc_attr($item->unitCostCents > 0 ? number_format($item->unitCostCents / 100, 2) : ''); ?>"
                       data-paid="<?= esc_attr($item->paidAmountCents > 0 ? number_format($item->paidAmountCents / 100, 2) : '0.00'); ?>"
                       data-website-url="<?= esc_attr($item->websiteUrl); ?>"
                       data-payment-deadline="<?= esc_attr($deadlineInput); ?>"
                       data-categories="<?= esc_attr($catsJson); ?>"
                       data-notes="<?= esc_attr($item->notes); ?>"
                       data-image-attachment-id="<?= esc_attr((string) $item->imageAttachmentId); ?>"
                       data-image-thumb-url="<?= esc_attr($imageThumbUrl); ?>"
                       data-image-full-url="<?= esc_attr($imageFullUrl); ?>"
                       data-image-title="<?= esc_attr($item->label); ?>">Edit</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Generates an AJAX sort link for the Payments tab columns.
     *
     * Uses class `eim-pay-sort-link` so the PaymentSection JS handles it
     * independently from the main line-items AJAX sort links.
     */
    private function paymentSortLink(string $label, string $key): string
    {
        return sprintf(
            '<a href="#" class="eim-pay-sort-link" data-sort="%s" data-order="asc">%s <span aria-hidden="true"></span></a>',
            esc_attr($key),
            esc_html($label)
        );
    }

    /**
     * Generates a sort link for line-item columns.
     *
     * Line items are sorted via AJAX, so the link carries only data-sort / data-order
     * attributes — the JS reads these and fires the search request.
     */
    private function lineItemSortLink(string $label, string $key, string $currentSort, string $currentOrder): string
    {
        $isCurrent = $currentSort === $key;
        $nextOrder = $isCurrent && $currentOrder === 'asc' ? 'desc' : 'asc';
        $indicator = $isCurrent ? ($currentOrder === 'asc' ? '^' : 'v') : '';

        return sprintf(
            '<a href="#" class="eim-sort-link" data-sort="%s" data-order="%s">%s <span aria-hidden="true">%s</span></a>',
            esc_attr($key),
            esc_attr($nextOrder),
            esc_html($label),
            esc_html($indicator)
        );
    }

    /**
     * Renders line-item table rows — used for both the initial page load and AJAX responses.
     *
     * @param BudgetLineItem[] $items
     */
    private function renderLineItemRows(array $items, BudgetPlan $plan, string $search = '', string $emptyMsg = ''): void
    {
        if (empty($items)) {
            if ($emptyMsg === '') {
                $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No line items yet. Use the form below to add your first cost.';
            } else {
                $msg = $emptyMsg;
            }
            echo '<tr class="eim-no-results"><td colspan="12">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $vendorIds   = array_values(array_filter(array_map(static fn(BudgetLineItem $i) => $i->vendorId, $items)));
        $vendorsMap  = Vendor::findMany($vendorIds);

        // Categories live on the global item, keyed by globalItemId.
        $globalIds    = array_values(array_filter(array_map(static fn(BudgetLineItem $i) => $i->globalItemId > 0 ? $i->globalItemId : null, $items)));
        $catsByGlobal = $globalIds ? Category::forEntities('budget_item', $globalIds) : [];
        $planCountMap = $globalIds ? BudgetItem::planCountsForIds($globalIds) : [];

        foreach ($items as $item) {
            $vendor        = $item->vendorId ? ($vendorsMap[$item->vendorId] ?? null) : null;
            $itemCats      = $catsByGlobal[$item->globalItemId] ?? [];
            $planUseCount  = $item->globalItemId > 0 ? ($planCountMap[$item->globalItemId] ?? 0) : 0;
            $deleteItemUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                    'action'  => 'delete_budget_line_item',
                    'item_id' => $item->id,
                    'plan_id' => $plan->id,
                ]),
                'eim_delete_budget_line_item_' . $item->id
            );
            $linkedEvent   = $item->eventId ? Event::find($item->eventId) : null;
            $qtyDisplay    = $item->quantityMode === BudgetLineItem::QUANTITY_MODE_PER_ATTENDING
                ? ($linkedEvent ? $linkedEvent->registeredCount() . ' (attending)' : '— (attending)')
                : number_format($item->quantity, $item->quantity == (int) $item->quantity ? 0 : 2);
            $deadlineLabel = '';
            if ($item->paymentDeadline) {
                try {
                    $dt = new \DateTime($item->paymentDeadline);
                    $deadlineLabel = $dt->format('M j, Y g:i a');
                } catch (\Throwable) {
                    $deadlineLabel = $item->paymentDeadline;
                }
            }
            $deadlinePast  = $item->paymentDeadline && strtotime($item->paymentDeadline) < time();
            $catsJson      = wp_json_encode(array_values(array_map(static fn(Category $c): array => [
                'id'          => $c->id,
                'name'        => $c->name,
                'parent_name' => $c->parentName,
                'label'       => $c->parentName ? $c->parentName . ' › ' . $c->name : $c->name,
            ], $itemCats)));
            // datetime-local value for the edit form: convert stored "Y-m-d H:i:s" to "Y-m-d\TH:i"
            $deadlineInput = $item->paymentDeadline
                ? substr(str_replace(' ', 'T', $item->paymentDeadline), 0, 16)
                : '';
            $imageThumbUrl = $item->imageAttachmentId > 0 ? (wp_get_attachment_image_url($item->imageAttachmentId, 'thumbnail') ?: '') : '';
            $imageFullUrl  = $item->imageAttachmentId > 0 ? (wp_get_attachment_image_url($item->imageAttachmentId, 'full') ?: '') : '';
            ?>
            <tr>
                <?= $this->renderBulkSelectCell('eim-line-items-bulk-form', 'budget-line-items-' . $plan->id, $item->id, $item->label); ?>
                <td><?= $this->lineItemImageThumbnailMarkup($item->imageAttachmentId, $item->label); ?></td>
                <td>
                    <strong><?= esc_html($item->label); ?></strong>
                    <?php if ($planUseCount > 1): ?>
                        <span title="This item is shared across <?= esc_attr((string) $planUseCount); ?> budget plans"
                              style="display:inline-block;margin-left:5px;padding:1px 5px;font-size:10px;font-weight:600;background:#dbe5f4;color:#2271b1;border-radius:3px;vertical-align:middle;">
                            Shared &times;<?= esc_html((string) $planUseCount); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($vendor): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html($vendor->companyName); ?></span>
                    <?php endif; ?>
                    <?php if ($item->notes): ?>
                        <br><span style="color:#999;font-size:11px;font-style:italic;"><?= esc_html(wp_trim_words($item->notes, 8, '…')); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php foreach ($itemCats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip" style="font-size:11px;display:inline-block;margin-bottom:2px;"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($itemCats)): ?><span style="color:#999;">—</span><?php endif; ?>
                </td>
                <td><?= esc_html($linkedEvent ? $linkedEvent->name : '—'); ?></td>
                <td><?= esc_html($qtyDisplay); ?></td>
                <td><?= esc_html($item->formattedUnitCost()); ?></td>
                <td><strong><?= esc_html($item->formattedEstimated()); ?></strong></td>
                <td style="color:<?= $item->paidAmountCents > 0 ? '#00a32a' : '#999'; ?>">
                    <?= esc_html($item->paidAmountCents > 0 ? $item->formattedPaid() : '—'); ?>
                </td>
                <td>
                    <?php if ($item->websiteUrl): ?>
                        <a href="<?= esc_url($item->websiteUrl); ?>" target="_blank" rel="noopener" style="font-size:12px;">Visit</a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="<?= $deadlinePast ? 'color:#d63638;' : ''; ?>font-size:12px;">
                    <?= $deadlineLabel ? esc_html($deadlineLabel) : '<span style="color:#999;">—</span>'; ?>
                </td>
                <td>
                    <a href="#eim-budget-line-item-form"
                       class="eim-edit-line-item"
                       data-id="<?= esc_attr($item->id); ?>"
                       data-global-item-id="<?= esc_attr((string) $item->globalItemId); ?>"
                       data-label="<?= esc_attr($item->label); ?>"
                       data-vendor-id="<?= esc_attr((string) ($item->vendorId ?? 0)); ?>"
                       data-vendor-name="<?= esc_attr($vendor ? $vendor->companyName : ''); ?>"
                       data-event-id="<?= esc_attr((string) ($item->eventId ?? 0)); ?>"
                       data-quantity-mode="<?= esc_attr($item->quantityMode); ?>"
                       data-quantity="<?= esc_attr((string) $item->quantity); ?>"
                       data-unit-cost="<?= esc_attr($item->unitCostCents > 0 ? number_format($item->unitCostCents / 100, 2) : ''); ?>"
                       data-paid="<?= esc_attr($item->paidAmountCents > 0 ? number_format($item->paidAmountCents / 100, 2) : '0.00'); ?>"
                       data-website-url="<?= esc_attr($item->websiteUrl); ?>"
                       data-payment-deadline="<?= esc_attr($deadlineInput); ?>"
                       data-categories="<?= esc_attr($catsJson); ?>"
                       data-notes="<?= esc_attr($item->notes); ?>"
                       data-image-attachment-id="<?= esc_attr((string) $item->imageAttachmentId); ?>"
                       data-image-thumb-url="<?= esc_attr($imageThumbUrl); ?>"
                       data-image-full-url="<?= esc_attr($imageFullUrl); ?>"
                       data-image-title="<?= esc_attr($item->label); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteItemUrl); ?>"
                       onclick="return confirm('Delete line item &ldquo;<?= esc_js($item->label); ?>&rdquo;?');">Delete</a>
                    <?php if ($item->globalItemId > 0): ?>
                        | <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['action' => 'edit', 'id' => $item->globalItemId])); ?>"
                             style="color:#646970;font-size:11px;" title="View in global library">Library ↗</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }
}
