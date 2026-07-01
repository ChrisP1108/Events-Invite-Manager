<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\BudgetItem;
use EventsInviteManager\Models\BudgetPlan;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\Vendor;

/**
 * Budget Line Items global library admin page.
 *
 * Actions handled:
 *   save_budget_item       — create or update a global library item
 *   delete_budget_item     — delete an item (cascades usage rows)
 *   bulk_delete_budget_items — bulk delete
 *
 * AJAX actions:
 *   eim_search_budget_items   — AJAX list search
 *   eim_suggest_budget_items  — typeahead autocomplete (used on budget plan add form)
 */
final class BudgetItemsPage extends AbstractAdminPage
{
    // =========================================================================
    // AJAX handlers
    // =========================================================================

    /**
     * AJAX: searches the global budget items list table.
     *
     * Expected GET params: nonce, query, sort, order, field, page, per_page.
     */
    public function handleAjaxSearch(): void
    {
        check_ajax_referer('eim_search_budget_items_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeSortKey(sanitize_key($_GET['sort']  ?? 'label'));
        $order   = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $field   = sanitize_key($_GET['field']    ?? '');
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;

        $all   = BudgetItem::listForAdmin($query, $sort, $order, $field);
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderRows($items, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * AJAX: returns matching global items for the typeahead picker on the budget plan page.
     *
     * Expected GET params: nonce, query.
     */
    public function handleAjaxSuggest(): void
    {
        check_ajax_referer('eim_suggest_budget_items_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $items = BudgetItem::suggest($query, 12);

        $results = array_map(static function (BudgetItem $item): array {
            $vendor       = $item->vendorId ? Vendor::find($item->vendorId) : null;
            $imageThumb   = $item->imageAttachmentId > 0
                ? (wp_get_attachment_image_url($item->imageAttachmentId, 'thumbnail') ?: '')
                : '';
            return [
                'id'              => $item->id,
                'label'           => $item->label,
                'vendor_id'       => $item->vendorId ?? 0,
                'vendor_name'     => $vendor ? $vendor->companyName : '',
                'unit_cost_cents' => $item->unitCostCents,
                'unit_cost_fmt'   => $item->formattedUnitCost(),
                'website_url'     => $item->websiteUrl,
                'notes'           => $item->notes,
                'image_id'        => $item->imageAttachmentId,
                'image_thumb_url' => $imageThumb,
            ];
        }, $items);

        wp_send_json_success($results);
    }

    // =========================================================================
    // Action handler
    // =========================================================================

    public function handleAction(string $action): void
    {
        match ($action) {
            'save_budget_item'         => $this->handleSave(),
            'delete_budget_item'       => $this->handleDelete(),
            'bulk_delete_budget_items' => $this->handleBulkDelete(),
            default                    => null,
        };
    }

    // =========================================================================
    // Page render
    // =========================================================================

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderForm(null),
            'edit'  => $this->renderForm(BudgetItem::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderList(),
        };
    }

    // =========================================================================
    // Private — action handlers
    // =========================================================================

    private function handleSave(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_budget_item')) {
            wp_die('Security check failed.');
        }

        $id    = (int) ($_POST['budget_item_id'] ?? 0);
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));

        if ($label === '') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'budget_item_label_required',
            ]));
            exit;
        }

        $unitRaw   = str_replace(['$', ',', ' '], '', wp_unslash($_POST['unit_cost'] ?? '0'));
        $unitCents = max(0, (int) round((float) $unitRaw * 100));

        $data = [
            'label'               => $label,
            'vendor_id'           => (int) ($_POST['vendor_id']          ?? 0),
            'website_url'         => esc_url_raw(wp_unslash($_POST['website_url'] ?? '')),
            'notes'               => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
            'image_attachment_id' => $this->sanitizeImageAttachmentId((int) ($_POST['image_attachment_id'] ?? 0)),
            'unit_cost_cents'     => $unitCents,
        ];
        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        if ($id > 0) {
            BudgetItem::update($id, $data);
            Category::syncToEntity('budget_item', $id, $categoryIds);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, [
                'action'      => 'edit',
                'id'          => $id,
                'eim_message' => 'budget_item_updated',
            ]));
        } else {
            $item = BudgetItem::create($data);
            if ($item === null) {
                wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, [
                    'action'    => 'add',
                    'eim_error' => 'budget_item_save_failed',
                ]));
                exit;
            }
            Category::syncToEntity('budget_item', $item->id, $categoryIds);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, [
                'action'      => 'edit',
                'id'          => $item->id,
                'eim_message' => 'budget_item_created',
            ]));
        }
        exit;
    }

    private function handleDelete(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'eim_delete_budget_item_' . $id)) {
            wp_die('Security check failed.');
        }
        Category::syncToEntity('budget_item', $id, []);
        BudgetItem::delete($id);
        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['eim_message' => 'budget_item_deleted']));
        exit;
    }

    private function handleBulkDelete(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_budget_items')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('budget_item', $id, []);
            BudgetItem::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    // =========================================================================
    // Private — rendering
    // =========================================================================

    private function renderList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s']     ?? ''));
        $sort    = $this->sanitizeSortKey(sanitize_key($_GET['sort']  ?? 'label'));
        $order   = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $addUrl  = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['action' => 'add']);

        $all   = BudgetItem::listForAdmin($search, $sort, $order);
        $total = count($all);
        $items = array_slice($all, 0, 10);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Budget Line Items</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">New Line Item</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                A shared library of cost line items. Each item stores the label, vendor, unit cost, image, and notes.
                Add items to budget plans from the <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET)); ?>">Budget Plans</a> tab,
                where you can also set event assignment, quantity, and payment details per plan.
            </p>

            <?php $this->renderSearchBar(
                'eim-budget-items-search',
                'eim-budget-items-count',
                'eim-budget-items-loading',
                'Search line items…',
                $total,
                $search,
                [
                    ['value' => 'label',  'label' => 'Label'],
                    ['value' => 'vendor', 'label' => 'Vendor'],
                    ['value' => 'notes',  'label' => 'Notes'],
                ],
                ''
            ); ?>

            <?php $this->renderBulkActions(
                'eim-budget-items-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS),
                'bulk_delete_budget_items',
                'eim_bulk_delete_budget_items'
            ); ?>

            <table id="eim-budget-items-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:8px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('budget-items'); ?>
                        <th style="width:52px;">Image</th>
                        <th style="width:20%;"><?= $this->sortLink('Label',        'label',        AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET_LINE_ITEMS]); ?></th>
                        <th style="width:9%;"><?= $this->sortLink('Unit Cost',    'unit_cost',    AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET_LINE_ITEMS]); ?></th>
                        <th style="width:14%;">Notes</th>
                        <th style="width:16%;">Categories</th>
                        <th style="width:16%;"><?= $this->sortLink('Budget Plans', 'budget_plans', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_BUDGET_LINE_ITEMS]); ?></th>
                        <th style="width:9%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-budget-items-table-body">
                    <?php $this->renderRows($items, $search); ?>
                </tbody>
            </table>

            <?php $this->renderPaginationBar('eim-budget-items-search'); ?>

            <?php if (empty($all) && $search === ''): ?>
                <p style="margin-top:12px;">No line items yet. <a href="<?= esc_url($addUrl); ?>">Create your first item.</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the list-table rows for both initial load and AJAX refresh.
     *
     * @param BudgetItem[] $items
     */
    private function renderRows(array $items, string $search = '', int $offset = 0): void
    {
        if (empty($items)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No line items found.';
            echo $this->renderNoResultsRow($msg);
            return;
        }

        $itemIds       = array_map(static fn(BudgetItem $i): int => $i->id, $items);
        $planIdsByItem = BudgetItem::planIdsForItems($itemIds);
        $catsByItem    = Category::forEntities('budget_item', $itemIds);

        // Pre-load all plan names to avoid N+1.
        $allPlanIds   = array_unique(array_merge(...array_values($planIdsByItem)));
        $plansById    = [];
        foreach ($allPlanIds as $pid) {
            $p = BudgetPlan::find($pid);
            if ($p) $plansById[$pid] = $p;
        }

        foreach ($items as $i => $item) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['action' => 'edit', 'id' => $item->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS, ['action' => 'delete_budget_item', 'id' => $item->id]),
                'eim_delete_budget_item_' . $item->id
            );
            $vendor    = $item->vendorId ? Vendor::find($item->vendorId) : null;
            $planIds   = $planIdsByItem[$item->id] ?? [];
            $planCount = count($planIds);
            $itemCats  = $catsByItem[$item->id] ?? [];

            $deleteConfirm = $planCount > 0
                ? "Delete &ldquo;{$item->label}&rdquo; and remove it from {$planCount} budget plan(s)?"
                : "Delete line item &ldquo;{$item->label}&rdquo;?";
            ?>
            <tr>
                <?= $this->renderLeadingCells('eim-budget-items-bulk-form', 'budget-items', $item->id, $item->label, $offset + $i + 1); ?>
                <td><?= $this->lineItemImageThumbnailMarkup($item->imageAttachmentId, $item->label); ?></td>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($item->label); ?></a></strong>
                    <?php if ($vendor): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html($vendor->companyName); ?></span>
                    <?php endif; ?>
                    <?php if ($item->websiteUrl): ?>
                        <br><a href="<?= esc_url($item->websiteUrl); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#646970;">Visit site ↗</a>
                    <?php endif; ?>
                </td>
                <td><?= esc_html($item->formattedUnitCost()); ?></td>
                <td style="font-size:12px;color:#646970;">
                    <?= $item->notes ? esc_html(wp_trim_words($item->notes, 10, '…')) : '<span style="color:#ccc;">—</span>'; ?>
                </td>
                <td style="font-size:12px;">
                    <?php if (empty($itemCats)): ?>
                        <span style="color:#ccc;">—</span>
                    <?php else: ?>
                        <?php foreach ($itemCats as $cat): ?>
                            <?php $catUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                            <a href="<?= esc_url($catUrl); ?>" class="eim-cat-chip" style="display:inline-block;margin-bottom:2px;">
                                <?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($planIds)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <?php foreach ($planIds as $pid): ?>
                            <?php $plan = $plansById[$pid] ?? null; ?>
                            <?php if ($plan): ?>
                                <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'edit', 'id' => $plan->id])); ?>"
                                   class="eim-plan-chip"
                                   style="display:inline-block;margin-bottom:2px;"><?= esc_html($plan->name); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('<?= esc_js($deleteConfirm); ?>');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    private function renderForm(?BudgetItem $item): void
    {
        $isNew   = $item === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $backUrl = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS);
        $title   = $isNew ? 'New Line Item' : 'Edit Line Item: ' . $item->label;

        if (!$isNew) {
            $planIds  = $item->planIds();
            $planCount = count($planIds);
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Budget Line Items</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <?php if (!$isNew && isset($planCount) && $planCount > 0): ?>
                <div class="notice notice-info inline" style="margin:12px 0;">
                    <p>
                        <strong>Shared item:</strong> This line item is used in <?= esc_html((string) $planCount); ?> budget plan<?= $planCount > 1 ? 's' : ''; ?>.
                        Changes to the label, vendor, cost, or notes will apply across all plans.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET_LINE_ITEMS)); ?>">
                <?php wp_nonce_field('eim_save_budget_item'); ?>
                <input type="hidden" name="eim_action"     value="save_budget_item">
                <input type="hidden" name="budget_item_id" value="<?= esc_attr($isNew ? 0 : $item->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_bi_label">Label <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_bi_label" name="label" class="regular-text" required
                                   value="<?= esc_attr($isNew ? '' : $item->label); ?>"
                                   placeholder="e.g. Catering deposit, DJ fee, Floral arrangements"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bi_vendor_search">Vendor</label></th>
                        <td>
                            <?php
                            $vendorName = !$isNew && $item->vendorId ? (Vendor::find($item->vendorId)?->companyName ?? '') : '';
                            ?>
                            <div class="eim-vendor-autocomplete" id="eim-bi-vendor-picker">
                                <input type="text" id="eim_bi_vendor_search"
                                       class="regular-text eim-vendor-search-input"
                                       placeholder="Search vendors…" autocomplete="off"
                                       value="">
                                <input type="hidden" name="vendor_id" id="eim_bi_vendor_id"
                                       value="<?= esc_attr((string) ($isNew ? 0 : ($item->vendorId ?? 0))); ?>">
                                <div class="eim-vendor-selected" id="eim-bi-vendor-selected"
                                     style="<?= (!$isNew && $item->vendorId) ? '' : 'display:none;'; ?>">
                                    <span class="eim-vendor-selected-name"><?= esc_html($vendorName); ?></span>
                                    <a href="#" class="eim-vendor-clear" aria-label="Remove vendor" style="margin-left:6px;">&times;</a>
                                </div>
                                <div class="eim-vendor-dropdown" id="eim-bi-vendor-dropdown"
                                     style="display:none;position:absolute;background:#fff;border:1px solid #dcdcde;border-radius:4px;z-index:9999;min-width:300px;max-height:220px;overflow-y:auto;box-shadow:0 2px 8px rgba(0,0,0,.12);"></div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bi_unit_cost">Unit Cost</label></th>
                        <td>
                            <input type="text" id="eim_bi_unit_cost" name="unit_cost" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : ($item->unitCostCents > 0 ? number_format($item->unitCostCents / 100, 2) : '')); ?>"
                                   placeholder="0.00">
                            <p class="description">Base unit cost (e.g. price per item or per head).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bi_website_url">Website URL</label></th>
                        <td><input type="url" id="eim_bi_website_url" name="website_url" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $item->websiteUrl); ?>"
                                   placeholder="https://"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_bi_notes">Notes</label></th>
                        <td><textarea id="eim_bi_notes" name="notes" class="large-text" rows="3"
                                      placeholder="Optional notes about this item"><?= esc_textarea($isNew ? '' : $item->notes); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php
                            $selCats  = [];
                            $catNonce = wp_create_nonce('eim_suggest_categories_nonce');
                            if (!$isNew) {
                                foreach (Category::forEntity('budget_item', $item->id) as $cat) {
                                    $selCats[] = [
                                        'id'          => $cat->id,
                                        'name'        => $cat->name,
                                        'parent_name' => $cat->parentName,
                                        'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                    ];
                                }
                            }
                            $this->renderCategoryPicker('eim-bi-cat-picker', $selCats, $catNonce);
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Image</th>
                        <td>
                            <?php
                            $thumbUrl = (!$isNew && $item->imageAttachmentId > 0)
                                ? (wp_get_attachment_image_url($item->imageAttachmentId, 'thumbnail') ?: '')
                                : '';
                            $fullUrl  = (!$isNew && $item->imageAttachmentId > 0)
                                ? (wp_get_attachment_image_url($item->imageAttachmentId, 'full') ?: '')
                                : '';
                            ?>
                            <input type="hidden" id="eim_bi_image_attachment_id" name="image_attachment_id"
                                   value="<?= esc_attr((string) ($isNew ? 0 : $item->imageAttachmentId)); ?>">
                            <div class="eim-li-image-picker" id="eim-bi-image-picker">
                                <div id="eim_bi_image_preview" class="eim-li-image-preview">
                                    <?php if ($thumbUrl): ?>
                                        <button type="button" class="button-link eim-li-image-thumb"
                                                data-full-src="<?= esc_attr($fullUrl); ?>"
                                                data-caption="<?= esc_attr($isNew ? '' : $item->label); ?>"
                                                aria-label="View full-size image">
                                            <img src="<?= esc_attr($thumbUrl); ?>" alt="" loading="lazy">
                                        </button>
                                    <?php else: ?>
                                        <span class="description">No image selected.</span>
                                    <?php endif; ?>
                                </div>
                                <p class="eim-li-image-actions">
                                    <button type="button" id="eim_bi_image_select" class="button"
                                            data-select-label="Select Image"
                                            data-change-label="Change Image">
                                        <?= $thumbUrl ? 'Change Image' : 'Select Image'; ?>
                                    </button>
                                    <button type="button" id="eim_bi_image_remove" class="button"
                                            <?= $thumbUrl ? '' : 'hidden'; ?>>Remove Image</button>
                                </p>
                            </div>
                            <p class="description" style="margin-top:6px;">Optional image from the WordPress Media Library.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Create Line Item' : 'Update Line Item'); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function sanitizeSortKey(string $key): string
    {
        return in_array($key, ['label', 'unit_cost', 'budget_plans'], true) ? $key : 'label';
    }

    private function sanitizeImageAttachmentId(int $attachmentId): int
    {
        if ($attachmentId <= 0) return 0;
        $post = get_post($attachmentId);
        return ($post && $post->post_type === 'attachment') ? $attachmentId : 0;
    }
}
