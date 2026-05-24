<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\Vendor;

/**
 * Admin page for the global food and beverage menu item library.
 *
 * Displays two independent scrollable tables — one for food, one for beverages —
 * each with its own search bar.  Items from this library are assigned to events
 * via the eim_event_menu_items pivot and presented to invitees during RSVP.
 */
final class MenuItemsPage extends AbstractAdminPage
{
    /**
     * Dispatches menu item form submissions and GET actions.
     *
     * @param string $action The action slug.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_menu_item'        => $this->handleSaveMenuItem(),
            'update_menu_item'      => $this->handleUpdateMenuItem(),
            'delete_menu_item'      => $this->handleDeleteMenuItem(),
            'bulk_delete_menu_items' => $this->handleBulkDeleteMenuItems(),
            default                 => null,
        };
    }

    /** Renders the Food &amp; Beverages admin page (list or single-item edit form). */
    public function renderPage(): void
    {
        $action  = $_GET['action'] ?? 'list';
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $hasVendors = Vendor::count() > 0;

        if ($action === 'edit') {
            $item = MenuItem::find((int) ($_GET['id'] ?? 0));
            if ($item === null) {
                $this->renderError('Menu item not found.', AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS));
                return;
            }
            $this->renderEditForm($item, $message, $error, $hasVendors);
            return;
        }
        ?>
        <div class="wrap">
            <h1>Food &amp; Beverages</h1>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:24px;">
                Manage your global food and beverage menu item library.
                Items from this library are assigned to individual events and presented to invitees during RSVP.
            </p>

