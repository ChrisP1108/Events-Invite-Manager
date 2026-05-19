<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\BudgetLineItem;
use EventsInviteManager\Models\BudgetPlan;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\MenuItem;

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

        $planId = (int) ($_GET['plan_id'] ?? 0);
        $query  = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort   = sanitize_key($_GET['sort']  ?? 'sort_order');
        $order  = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $field  = sanitize_key($_GET['field']  ?? '');

        $plan  = $planId > 0 ? BudgetPlan::find($planId) : null;
        if ($plan === null) {
            wp_send_json_error('Plan not found.', 404);
        }

        $items = BudgetLineItem::searchForPlan($planId, $query, $sort, $order, $field);

        ob_start();
        $this->renderLineItemRows($items, $plan, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => count($items)]);
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
        $plans = BudgetPlan::listForAdmin($query, $sort, $order, $field);

        ob_start();
        $this->renderPlanRows($plans, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => count($plans)]);
    }

    /**
     * Dispatches budget-page form submissions and GET actions.
     *
     * @param string $action The action slug from eim_action / action param.
     */
    public function handleAction(string $action): void
    {
        DatabaseManager::maybeCreateBudgetTables();

        match ($action) {
            'save_budget_plan'        => $this->handleSavePlan(),
            'delete_budget_plan'      => $this->handleDeletePlan(),
            'save_budget_line_item'   => $this->handleSaveLineItem(),
            'delete_budget_line_item' => $this->handleDeleteLineItem(),
            default                   => null,
        };
    }

    /** Renders the Budget admin page, routing to the list, add form, or plan detail view. */
    public function renderPage(): void
    {
        DatabaseManager::maybeCreateBudgetTables();

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
            BudgetPlan::setEvents($id, $eventIds);
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
        BudgetPlan::delete($id);
        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['eim_message' => 'budget_plan_deleted']));
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
            ]) . '#eim-budget-line-items');
            exit;
        }

        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        if ($label === '') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                'action'    => 'edit',
                'id'        => $planId,
                'eim_error' => 'line_item_label_required',
            ]) . '#eim-budget-line-items');
            exit;
        }

        $unitRaw       = str_replace(['$', ',', ' '], '', wp_unslash($_POST['unit_cost']      ?? '0'));
        $unitCents     = max(0, (int) round((float) $unitRaw * 100));
        $paidRaw       = str_replace(['$', ',', ' '], '', wp_unslash($_POST['paid_amount']    ?? '0'));
        $paidCents     = max(0, (int) round((float) $paidRaw * 100));
        $overrideRaw   = str_replace(['$', ',', ' '], '', wp_unslash($_POST['total_override'] ?? ''));
        $overrideCents = $overrideRaw !== '' ? max(0, (int) round((float) $overrideRaw * 100)) : 0;

        $data = [
            'plan_id'              => $planId,
            'event_id'             => $eventId,
            'category'             => sanitize_key($_POST['category']   ?? 'other'),
            'label'                => $label,
            'quantity'             => max(0.01, (float) ($_POST['quantity'] ?? 1)),
            'quantity_mode'        => $quantityMode,
            'unit_cost_cents'      => $unitCents,
            'total_override_cents' => $overrideCents,
            'paid_amount_cents'    => $paidCents,
            'vendor_name'          => sanitize_text_field(wp_unslash($_POST['vendor_name'] ?? '')),
            'notes'                => sanitize_textarea_field(wp_unslash($_POST['notes']   ?? '')),
        ];

        if ($itemId > 0) {
            BudgetLineItem::update($itemId, $data);
        } else {
            BudgetLineItem::create($data);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
            'action'      => 'edit',
            'id'          => $planId,
            'eim_message' => 'line_item_saved',
        ]) . '#eim-budget-line-items');
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

        BudgetLineItem::delete($itemId);
        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
            'action'      => 'edit',
            'id'          => $planId,
            'eim_message' => 'line_item_deleted',
        ]) . '#eim-budget-line-items');
        exit;
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
        $plans   = BudgetPlan::listForAdmin($search, $sort, $order);
        $addUrl  = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'add']);
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
                count($plans),
                $search,
                [
                    ['value' => 'name',        'label' => 'Plan Name'],
                    ['value' => 'description', 'label' => 'Description'],
                    ['value' => 'events',      'label' => 'Events'],
                ],
                ''
            ); ?>

            <table id="eim-budget-plans-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:8px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>">
                <thead>
                    <tr>
                        <th style="width:25%;"><?= $this->sortLink('Plan Name', 'name',      AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:20%;"><?= $this->sortLink('Events',    'events',    AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:12%;"><?= $this->sortLink('Target',    'target',    AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:12%;"><?= $this->sortLink('Estimated', 'estimated', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:11%;"><?= $this->sortLink('Paid',      'paid',      AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET]); ?></th>
                        <th style="width:12%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-budget-plans-table-body">
                    <?php $this->renderPlanRows($plans, $search); ?>
                </tbody>
            </table>

            <?php if (empty($plans) && $search === ''): ?>
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
    private function renderPlanRows(array $plans, string $search = ''): void
    {
        if (empty($plans)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No budget plans found.';
            echo '<tr class="eim-no-results"><td colspan="6">' . esc_html($msg) . '</td></tr>';
            return;
        }

        foreach ($plans as $plan) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'edit', 'id' => $plan->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'delete_budget_plan', 'id' => $plan->id]),
                'eim_delete_budget_plan_' . $plan->id
            );
            $events = $plan->events();
            ?>
            <tr>
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
        $allItems  = BudgetLineItem::forPlan($plan->id);
        $allEvents = Event::all();

        // Initial line items with default sort
        $liSort  = $this->sanitizeLineItemSortKey(sanitize_key($_GET['li_sort']  ?? 'sort_order'));
        $liOrder = strtolower((string) ($_GET['li_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $liItems = BudgetLineItem::searchForPlan($plan->id, '', $liSort, $liOrder);

        // Category summary (always uses all items, not filtered)
        $byCategory = [];
        foreach ($allItems as $item) {
            $byCategory[$item->category][] = $item;
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html('Budget: ' . $plan->name); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Budget Plans</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

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

            <?php /* Category summary — client-side sortable */ ?>
            <?php if (count($byCategory) >= 2): ?>
                <h2 style="margin-top:24px;">Summary by Category</h2>
                <table id="eim-budget-category-table"
                       class="wp-list-table widefat fixed striped"
                       style="max-width:600px;"
                       data-sort="category"
                       data-order="asc">
                    <thead>
                        <tr>
                            <th><a href="#" class="eim-sort-link eim-cat-sort" data-sort="category" data-order="desc">Category <span aria-hidden="true">^</span></a></th>
                            <th style="width:22%;"><a href="#" class="eim-sort-link eim-cat-sort" data-sort="estimated" data-order="asc">Estimated <span aria-hidden="true"></span></a></th>
                            <th style="width:22%;"><a href="#" class="eim-sort-link eim-cat-sort" data-sort="paid" data-order="asc">Paid <span aria-hidden="true"></span></a></th>
                        </tr>
                    </thead>
                    <tbody id="eim-budget-category-tbody">
                        <?php foreach ($byCategory as $cat => $catItems): ?>
                            <?php
                            $catEstimated = array_sum(array_map(static fn(BudgetLineItem $i) => $i->estimatedCents(), $catItems));
                            $catPaid      = array_sum(array_map(static fn(BudgetLineItem $i) => $i->paidAmountCents, $catItems));
                            $catLabel     = BudgetLineItem::CATEGORIES[$cat] ?? ucfirst($cat);
                            ?>
                            <tr>
                                <td data-val="<?= esc_attr(strtolower($catLabel)); ?>"><?= esc_html($catLabel); ?></td>
                                <td data-val="<?= esc_attr($catEstimated); ?>"><?= esc_html(BudgetPlan::formatCents($catEstimated)); ?></td>
                                <td data-val="<?= esc_attr($catPaid); ?>"><?= esc_html(BudgetPlan::formatCents($catPaid)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif (count($byCategory) === 1): ?>
                <h2 style="margin-top:24px;">Summary by Category</h2>
                <table class="wp-list-table widefat fixed striped" style="max-width:600px;">
                    <thead><tr><th>Category</th><th style="width:22%;">Estimated</th><th style="width:22%;">Paid</th></tr></thead>
                    <tbody>
                        <?php foreach ($byCategory as $cat => $catItems): ?>
                            <?php
                            $catLabel     = BudgetLineItem::CATEGORIES[$cat] ?? ucfirst($cat);
                            $catEstimated = array_sum(array_map(static fn(BudgetLineItem $i) => $i->estimatedCents(), $catItems));
                            $catPaid      = array_sum(array_map(static fn(BudgetLineItem $i) => $i->paidAmountCents, $catItems));
                            ?>
                            <tr>
                                <td><?= esc_html($catLabel); ?></td>
                                <td><?= esc_html(BudgetPlan::formatCents($catEstimated)); ?></td>
                                <td><?= esc_html(BudgetPlan::formatCents($catPaid)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php /* Line items — AJAX searchable + sortable */ ?>
            <hr id="eim-budget-line-items" style="margin:28px 0 16px;">
            <h2>Line Items</h2>

            <?php $this->renderSearchBar(
                'eim-line-item-search',
                'eim-line-item-count',
                'eim-line-item-loading',
                'Search line items…',
                count($allItems),
                '',
                [
                    ['value' => 'label',    'label' => 'Label'],
                    ['value' => 'category', 'label' => 'Category'],
                    ['value' => 'event',    'label' => 'Event'],
                    ['value' => 'vendor',   'label' => 'Vendor'],
                ],
                ''
            ); ?>

            <table id="eim-line-items-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:8px;margin-bottom:20px;"
                   data-plan-id="<?= esc_attr($plan->id); ?>"
                   data-sort="<?= esc_attr($liSort); ?>"
                   data-order="<?= esc_attr($liOrder); ?>">
                <thead>
                    <tr>
                        <th style="width:21%;"><?= $this->lineItemSortLink('Label',     'label',      $liSort, $liOrder); ?></th>
                        <th style="width:11%;"><?= $this->lineItemSortLink('Category',  'category',   $liSort, $liOrder); ?></th>
                        <th style="width:13%;"><?= $this->lineItemSortLink('Event',     'event',      $liSort, $liOrder); ?></th>
                        <th style="width:9%;"><?= $this->lineItemSortLink('Qty',        'quantity',   $liSort, $liOrder); ?></th>
                        <th style="width:10%;"><?= $this->lineItemSortLink('Unit Cost', 'unit_cost',  $liSort, $liOrder); ?></th>
                        <th style="width:10%;"><?= $this->lineItemSortLink('Estimated', 'estimated',  $liSort, $liOrder); ?></th>
                        <th style="width:10%;"><?= $this->lineItemSortLink('Paid',      'paid',       $liSort, $liOrder); ?></th>
                        <th style="width:8%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-line-items-table-body">
                    <?php $this->renderLineItemRows($liItems, $plan); ?>
                </tbody>
            </table>

            <?php /* Edit plan settings */ ?>
            <hr style="margin:24px 0 16px;">
            <h2>Plan Settings</h2>
            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET)); ?>" style="max-width:680px;">
                <?php wp_nonce_field('eim_save_budget_plan'); ?>
                <input type="hidden" name="eim_action" value="save_budget_plan">
                <input type="hidden" name="plan_id"    value="<?= esc_attr($plan->id); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_bp_name2">Plan Name</label></th>
                        <td><input type="text" id="eim_bp_name2" name="name" class="regular-text"
                                   value="<?= esc_attr($plan->name); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bp_desc2">Description</label></th>
                        <td><textarea id="eim_bp_desc2" name="description" class="large-text" rows="2"><?= esc_textarea($plan->description); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bp_target2">Target Budget</label></th>
                        <td><input type="text" id="eim_bp_target2" name="target_amount" class="regular-text"
                                   value="<?= esc_attr($plan->targetAmountCents > 0 ? number_format($plan->targetAmountCents / 100, 2) : ''); ?>"
                                   placeholder="0.00"></td>
                    </tr>
                    <tr>
                        <th scope="row">Events</th>
                        <td>
                            <?php foreach ($allEvents as $event): ?>
                                <label style="display:block;margin-bottom:3px;">
                                    <input type="checkbox" name="event_ids[]"
                                           value="<?= esc_attr($event->id); ?>"
                                           <?php checked(in_array($event->id, array_map(fn(Event $e) => $e->id, $events), true)); ?>>
                                    <?= esc_html($event->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Update Plan Settings', 'secondary'); ?>
            </form>

            <?php /* Add line item form */ ?>
            <hr style="margin:24px 0 16px;">
            <h2 id="eim-li-form-title">Add Line Item</h2>
            <?php $this->renderAddLineItemForm($plan, $allEvents); ?>
        </div>
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
                <input type="hidden" name="eim_action" value="save_budget_line_item">
                <input type="hidden" name="plan_id"    value="<?= esc_attr($plan->id); ?>">
                <input type="hidden" name="line_item_id" value="0">

                <table class="form-table" role="presentation" style="margin-bottom:0;">
                    <tr>
                        <th scope="row"><label for="eim_li_label">Label <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_li_label" name="label" class="regular-text" required placeholder="e.g. Catering deposit, DJ fee, Floral arrangements"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_category">Category</label></th>
                        <td>
                            <select id="eim_li_category" name="category">
                                <?php foreach (BudgetLineItem::CATEGORIES as $key => $label): ?>
                                    <option value="<?= esc_attr($key); ?>"><?= esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
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
                        <th scope="row"><label for="eim_li_vendor">Vendor</label></th>
                        <td><input type="text" id="eim_li_vendor" name="vendor_name" class="regular-text" placeholder="Optional vendor name"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_li_notes">Notes</label></th>
                        <td><textarea id="eim_li_notes" name="notes" class="large-text" rows="2" placeholder="Optional notes"></textarea></td>
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
        return in_array($key, ['sort_order', 'label', 'category', 'event', 'quantity', 'unit_cost', 'estimated', 'paid'], true)
            ? $key
            : 'sort_order';
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
    private function renderLineItemRows(array $items, BudgetPlan $plan, string $search = ''): void
    {
        if (empty($items)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No line items yet. Use the form below to add your first cost.';
            echo '<tr class="eim-no-results"><td colspan="8">' . esc_html($msg) . '</td></tr>';
            return;
        }

        foreach ($items as $item) {
            $deleteItemUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, [
                    'action'  => 'delete_budget_line_item',
                    'item_id' => $item->id,
                    'plan_id' => $plan->id,
                ]),
                'eim_delete_budget_line_item_' . $item->id
            );
            $linkedEvent = $item->eventId ? Event::find($item->eventId) : null;
            $qtyDisplay  = $item->quantityMode === BudgetLineItem::QUANTITY_MODE_PER_ATTENDING
                ? ($linkedEvent ? $linkedEvent->registeredCount() . ' (attending)' : '— (attending)')
                : number_format($item->quantity, $item->quantity == (int) $item->quantity ? 0 : 2);
            ?>
            <tr>
                <td>
                    <strong><?= esc_html($item->label); ?></strong>
                    <?php if ($item->vendorName): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html($item->vendorName); ?></span>
                    <?php endif; ?>
                    <?php if ($item->notes): ?>
                        <br><span style="color:#999;font-size:11px;font-style:italic;"><?= esc_html(wp_trim_words($item->notes, 8, '…')); ?></span>
                    <?php endif; ?>
                </td>
                <td><?= esc_html($item->categoryLabel()); ?></td>
                <td><?= esc_html($linkedEvent ? $linkedEvent->name : '—'); ?></td>
                <td><?= esc_html($qtyDisplay); ?></td>
                <td><?= esc_html($item->formattedUnitCost()); ?></td>
                <td><strong><?= esc_html($item->formattedEstimated()); ?></strong></td>
                <td style="color:<?= $item->paidAmountCents > 0 ? '#00a32a' : '#999'; ?>">
                    <?= esc_html($item->paidAmountCents > 0 ? $item->formattedPaid() : '—'); ?>
                </td>
                <td>
                    <a href="#eim-budget-line-item-form"
                       class="eim-edit-line-item"
                       data-id="<?= esc_attr($item->id); ?>"
                       data-label="<?= esc_attr($item->label); ?>"
                       data-category="<?= esc_attr($item->category); ?>"
                       data-event-id="<?= esc_attr((string) ($item->eventId ?? 0)); ?>"
                       data-quantity-mode="<?= esc_attr($item->quantityMode); ?>"
                       data-quantity="<?= esc_attr((string) $item->quantity); ?>"
                       data-unit-cost="<?= esc_attr($item->unitCostCents > 0 ? number_format($item->unitCostCents / 100, 2) : ''); ?>"
                       data-paid="<?= esc_attr($item->paidAmountCents > 0 ? number_format($item->paidAmountCents / 100, 2) : '0.00'); ?>"
                       data-vendor="<?= esc_attr($item->vendorName); ?>"
                       data-notes="<?= esc_attr($item->notes); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteItemUrl); ?>"
                       onclick="return confirm('Delete line item &ldquo;<?= esc_js($item->label); ?>&rdquo;?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }
}
