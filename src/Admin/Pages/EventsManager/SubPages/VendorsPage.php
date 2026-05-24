<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Vendor;

/**
 * Admin page for the global vendor library.
 *
 * Vendors are linked to budget line items and menu items. The vendor's category
 * drives the category shown on those records.
 */
final class VendorsPage extends AbstractAdminPage
{
    public function handleAction(string $action): void
    {
        DatabaseManager::maybeCreateVendorTable();

        match ($action) {
            'save_vendor'   => $this->handleSaveVendor(),
            'delete_vendor' => $this->handleDeleteVendor(),
            default         => null,
        };
    }

    public function renderPage(): void
    {
        DatabaseManager::maybeCreateVendorTable();

        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderVendorForm(null),
            'edit'  => $this->renderVendorForm(Vendor::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderVendorList(),
        };
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * AJAX: searches the vendor list table.
     *
     * Expected GET params: nonce, query, sort, order, field.
     */
    public function handleAjaxSearchVendors(): void
    {
        check_ajax_referer('eim_search_vendors_list_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeVendorSortKey(sanitize_key($_GET['sort']  ?? 'company_name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeVendorFieldKey(sanitize_key($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;
        $all     = Vendor::listForAdmin($query, $sort, $order, $field);
        $total   = count($all);
        $vendors = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderVendorRows($vendors, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * AJAX: autocomplete search for the vendor typeahead picker.
     *
     * Expected GET params: nonce, query.
     * Returns JSON: { success: true, data: [ { id, company_name, category, category_label, label }, ... ] }
     */
    public function handleAjaxSuggestVendors(): void
    {
        check_ajax_referer('eim_suggest_vendors_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));

        if (mb_strlen($query) < 1) {
            wp_send_json_success([]);
            return;
        }

        wp_send_json_success(Vendor::search($query, 10));
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    private function handleSaveVendor(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_vendor')) {
            wp_die('Security check failed.');
        }

        $id          = (int) ($_POST['vendor_id'] ?? 0);
        $companyName = sanitize_text_field(wp_unslash($_POST['company_name'] ?? ''));

        if (empty($companyName)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'vendor_name_required',
            ]));
            exit;
        }

        $data = [
            'company_name'   => $companyName,
            'category'       => sanitize_key($_POST['category']       ?? 'other'),
            'street_address' => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
            'city'           => sanitize_text_field(wp_unslash($_POST['city']           ?? '')),
            'state'          => sanitize_text_field(wp_unslash($_POST['state']          ?? '')),
            'zip_code'       => sanitize_text_field(wp_unslash($_POST['zip_code']       ?? '')),
            'email'          => sanitize_email(wp_unslash($_POST['email']               ?? '')),
            'phone'          => sanitize_text_field(wp_unslash($_POST['phone']          ?? '')),
            'notes'          => sanitize_textarea_field(wp_unslash($_POST['notes']      ?? '')),
        ];

        if ($id > 0) {
            Vendor::update($id, $data);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['eim_message' => 'vendor_updated']));
        } else {
            Vendor::create($data);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['eim_message' => 'vendor_created']));
        }
        exit;
    }

    private function handleDeleteVendor(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_vendor_' . $id)) {
            wp_die('Security check failed.');
        }