            <div class="eim-menu-items-layout">
                <?php $this->renderTypeSection(MenuItem::TYPE_FOOD,     'Food Items', $hasVendors); ?>
                <?php $this->renderTypeSection(MenuItem::TYPE_BEVERAGE, 'Beverage Items', $hasVendors); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the standalone edit form for an existing menu item.
     *
     * @param MenuItem $item    The item being edited.
     * @param string   $message Success message key to display.
     * @param string   $error   Error key to display.
     * @param bool     $hasVendors Whether at least one vendor exists in the vendor library.
     */
    private function renderEditForm(MenuItem $item, string $message, string $error, bool $hasVendors): void
    {
        $backUrl       = AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS);
        $currentVendor = $item->vendorId ? Vendor::find($item->vendorId) : null;
        $vendorAddUrl  = AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['action' => 'add']);
        ?>
        <div class="wrap">
            <h1>Edit <?= esc_html(ucfirst($item->type)); ?> Item</h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Food &amp; Beverages</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <?php if (!$hasVendors): ?>
                <div class="notice notice-warning inline">
                    <p>
                        Create at least one vendor before updating food or beverage items.
                        <a href="<?= esc_url($vendorAddUrl); ?>" class="button button-primary" style="margin-left:8px;">Add Vendor</a>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS)); ?>" style="max-width:560px;">
                <?php wp_nonce_field('eim_update_menu_item'); ?>
                <input type="hidden" name="eim_action"    value="update_menu_item">
                <input type="hidden" name="menu_item_id"  value="<?= esc_attr($item->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_mi_label">Label <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_mi_label" name="label" class="regular-text"
                                   value="<?= esc_attr($item->label); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_mi_desc">Description</label></th>
                        <td><input type="text" id="eim_mi_desc" name="description" class="regular-text"
                                   value="<?= esc_attr($item->description); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_mi_edit_vendor_search">Vendor <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td>
                            <div class="eim-vendor-autocomplete" id="eim-mi-edit-vendor-picker"
                                 data-initial-id="<?= esc_attr((string) ($item->vendorId ?? 0)); ?>"
                                 data-initial-name="<?= esc_attr($currentVendor ? $currentVendor->companyName : ''); ?>">
                                <input type="text" id="eim_mi_edit_vendor_search"
                                       class="regular-text eim-vendor-search-input"
                                       placeholder="Vendor — type to search…" autocomplete="off"
                                       value="<?= esc_attr($currentVendor ? $currentVendor->companyName : ''); ?>">
                                <input type="hidden" name="vendor_id" id="eim_mi_edit_vendor_id"
                                       value="<?= esc_attr((string) ($item->vendorId ?? 0)); ?>">
                                <?php if ($currentVendor): ?>
                                    <div class="eim-vendor-selected" id="eim-mi-edit-vendor-selected">
                                        <span class="eim-vendor-selected-name"><?= esc_html($currentVendor->companyName); ?></span>
                                        <a href="#" class="eim-vendor-clear" aria-label="Remove vendor" style="margin-left:6px;">&times;</a>
                                    </div>
                                <?php else: ?>
                                    <div class="eim-vendor-selected" id="eim-mi-edit-vendor-selected" style="display:none;">
                                        <span class="eim-vendor-selected-name"></span>
                                        <a href="#" class="eim-vendor-clear" aria-label="Remove vendor" style="margin-left:6px;">&times;</a>
                                    </div>
                                <?php endif; ?>
                                <div class="eim-vendor-dropdown" id="eim-mi-edit-vendor-dropdown" style="display:none;position:absolute;background:#fff;border:1px solid #dcdcde;border-radius:4px;z-index:9999;min-width:300px;max-height:220px;overflow-y:auto;box-shadow:0 2px 8px rgba(0,0,0,.12);"></div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_mi_price">Price</label></th>
                        <td>
                            <input type="text" id="eim_mi_price" name="price" class="regular-text"
                                   value="<?= esc_attr($item->priceCents > 0 ? number_format($item->priceCents / 100, 2) : ''); ?>"
                                   placeholder="0.00">
                            <p class="description">Per-person price used in budget calculations.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php
                            $selCats = [];
                            foreach (Category::forEntity('menu_item', $item->id) as $cat) {
                                $selCats[] = [
                                    'id'          => $cat->id,
                                    'name'        => $cat->name,
                                    'parent_name' => $cat->parentName,
                                    'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                ];
                            }
                            $this->renderCategoryPicker('eim-menu-item-cat-picker', $selCats, wp_create_nonce('eim_suggest_categories_nonce'));
                            ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Update Item'); ?>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    /**
     * AJAX: searches the menu items list for a given type.
     *
     * Expected GET params: nonce, type, query, sort, order, field.
     */
    public function handleAjaxSearchMenuItems(): void
    {
        check_ajax_referer('eim_search_menu_items_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $type  = sanitize_key($_GET['type']  ?? 'food');
        $type  = $type === MenuItem::TYPE_BEVERAGE ? MenuItem::TYPE_BEVERAGE : MenuItem::TYPE_FOOD;
        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort  = $this->sanitizeMenuItemSortKey((string) ($_GET['sort']  ?? 'label'));
        $order = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field = $this->sanitizeMenuItemFieldKey((string) ($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;

        $all   = MenuItem::listByType($type, $query, $sort, $order, $field);
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderItemRows($items, $type, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * AJAX: autocomplete for the event-edit menu item picker.
     *
     * Expected GET params: nonce, type, query.
     */
    public function handleAjaxSuggestMenuItems(): void
    {
        check_ajax_referer('eim_suggest_menu_items_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $type  = sanitize_key($_GET['type']  ?? 'food');
        $type  = $type === MenuItem::TYPE_BEVERAGE ? MenuItem::TYPE_BEVERAGE : MenuItem::TYPE_FOOD;
        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $items = MenuItem::search($query, $type);

        wp_send_json_success(array_map(static fn(MenuItem $i): array => [
            'id'          => $i->id,
            'label'       => $i->label,
            'description' => $i->description,
        ], $items));
    }

    // -------------------------------------------------------------------------
    // Form handlers
    // -------------------------------------------------------------------------

    /** Handles updating an existing menu item from the edit form. */
    private function handleUpdateMenuItem(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_update_menu_item')) {
            wp_die('Security check failed.');
        }

        $id    = (int) ($_POST['menu_item_id'] ?? 0);
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));

        if ($label === '' || $id <= 0) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, [
                'action'    => 'edit',
                'id'        => $id ?: null,
                'eim_error' => 'menu_item_label_required',
            ]));
            exit;
        }

        $priceRaw   = str_replace(['$', ',', ' '], '', wp_unslash($_POST['price'] ?? '0'));
        $priceCents = max(0, (int) round((float) $priceRaw * 100));
        $vendorId   = (int) ($_POST['vendor_id'] ?? 0);

        if ($vendorId <= 0 || Vendor::find($vendorId) === null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, [
                'action'    => 'edit',
                'id'        => $id,
                'eim_error' => 'menu_item_vendor_required',
            ]));
            exit;
        }

        MenuItem::update($id, [
            'label'       => $label,
            'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
            'price_cents' => $priceCents,
            'vendor_id'   => $vendorId,
        ]);

        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));
        Category::syncToEntity('menu_item', $id, $categoryIds);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, ['eim_message' => 'menu_item_updated']));
        exit;
    }

    /** Handles creating a new menu item from the inline add form. */
    private function handleSaveMenuItem(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_menu_item')) {
            wp_die('Security check failed.');
        }

        $type     = sanitize_key($_POST['type'] ?? 'food');
        $type     = $type === MenuItem::TYPE_BEVERAGE ? MenuItem::TYPE_BEVERAGE : MenuItem::TYPE_FOOD;
        $label    = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $desc     = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $priceRaw   = str_replace(['$', ',', ' '], '', wp_unslash($_POST['price'] ?? '0'));
        $priceCents = max(0, (int) round((float) $priceRaw * 100));
        $vendorId   = (int) ($_POST['vendor_id'] ?? 0);

        if ($label === '') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, [
                'eim_error' => 'menu_item_label_required',
            ]));
            exit;
        }

        if ($vendorId <= 0 || Vendor::find($vendorId) === null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, [
                'eim_error' => 'menu_item_vendor_required',
            ]));
            exit;
        }

        $item = MenuItem::create([
            'type'        => $type,
            'label'       => $label,
            'description' => $desc,
            'price_cents' => $priceCents,
            'vendor_id'   => $vendorId,
        ]);

        if ($item !== null) {
            $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));
            Category::syncToEntity('menu_item', $item->id, $categoryIds);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, [
            'eim_message' => 'menu_item_created',
        ]));
        exit;
    }

    /** Handles deleting a menu item via a GET nonce link. */
    private function handleDeleteMenuItem(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_menu_item_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::syncToEntity('menu_item', $id, []);
        MenuItem::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, [
            'eim_message' => 'menu_item_deleted',
        ]));
        exit;
    }

    private function handleBulkDeleteMenuItems(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_menu_items')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('menu_item', $id, []);
            MenuItem::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Renders one of the two side-by-side item type sections (food or beverage).
     *
     * @param string $type       MenuItem::TYPE_FOOD or MenuItem::TYPE_BEVERAGE.
     * @param string $heading    Section heading text.
     * @param bool   $hasVendors Whether at least one vendor exists in the vendor library.
     */
    private function renderTypeSection(string $type, string $heading, bool $hasVendors): void
    {
        $inputId  = 'eim-menu-' . $type . '-search';
        $countId  = 'eim-menu-' . $type . '-count';
        $spinnerId = 'eim-menu-' . $type . '-loading';
        $tableId  = 'eim-menu-' . $type . '-table';
        $tbodyId  = 'eim-menu-' . $type . '-table-body';
        $sort     = 'label';
        $order    = 'asc';
        $all      = MenuItem::listByType($type);
        $total    = count($all);
        $items    = array_slice($all, 0, 10);
        ?>
        <div class="eim-menu-section">
            <h2><?= esc_html($heading); ?></h2>

            <?php $this->renderAddItemForm($type, $hasVendors); ?>

            <?php $this->renderSearchBar(
                $inputId,
                $countId,
                $spinnerId,
                'Search ' . strtolower($heading) . '...',
                $total,
                '',
                [
                    ['value' => 'label',       'label' => 'Label'],
                    ['value' => 'description', 'label' => 'Description'],
                ]
            ); ?>

            <?php $this->renderBulkActions(
                'eim-menu-' . $type . '-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS),
                'bulk_delete_menu_items',
                'eim_bulk_delete_menu_items',
                ['type' => $type]
            ); ?>

            <div class="eim-menu-table-wrapper">
                <table id="<?= esc_attr($tableId); ?>"
                       class="wp-list-table widefat fixed striped"
                       data-type="<?= esc_attr($type); ?>"
                       data-sort="<?= esc_attr($sort); ?>"
                       data-order="<?= esc_attr($order); ?>"
                       data-total="<?= esc_attr($total); ?>">
                    <thead>
                        <tr>
                            <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('menu-' . $type); ?></th>
                            <th style="width:22%;"><?= $this->clientSortLink('Label', 'label', $sort, $order); ?></th>
                            <th><?= $this->clientSortLink('Description', 'description', $sort, $order); ?></th>
                            <th style="width:16%;">Vendor</th>
                            <th style="width:13%;">Categories</th>
                            <th style="width:8%;">Price</th>
                            <th style="width:10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="<?= esc_attr($tbodyId); ?>">
                        <?php $this->renderItemRows($items, $type); ?>
                    </tbody>
                </table>
            </div>

            <?php $this->renderPaginationBar($inputId); ?>
        </div>
        <?php
    }

    /**
     * Renders menu item table rows for the initial page and AJAX responses.
     *
     * @param MenuItem[] $items  Items to render.
     * @param string     $type   MenuItem::TYPE_FOOD or TYPE_BEVERAGE (for empty-state message).
     * @param string     $search Active search query (used to distinguish no-match from empty library).
     */
    private function renderItemRows(array $items, string $type, string $search = ''): void
    {
        if (empty($items)) {
            $msg = $search !== ''
                ? 'No results found based upon search criteria.'
                : 'No ' . ($type === MenuItem::TYPE_BEVERAGE ? 'beverage' : 'food') . ' items yet.';
            echo '<tr class="eim-no-results"><td colspan="7">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $itemIds    = array_map(static fn(MenuItem $i): int => $i->id, $items);
        $vendorIds  = array_values(array_filter(array_map(static fn(MenuItem $i) => $i->vendorId, $items)));
        $vendorsMap = Vendor::findMany($vendorIds);
        $catsByItem = Category::forEntities('menu_item', $itemIds);
        $catEditBase = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES);

        foreach ($items as $item) {
            $vendor    = $item->vendorId ? ($vendorsMap[$item->vendorId] ?? null) : null;
            $cats      = $catsByItem[$item->id] ?? [];
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, ['action' => 'edit', 'id' => $item->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS, ['action' => 'delete_menu_item', 'id' => $item->id]),
                'eim_delete_menu_item_' . $item->id
            );
            ?>
            <tr>
                <?= $this->renderBulkSelectCell('eim-menu-' . $type . '-bulk-form', 'menu-' . $type, $item->id, $item->label); ?>
                <td><strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($item->label); ?></a></strong></td>
                <td><?= esc_html($item->description ?: '—'); ?></td>
                <td>
                    <?php if ($vendor): ?>
                        <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['action' => 'edit', 'id' => $vendor->id])); ?>"><?= esc_html($vendor->companyName); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($cats): ?>
                        <div class="eim-tag-list">
                            <?php foreach ($cats as $cat):
                                $chipLabel = $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name;
                                $chipUrl   = $catEditBase . '&action=edit&id=' . $cat->id;
                            ?>
                            <a href="<?= esc_url($chipUrl); ?>" class="eim-cat-chip"><?= esc_html($chipLabel); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($item->priceCents > 0): ?>
                        <span style="font-variant-numeric:tabular-nums;"><?= esc_html($item->formattedPrice()); ?></span>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete &ldquo;<?= esc_js($item->label); ?>&rdquo;?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Renders the inline add-item form below the menu item table.
     *
     * @param string $type       MenuItem::TYPE_FOOD or MenuItem::TYPE_BEVERAGE.
     * @param bool   $hasVendors Whether at least one vendor exists in the vendor library.
     */
    private function renderAddItemForm(string $type, bool $hasVendors): void
    {
        $typeLabel = $type === MenuItem::TYPE_BEVERAGE ? 'Beverage' : 'Food';
        $pickerId  = 'eim-mi-add-vendor-picker-' . $type;
        $vendorAddUrl = AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['action' => 'add']);
        ?>
        <div class="eim-menu-add-form">
            <h3 style="margin:0 0 10px;">Add <?= esc_html($typeLabel); ?> Item</h3>
            <?php if (!$hasVendors): ?>
                <p class="description" style="margin-top:0;">
                    Create at least one vendor before adding <?= esc_html(strtolower($typeLabel)); ?> items.
                </p>
                <p><a href="<?= esc_url($vendorAddUrl); ?>" class="button button-primary">Add Vendor</a></p>
            <?php else: ?>
            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS)); ?>">
                <?php wp_nonce_field('eim_save_menu_item'); ?>
                <input type="hidden" name="eim_action" value="save_menu_item">
                <input type="hidden" name="type"       value="<?= esc_attr($type); ?>">
                <div class="eim-menu-add-fields">
                    <div class="eim-menu-add-row">
                        <input type="text" name="label" class="regular-text eim-menu-add-input"
                               placeholder="Label (e.g. <?= $type === MenuItem::TYPE_BEVERAGE ? 'Red Wine' : 'Chicken'; ?>) *"
                               required>
                    </div>
                    <div class="eim-menu-add-row">
                        <input type="text" name="description" class="regular-text eim-menu-add-input"
                               placeholder="Description (optional)">
                    </div>
                    <div class="eim-menu-add-row">
                        <input type="text" name="price" class="regular-text eim-menu-add-input"
                               placeholder="Price e.g. 12.50 (optional)"
                               title="Per-person price used in budget calculations">
                    </div>
                    <div class="eim-menu-add-row">
                        <div class="eim-vendor-autocomplete eim-menu-add-vendor" id="<?= esc_attr($pickerId); ?>">
                            <input type="text" class="regular-text eim-vendor-search-input eim-menu-add-input"
                                   placeholder="Vendor — type to search… *" autocomplete="off">
                            <input type="hidden" name="vendor_id" value="0">
                            <div class="eim-vendor-selected" style="display:none;">
                                <span class="eim-vendor-selected-name"></span>
                                <a href="#" class="eim-vendor-clear" aria-label="Remove vendor" style="margin-left:6px;">&times;</a>
                            </div>
                            <div class="eim-vendor-dropdown" style="display:none;position:absolute;background:#fff;border:1px solid #dcdcde;border-radius:4px;z-index:9999;min-width:300px;max-height:220px;overflow-y:auto;box-shadow:0 2px 8px rgba(0,0,0,.12);"></div>
                        </div>
                    </div>
                    <div class="eim-menu-add-row">
                        <?php $this->renderCategoryPicker(
                            'eim-mi-add-cat-picker-' . $type,
                            [],
                            wp_create_nonce('eim_suggest_categories_nonce')
                        ); ?>
                    </div>
                    <div class="eim-menu-add-row">
                        <button type="submit" class="button button-primary">Add <?= esc_html($typeLabel); ?> Item</button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    /**
     * Sanitizes a menu item sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'label'.
     */
    private function sanitizeMenuItemSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['label', 'description'], true) ? $key : 'label';
    }

    /**
     * Sanitizes a menu item search field key against the allowed column list.
     *
     * @param string $field Raw field key.
     * @return string Validated key, or '' for any-column search.
     */
    private function sanitizeMenuItemFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['label', 'description'], true) ? $field : '';
    }

    /**
     * Generates a client-side sort link for menu item columns.
     *
     * The link carries only data-sort / data-order attributes; JS reads them and re-sorts the DOM.
     *
     * @param string $label        Visible column header text.
     * @param string $key          Column sort key.
     * @param string $currentSort  Currently active sort column.
     * @param string $currentOrder Currently active sort direction ('asc' or 'desc').
     * @return string HTML anchor element.
     */
    private function clientSortLink(string $label, string $key, string $currentSort, string $currentOrder): string
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
}
