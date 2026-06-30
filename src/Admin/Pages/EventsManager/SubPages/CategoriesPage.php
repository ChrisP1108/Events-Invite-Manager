<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Category;

/**
 * Admin page for managing the unified category taxonomy.
 *
 * Categories support one level of parent/child hierarchy and can be assigned
 * to any entity type in the plugin.
 */
final class CategoriesPage extends AbstractAdminPage
{
    public function __construct() {}

    public function handleAction(string $action): void
    {
        match ($action) {
            'save_category'   => $this->handleSaveCategory(),
            'delete_category' => $this->handleDeleteCategory(),
            'bulk_delete_categories' => $this->handleBulkDeleteCategories(),
            default           => null,
        };
    }

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderCategoryForm(null),
            'edit'  => $this->renderCategoryForm(Category::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderCategoryList(),
        };
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * AJAX: searches the categories list table.
     *
     * Expected GET params: nonce, query, sort, order, field.
     * Returns JSON: { success: true, data: { html, count } }
     */
    public function handleAjaxSearchCategories(): void
    {
        check_ajax_referer('eim_search_categories_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = sanitize_key($_GET['sort']  ?? 'name');
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeCategoryFieldKey(sanitize_key($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;

        $all   = Category::listForAdmin($query, $sort, $order, $field);
        $total = count($all);
        $paged = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderCategoryRows($paged, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * AJAX: typeahead suggest for the CategoryPicker widget.
     *
     * Expected GET params: nonce, query.
     * Returns JSON: { success: true, data: [ { id, name, parent_name, label }, ... ] }
     */
    public function handleAjaxSuggestCategories(): void
    {
        check_ajax_referer('eim_suggest_categories_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));

        if (mb_strlen($query) < 1) {
            wp_send_json_success([]);
            return;
        }

        wp_send_json_success(Category::search($query, 15));
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    private function handleSaveCategory(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_category')) {
            wp_die('Security check failed.');
        }

        $id       = (int) ($_POST['category_id'] ?? 0);
        $name     = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;

        if (empty($name)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'category_name_required',
            ]));
            exit;
        }

        if ($id > 0) {
            global $wpdb;
            $t = DatabaseManager::categoriesTable();
            // Prevent circular self-parenting.
            if ($parentId === $id) {
                $parentId = null;
            }
            $slug = sanitize_title($name);
            $base = $slug;
            $n    = 1;
            while ($wpdb->get_var($wpdb->prepare( // phpcs:ignore
                "SELECT id FROM {$t} WHERE slug = %s AND id != %d LIMIT 1",
                $slug,
                $id
            ))) {
                $slug = $base . '-' . $n++;
            }
            $wpdb->update($t, ['name' => $name, 'slug' => $slug, 'parent_id' => $parentId], ['id' => $id]);
            $message = 'category_updated';
        } else {
            Category::create($name, $parentId);
            $message = 'category_created';
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['eim_message' => $message]));
        exit;
    }

    private function handleDeleteCategory(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_category_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['eim_message' => 'category_deleted']));
        exit;
    }

    private function handleBulkDeleteCategories(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_categories')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    private function renderCategoryList(): void
    {
        $message  = (string) ($_GET['eim_message'] ?? '');
        $error    = (string) ($_GET['eim_error']   ?? '');
        $search   = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort     = sanitize_key($_GET['sort']  ?? 'name');
        $order    = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field    = $this->sanitizeCategoryFieldKey(sanitize_key($_GET['field'] ?? ''));
        $all      = Category::listForAdmin($search, $sort, $order, $field);
        $total    = count($all);
        $paged    = array_slice($all, 0, 10);
        $addUrl   = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'add']);
        $sortArgs = ['tab' => AdminMenu::TAB_CATEGORIES];
        if ($field !== '') {
            $sortArgs['field'] = $field;
        }
        $filterOptions = Category::count() >= 2 ? [
            ['value' => 'name',     'label' => 'Name'],
            ['value' => 'parent',   'label' => 'Parent'],
            ['value' => 'children', 'label' => 'Children'],
        ] : [];
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Categories</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Category</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Manage shared categories here. Categories can be assigned to events, invitees, connection groups,
                locations, food &amp; beverage items, budgets, vendors, and newsletters.
                Categories support one level of parent &rarr; child hierarchy.
            </p>

            <?php $this->renderSearchBar(
                'eim-category-search',
                'eim-category-count',
                'eim-category-loading',
                'Search categories…',
                $total,
                $search,
                $filterOptions,
                $field
            ); ?>

            <?php $this->renderBulkActions(
                'eim-categories-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES),
                'bulk_delete_categories',
                'eim_bulk_delete_categories'
            ); ?>