        Vendor::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['eim_message' => 'vendor_deleted']));
        exit;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    private function renderVendorList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s']     ?? ''));
        $sort    = $this->sanitizeVendorSortKey(sanitize_key($_GET['sort']  ?? 'company_name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeVendorFieldKey(sanitize_key($_GET['field'] ?? ''));
        $all     = Vendor::listForAdmin($search, $sort, $order, $field);
        $total   = count($all);
        $vendors = array_slice($all, 0, 10);
        $addUrl  = AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['action' => 'add']);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Vendors</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Vendor</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Manage your vendor library. Vendors are linked to budget line items and food &amp; beverage items.
                Each vendor's category drives the category shown on those records.
            </p>

            <?php $this->renderSearchBar(
                'eim-vendor-search',
                'eim-vendor-count',
                'eim-vendor-loading',
                'Search vendors…',
                $total,
                $search,
                [
                    ['value' => 'company_name', 'label' => 'Company Name'],
                    ['value' => 'category',     'label' => 'Category'],
                    ['value' => 'email',        'label' => 'Email'],
                    ['value' => 'phone',        'label' => 'Phone'],
                    ['value' => 'address',      'label' => 'Address'],
                ],
                $field
            ); ?>

            <table id="eim-vendors-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:8px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <th style="width:22%;"><?= $this->sortLink('Company Name', 'company_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_VENDORS]); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Category',     'category',     AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_VENDORS]); ?></th>
                        <th style="width:18%;">Contact</th>
                        <th style="width:16%;">Budget Plans</th>
                        <th style="width:16%;">Food &amp; Bev Items</th>
                        <th style="width:14%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-vendors-table-body">
                    <?php $this->renderVendorRows($vendors, $search); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-vendor-search'); ?>

            <?php if (empty($vendors) && $search === ''): ?>
                <p style="margin-top:12px;">No vendors yet. <a href="<?= esc_url($addUrl); ?>">Add the first vendor.</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders vendor table rows for the initial page and AJAX responses.
     *
     * @param Vendor[] $vendors
     */
    private function renderVendorRows(array $vendors, string $search = ''): void
    {
        if (empty($vendors)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No vendors found.';
            echo '<tr class="eim-no-results"><td colspan="6">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $vendorIds    = array_map(static fn(Vendor $v): int => $v->id, $vendors);
        $budgetUsage  = Vendor::budgetUsageForVendors($vendorIds);
        $menuUsage    = Vendor::menuItemUsageForVendors($vendorIds);

        foreach ($vendors as $vendor) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['action' => 'edit', 'id' => $vendor->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_VENDORS, ['action' => 'delete_vendor', 'id' => $vendor->id]),
                'eim_delete_vendor_' . $vendor->id
            );
            $plans    = $budgetUsage[$vendor->id]  ?? [];
            $menuItems = $menuUsage[$vendor->id]   ?? [];
            ?>
            <tr>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($vendor->companyName); ?></a></strong>
                    <?php if ($vendor->formattedAddress()): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html($vendor->formattedAddress()); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="background:#f0f0f1;padding:2px 8px;border-radius:3px;font-size:12px;">
                        <?= esc_html($vendor->categoryLabel()); ?>
                    </span>
                </td>
                <td>
                    <?php if ($vendor->email): ?>
                        <a href="mailto:<?= esc_attr($vendor->email); ?>" style="font-size:12px;"><?= esc_html($vendor->email); ?></a><br>
                    <?php endif; ?>
                    <?php if ($vendor->phone): ?>
                        <span style="font-size:12px;"><?= esc_html($vendor->phone); ?></span>
                    <?php endif; ?>
                    <?php if (!$vendor->email && !$vendor->phone): ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($plans)): ?>
                        <span style="color:#999;font-size:12px;">None</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($plans as $plan): ?>
                                <a class="eim-event-tag"
                                   href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_BUDGET, ['action' => 'edit', 'id' => $plan['id']])); ?>"
                                   style="font-size:11px;margin-right:3px;">
                                    <?= esc_html($plan['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($menuItems)): ?>
                        <span style="color:#999;font-size:12px;">None</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($menuItems as $mi): ?>
                                <span class="eim-event-tag" style="font-size:11px;margin-right:3px;">
                                    <?= esc_html($mi['label']); ?>
                                    <span class="eim-event-tag-role"><?= esc_html(ucfirst($mi['type'])); ?></span>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete vendor &ldquo;<?= esc_js($vendor->companyName); ?>&rdquo;? Any budget line items and menu items using this vendor will be unlinked.');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    private function renderVendorForm(?Vendor $vendor): void
    {
        $isNew   = $vendor === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $backUrl = AdminMenu::tabUrl(AdminMenu::TAB_VENDORS);
        $title   = $isNew ? 'Add Vendor' : 'Edit Vendor: ' . $vendor->companyName;
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Vendors</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_VENDORS)); ?>" style="max-width:680px;">
                <?php wp_nonce_field('eim_save_vendor'); ?>
                <input type="hidden" name="eim_action" value="save_vendor">
                <input type="hidden" name="vendor_id"  value="<?= esc_attr($isNew ? 0 : $vendor->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_v_name">Company Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_v_name" name="company_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->companyName); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_category">Category <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td>
                            <select id="eim_v_category" name="category" required>
                                <?php foreach (Vendor::CATEGORIES as $key => $label): ?>
                                    <option value="<?= esc_attr($key); ?>"
                                            <?= selected($isNew ? 'other' : $vendor->category, $key, false); ?>>
                                        <?= esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_email">Email</label></th>
                        <td><input type="email" id="eim_v_email" name="email" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->email); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_phone">Phone</label></th>
                        <td><input type="text" id="eim_v_phone" name="phone" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->phone); ?>"></td>
                    </tr>
                </table>

                <h2 class="title">Address</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_v_street">Street Address</label></th>
                        <td><input type="text" id="eim_v_street" name="street_address" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->streetAddress); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_city">City</label></th>
                        <td><input type="text" id="eim_v_city" name="city" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->city); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_state">State</label></th>
                        <td><input type="text" id="eim_v_state" name="state" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->state); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_zip">ZIP Code</label></th>
                        <td><input type="text" id="eim_v_zip" name="zip_code" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $vendor->zipCode); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_v_notes">Notes</label></th>
                        <td><textarea id="eim_v_notes" name="notes" class="large-text" rows="3"><?= esc_textarea($isNew ? '' : $vendor->notes); ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Add Vendor' : 'Update Vendor'); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // Sanitizers
    // =========================================================================

    private function sanitizeVendorSortKey(string $key): string
    {
        return in_array($key, ['company_name', 'category', 'email'], true) ? $key : 'company_name';
    }

    private function sanitizeVendorFieldKey(string $field): string
    {
        return in_array($field, ['company_name', 'category', 'email', 'phone', 'address'], true) ? $field : '';
    }
}
