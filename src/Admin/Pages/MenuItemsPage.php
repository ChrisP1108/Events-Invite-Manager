<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\MenuItem;

/**
 * Admin page for the global food and beverage menu item library.
 *
 * Displays two independent scrollable tables — one for food, one for beverages —
 * each with its own search bar.  Items from this library are assigned to events
 * via the eim_event_menu_items pivot and presented to invitees during RSVP.
 */
final class MenuItemsPage extends AbstractAdminPage
{
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_menu_item'   => $this->handleSaveMenuItem(),
            'delete_menu_item' => $this->handleDeleteMenuItem(),
            default            => null,
        };
    }

    public function renderPage(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
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
                <?php $this->renderTypeSection(MenuItem::TYPE_FOOD,     'Food Items'); ?>
                <?php $this->renderTypeSection(MenuItem::TYPE_BEVERAGE, 'Beverage Items'); ?>
            </div>
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
        $items = MenuItem::listByType($type, $query, $sort, $order, $field);

        ob_start();
        $this->renderItemRows($items, $type, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => count($items)]);
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

    private function handleSaveMenuItem(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_menu_item')) {
            wp_die('Security check failed.');
        }

        $type  = sanitize_key($_POST['type'] ?? 'food');
        $type  = $type === MenuItem::TYPE_BEVERAGE ? MenuItem::TYPE_BEVERAGE : MenuItem::TYPE_FOOD;
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $desc  = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $order = (int) ($_POST['sort_order'] ?? 0);

        if ($label === '') {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_MENU_ITEMS,
                'eim_error' => 'menu_item_label_required',
            ], admin_url('admin.php')));
            exit;
        }

        MenuItem::create([
            'type'        => $type,
            'label'       => $label,
            'description' => $desc,
            'sort_order'  => $order,
        ]);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_MENU_ITEMS,
            'eim_message' => 'menu_item_created',
        ], admin_url('admin.php')));
        exit;
    }

    private function handleDeleteMenuItem(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_menu_item_' . $id)) {
            wp_die('Security check failed.');
        }

        MenuItem::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_MENU_ITEMS,
            'eim_message' => 'menu_item_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private function renderTypeSection(string $type, string $heading): void
    {
        $inputId  = 'eim-menu-' . $type . '-search';
        $countId  = 'eim-menu-' . $type . '-count';
        $spinnerId = 'eim-menu-' . $type . '-loading';
        $tableId  = 'eim-menu-' . $type . '-table';
        $tbodyId  = 'eim-menu-' . $type . '-table-body';
        $items    = MenuItem::listByType($type);
        ?>
        <div class="eim-menu-section">
            <h2><?= esc_html($heading); ?></h2>

            <?php $this->renderSearchBar(
                $inputId,
                $countId,
                $spinnerId,
                'Search ' . strtolower($heading) . '...',
                count($items),
                '',
                [
                    ['value' => 'label',       'label' => 'Label'],
                    ['value' => 'description', 'label' => 'Description'],
                ]
            ); ?>

            <div class="eim-menu-table-wrapper">
                <table id="<?= esc_attr($tableId); ?>"
                       class="wp-list-table widefat fixed striped"
                       data-type="<?= esc_attr($type); ?>">
                    <thead>
                        <tr>
                            <th style="width:35%;">Label</th>
                            <th>Description</th>
                            <th style="width:10%;">Order</th>
                            <th style="width:10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="<?= esc_attr($tbodyId); ?>">
                        <?php $this->renderItemRows($items, $type); ?>
                    </tbody>
                </table>
            </div>

            <?php $this->renderAddItemForm($type); ?>
        </div>
        <?php
    }

    private function renderItemRows(array $items, string $type, string $search = ''): void
    {
        if (empty($items)) {
            $msg = $search !== ''
                ? 'No results found based upon search criteria.'
                : 'No ' . ($type === MenuItem::TYPE_BEVERAGE ? 'beverage' : 'food') . ' items yet.';
            echo '<tr class="eim-no-results"><td colspan="4">' . esc_html($msg) . '</td></tr>';
            return;
        }

        foreach ($items as $item) {
            $deleteUrl = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_MENU_ITEMS . '&action=delete_menu_item&id=' . $item->id),
                'eim_delete_menu_item_' . $item->id
            );
            ?>
            <tr>
                <td><strong><?= esc_html($item->label); ?></strong></td>
                <td><?= esc_html($item->description ?: '—'); ?></td>
                <td><?= esc_html($item->sortOrder); ?></td>
                <td>
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete &ldquo;<?= esc_js($item->label); ?>&rdquo;?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    private function renderAddItemForm(string $type): void
    {
        $label = $type === MenuItem::TYPE_BEVERAGE ? 'Beverage' : 'Food';
        ?>
        <div class="eim-menu-add-form" style="margin-top:14px;padding:14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
            <h3 style="margin:0 0 10px;">Add <?= esc_html($label); ?> Item</h3>
            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_MENU_ITEMS)); ?>">
                <?php wp_nonce_field('eim_save_menu_item'); ?>
                <input type="hidden" name="eim_action" value="save_menu_item">
                <input type="hidden" name="type"       value="<?= esc_attr($type); ?>">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <input type="text" name="label" class="regular-text"
                           placeholder="Label (e.g. <?= $type === MenuItem::TYPE_BEVERAGE ? 'Red Wine' : 'Chicken'; ?>)"
                           required>
                    <input type="text" name="description" class="regular-text"
                           placeholder="Description (optional)">
                    <label style="white-space:nowrap;">
                        Order: <input type="number" name="sort_order" value="0" min="0" style="width:58px;">
                    </label>
                    <button type="submit" class="button button-primary">Add <?= esc_html($label); ?> Item</button>
                </div>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Sanitizers
    // -------------------------------------------------------------------------

    private function sanitizeMenuItemSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['label', 'sort_order'], true) ? $key : 'label';
    }

    private function sanitizeMenuItemFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['label', 'description'], true) ? $field : '';
    }
}