            <table id="eim-categories-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('categories'); ?></th>
                        <th style="width:40%;"><?= $this->sortLink('Name', 'name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, $sortArgs); ?></th>
                        <th style="width:30%;">Parent</th>
                        <th style="width:15%;">Children</th>
                        <th style="width:15%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-categories-table-body">
                    <?php $this->renderCategoryRows($paged, $search); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-category-search'); ?>

            <?php if (empty($paged) && $search === ''): ?>
                <p style="margin-top:12px;">No categories yet. <a href="<?= esc_url($addUrl); ?>">Add the first category.</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param Category[] $categories */
    private function renderCategoryRows(array $categories, string $search = '', int $offset = 0): void
    {
        if (empty($categories)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No categories found.';
            echo '<tr class="eim-no-results"><td colspan="6">' . esc_html($msg) . '</td></tr>';
            return;
        }

        // Build child link map for the visible parent categories.
        global $wpdb;
        $t         = DatabaseManager::categoriesTable();
        $allCatIds = array_map(static fn(Category $c): int => $c->id, $categories);
        $childrenByParent = [];
        if (!empty($allCatIds)) {
            $placeholders = implode(', ', array_fill(0, count($allCatIds), '%d'));
            $rows = $wpdb->get_results(
                $wpdb->prepare( // phpcs:ignore
                    "SELECT id, parent_id, name FROM {$t} WHERE parent_id IN ({$placeholders}) ORDER BY name ASC",
                    ...$allCatIds
                )
            );
            foreach ($rows ?? [] as $row) {
                $childrenByParent[(int) $row->parent_id][] = [
                    'id'   => (int) $row->id,
                    'name' => (string) $row->name,
                ];
            }
        }

        foreach ($categories as $i => $cat) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'delete_category', 'id' => $cat->id]),
                'eim_delete_category_' . $cat->id
            );
            $children   = $childrenByParent[$cat->id] ?? [];
            $childCount = count($children);
            ?>
            <tr>
                <td class="eim-row-num"><?= $offset + $i + 1; ?></td>
                <?= $this->renderBulkSelectCell('eim-categories-bulk-form', 'categories', $cat->id, $cat->name); ?>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($cat->name); ?></a></strong>
                    <?php if ($cat->parentId !== null): ?>
                        <span style="color:#646970;font-size:12px;">(child)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($cat->parentName !== null): ?>
                        <?php
                        $parentEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->parentId]);
                        ?>
                        <a href="<?= esc_url($parentEditUrl); ?>"><?= esc_html($cat->parentName); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($children)): ?>
                        <span class="eim-tag-list">
                            <?php foreach ($children as $child): ?>
                                <?php $childEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $child['id']]); ?>
                                <a href="<?= esc_url($childEditUrl); ?>" class="eim-cat-chip"><?= esc_html($child['name']); ?></a>
                            <?php endforeach; ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete &ldquo;<?= esc_js($cat->name); ?>&rdquo;?<?= $childCount > 0 ? ' Its ' . $childCount . ' child categor' . ($childCount === 1 ? 'y' : 'ies') . ' will also be deleted.' : ''; ?>');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    private function renderCategoryForm(?Category $category): void
    {
        $isNew   = $category === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $backUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES);
        $title   = $isNew ? 'Add Category' : 'Edit Category: ' . $category->name;
        $parents = Category::roots();
        // Exclude the current category from the parent options to prevent circular refs.
        if (!$isNew) {
            $parents = array_filter($parents, static fn(Category $p): bool => $p->id !== $category->id);
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Categories</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES)); ?>" style="max-width:540px;">
                <?php wp_nonce_field('eim_save_category'); ?>
                <input type="hidden" name="eim_action"   value="save_category">
                <input type="hidden" name="category_id"  value="<?= esc_attr($isNew ? 0 : $category->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_cat_name">Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" id="eim_cat_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $category->name); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_cat_parent">Parent Category</label></th>
                        <td>
                            <select id="eim_cat_parent" name="parent_id">
                                <option value="0">— None (top-level) —</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?= esc_attr($parent->id); ?>"
                                            <?= selected(!$isNew && $category->parentId === $parent->id, true, false); ?>>
                                        <?= esc_html($parent->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Optionally nest this category under a parent. Only one level of hierarchy is supported.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Add Category' : 'Update Category'); ?>
            </form>
        </div>
        <?php
    }

    private function sanitizeCategoryFieldKey(string $field): string
    {
        return in_array($field, ['name', 'parent', 'children'], true) ? $field : '';
    }
}
