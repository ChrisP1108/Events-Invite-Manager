<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\Location;

/**
 * Manages the global location library and provides the AJAX search endpoint
 * used by the venue and lodging autocomplete fields throughout the plugin.
 */
final class LocationsPage extends AbstractAdminPage
{
    /**
     * Dispatches locations-page form submissions and GET actions.
     *
     * @param string $action
     * @return void
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_location'   => $this->handleSaveLocation(),
            'delete_location' => $this->handleDeleteLocation(),
            'bulk_delete_locations' => $this->handleBulkDeleteLocations(),
            default           => null,
        };
    }

    /**
     * Handles the wp_ajax_eim_search_locations_list AJAX action.
     *
     * Filters the locations list table by name, city, or state and returns
     * rendered HTML rows so the browser can replace the table body without a
     * full page reload. Supports optional sort column and direction.
     *
     * Expected GET params: nonce, query, sort, order.
     * Returns JSON: { success: true, data: { html, count } }
     *
     * @return void
     */
    public function handleAjaxSearchLocationsList(): void
    {
        check_ajax_referer('eim_search_locations_list_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeLocationSortKey((string) ($_GET['sort'] ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeLocationFieldKey((string) ($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;
        $all     = Location::listForAdmin($query, $sort, $order, $field);
        $total   = count($all);
        $paged   = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderLocationRows($paged, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => $total,
            'total' => $total,
        ]);
    }

    /**
     * Handles the wp_ajax_eim_search_locations AJAX action.
     *
     * Searches the location library for entries whose name matches the supplied
     * query string. Used by the autocomplete fields on both the event venue and
     * per-event lodging add/edit forms.
     *
     * Expected GET params: nonce, query (min 2 chars).
     * Returns JSON: { success: true, data: [ { id, name, street_address, city,
     *                state, zip_code, is_other, label }, ... ] }
     *
     * @return void
     */
    public function handleAjaxSearchLocations(): void
    {
        check_ajax_referer('eim_search_locations_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query      = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $lodgingOnly = !empty($_GET['lodging_only']);

        if (mb_strlen($query) < 2) {
            wp_send_json_success([]);
        }

        wp_send_json_success(Location::search($query, 10, $lodgingOnly));
    }

    /**
     * Renders the Locations admin page, dispatching to the list or add/edit form.
     *
     * @return void
     */
    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderLocationForm(null),
            'edit'  => $this->renderLocationForm(Location::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderLocationsList(),
        };
    }

    /**
     * Processes creating or updating a library location from the admin form.
     *
     * @return void
     */
    private function handleSaveLocation(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_location')) {
            wp_die('Security check failed.');
        }

        $id      = (int) ($_POST['location_id'] ?? 0);
        $isOther = !empty($_POST['is_other']);

        $data = [
            'name'                => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'is_other'            => $isOther,
            'street_address'      => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
            'city'                => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'state'               => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
            'zip_code'            => sanitize_text_field(wp_unslash($_POST['zip_code'] ?? '')),
            'has_lodging'         => !empty($_POST['has_lodging']),
            'booking_url'         => esc_url_raw(wp_unslash($_POST['booking_url'] ?? '')),
            'description'         => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'image_attachment_id' => $this->sanitizeImageAttachmentId((int) ($_POST['image_attachment_id'] ?? 0)),
        ];

        if (empty($data['name'])) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'location_name_required',
            ]));
            exit;
        }

        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        if ($id > 0) {
            Location::update($id, $data);
            Category::syncToEntity('location', $id, $categoryIds);
            $message = 'location_updated';
        } else {
            $locationId = Location::create($data);
            if (is_int($locationId) && $locationId > 0) {
                Category::syncToEntity('location', $locationId, $categoryIds);
            }
            $message = 'location_created';
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, [
            'eim_message' => $message,
        ]));
        exit;
    }

    /**
     * Processes deleting a library location via a GET request with a nonce.
     *
     * @return void
     */
    private function handleDeleteLocation(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_location_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::syncToEntity('location', $id, []);
        Location::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, [
            'eim_message' => 'location_deleted',
        ]));
        exit;
    }

    private function handleBulkDeleteLocations(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_locations')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('location', $id, []);
            Location::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    /**
     * Renders the library locations list with search and sortable columns.
     *
     * @return void
     */
    private function renderLocationsList(): void
    {
        $message   = (string) ($_GET['eim_message'] ?? '');
        $error     = (string) ($_GET['eim_error'] ?? '');
        $search    = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort      = $this->sanitizeLocationSortKey((string) ($_GET['sort'] ?? 'name'));
        $order     = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field     = $this->sanitizeLocationFieldKey((string) ($_GET['field'] ?? ''));
        $all       = Location::listForAdmin($search, $sort, $order, $field);
        $total     = count($all);
        $locations = array_slice($all, 0, 10);
        $addUrl    = AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'add']);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Locations</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Location</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Manage your location library here. These locations are available when setting venue and lodging details on events.
            </p>

            <?php $this->renderSearchBar(
                'eim-location-search',
                'eim-location-count',
                'eim-location-loading',
                'Search by name, city, or state…',
                $total,
                $search,
                [
                    ['value' => 'name',        'label' => 'Name'],
                    ['value' => 'is_other',    'label' => 'Type'],
                    ['value' => 'has_lodging', 'label' => 'Lodging'],
                    ['value' => 'address',     'label' => 'Address'],
                    ['value' => 'used_in',     'label' => 'Used In'],
                ],
                $field
            ); ?>

            <?php $this->renderBulkActions(
                'eim-locations-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS),
                'bulk_delete_locations',
                'eim_bulk_delete_locations'
            ); ?>

            <table id="eim-locations-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('locations'); ?>
                        <th class="eim-li-image-column">Image</th>
                        <th style="width:24%;"><?= $this->sortLink('Name', 'name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_LOCATIONS]); ?></th>
                        <th style="width:12%;"><?= $this->sortLink('Type', 'is_other', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_LOCATIONS]); ?></th>
                        <th style="width:10%;"><?= $this->sortLink('Lodging', 'has_lodging', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_LOCATIONS]); ?></th>
                        <th style="width:20%;">Address / Booking</th>
                        <th style="width:16%;">Used In</th>
                        <th style="width:10%;">Categories</th>
                        <th style="width:12%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-locations-table-body">
                    <?php $this->renderLocationRows($locations, $search); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-location-search'); ?>

            <?php if (empty($locations) && $search === ''): ?>
                <p style="margin-top:12px;">No locations yet. <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'add'])); ?>">Add the first location.</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders location table rows for both the initial page load and AJAX responses.
     *
     * @param Location[] $locations
     * @return void
     */
    private function renderLocationRows(array $locations, string $search = '', int $offset = 0): void
    {
        if (empty($locations)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No locations found.';
            echo $this->renderNoResultsRow($msg);
            return;
        }

        $usageByLocation = Location::eventUsageForLocations(array_map(static fn(Location $loc): int => $loc->id, $locations));
        $locationIds     = array_map(static fn(Location $loc): int => $loc->id, $locations);
        $catsByLocation  = Category::forEntities('location', $locationIds);

        foreach ($locations as $i => $loc) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'edit', 'id' => $loc->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'delete_location', 'id' => $loc->id]),
                'eim_delete_location_' . $loc->id
            );
            ?>
            <tr>
                <?= $this->renderLeadingCells('eim-locations-bulk-form', 'locations', $loc->id, $loc->name, $offset + $i + 1); ?>
                <td><?= $this->locationImageThumbnailMarkup($loc->imageAttachmentId, $loc->name); ?></td>
                <td><strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($loc->name); ?></a></strong></td>
                <td>
                    <?php if ($loc->isOther): ?>
                        <span style="background:#f0f0f1;padding:2px 8px;border-radius:3px;font-size:12px;">Other</span>
                    <?php else: ?>
                        <span style="background:#dff0d8;padding:2px 8px;border-radius:3px;font-size:12px;">Specific</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($loc->hasLodging): ?>
                        <span style="background:#d7f2ff;padding:2px 8px;border-radius:3px;font-size:12px;">Yes</span>
                    <?php else: ?>
                        <span style="color:#999;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= esc_html($loc->formattedAddress() ?: '—'); ?>
                    <?php if ($loc->hasLodging && $loc->bookingUrl): ?>
                        <br><a href="<?= esc_url($loc->bookingUrl); ?>" target="_blank" rel="noopener" style="font-size:12px;">Book →</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $usage = $usageByLocation[$loc->id] ?? []; ?>
                    <?php if (empty($usage)): ?>
                        <span style="color:#999;">Not used yet</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($usage as $eventUsage): ?>
                                <?php
                                $eventUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $eventUsage['id']]);
                                $roles    = array_map(
                                    static fn(string $role): string => $role === 'venue' ? 'Venue' : 'Lodging',
                                    $eventUsage['roles']
                                );
                                ?>
                                <a class="eim-event-tag" href="<?= esc_url($eventUrl); ?>">
                                    <?= esc_html($eventUsage['name']); ?>
                                    <span class="eim-event-tag-role"><?= esc_html(implode(' + ', $roles)); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $cats = $catsByLocation[$loc->id] ?? []; ?>
                    <?php foreach ($cats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($cats)): ?><span style="color:#999;">—</span><?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete <?= esc_js($loc->name); ?>?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Sanitizes a location table sort key against the allowed column list.
     *
     * @param string $key
     * @return string
     */
    private function sanitizeLocationSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['name', 'is_other', 'has_lodging'], true) ? $key : 'name';
    }

    /**
     * Sanitizes a location search field key against the allowed column list.
     *
     * @param string $field Raw field key.
     * @return string Validated key, or '' for any-column search.
     */
    private function sanitizeLocationFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['name', 'is_other', 'has_lodging', 'address', 'used_in'], true) ? $field : '';
    }

    /**
     * Renders the add/edit form for a library location.
     *
     * @param Location|null $location Existing location to edit, or null when adding.
     * @return void
     */
    private function renderLocationForm(?Location $location): void
    {
        $isNew   = $location === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error'] ?? '');
        $backUrl = AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS);
        $title   = $isNew ? 'Add Location' : 'Edit Location';
        $isOther = !$isNew && $location->isOther;
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Locations</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS)); ?>">
                <?php wp_nonce_field('eim_save_location'); ?>
                <input type="hidden" name="eim_action" value="save_location">
                <input type="hidden" name="location_id" value="<?= esc_attr($isNew ? 0 : $location->id); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="eim_lib_name">Location Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_lib_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $location->name); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Type</th>
                        <td>
                            <label>
                                <input type="checkbox" id="eim_lib_is_other" name="is_other" value="1"
                                       <?php checked($isOther); ?>
                                       onchange="document.getElementById('eim-lib-address-fields').style.display = this.checked ? 'none' : '';">
                                This is an "Other" option (no fixed address — e.g. Airbnb, personal arrangement)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Lodging</th>
                        <td>
                            <label>
                                <input type="checkbox" id="eim_lib_has_lodging" name="has_lodging" value="1"
                                       <?php checked(!$isNew && $location->hasLodging); ?>
                                       onchange="document.getElementById('eim-lib-booking-url-row').style.display = this.checked ? '' : 'none';">
                                This location offers lodging (hotel, B&amp;B, rental, etc.)
                            </label>
                            <p class="description">Only locations with this checked will appear when adding lodging to an event.</p>
                        </td>
                    </tr>
                    <tr id="eim-lib-booking-url-row" <?= (!$isNew && $location->hasLodging) ? '' : 'style="display:none;"'; ?>>
                        <th scope="row"><label for="eim_lib_booking_url">Booking Website</label></th>
                        <td>
                            <input type="url" id="eim_lib_booking_url" name="booking_url" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $location->bookingUrl); ?>"
                                   placeholder="https://…">
                            <p class="description">Optional URL where invitees can book their stay.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Image</th>
                        <td>
                            <?php
                            $thumbUrl = (!$isNew && $location->imageAttachmentId > 0)
                                ? (wp_get_attachment_image_url($location->imageAttachmentId, 'thumbnail') ?: '')
                                : '';
                            $fullUrl  = (!$isNew && $location->imageAttachmentId > 0)
                                ? (wp_get_attachment_image_url($location->imageAttachmentId, 'full') ?: '')
                                : '';
                            ?>
                            <input type="hidden" id="eim_loc_image_attachment_id" name="image_attachment_id"
                                   value="<?= esc_attr((string) ($isNew ? 0 : $location->imageAttachmentId)); ?>">
                            <div class="eim-li-image-picker" id="eim-loc-image-picker">
                                <div id="eim_loc_image_preview" class="eim-li-image-preview">
                                    <?php if ($thumbUrl): ?>
                                        <button type="button" class="button-link eim-li-image-thumb"
                                                data-full-src="<?= esc_attr($fullUrl); ?>"
                                                data-caption="<?= esc_attr($isNew ? '' : $location->name); ?>"
                                                aria-label="View full-size image">
                                            <img src="<?= esc_attr($thumbUrl); ?>" alt="" loading="lazy">
                                        </button>
                                    <?php else: ?>
                                        <span class="description">No image selected.</span>
                                    <?php endif; ?>
                                </div>
                                <p class="eim-li-image-actions">
                                    <button type="button" id="eim_loc_image_select" class="button"
                                            data-select-label="Select Image"
                                            data-change-label="Change Image">
                                        <?= $thumbUrl ? 'Change Image' : 'Select Image'; ?>
                                    </button>
                                    <button type="button" id="eim_loc_image_remove" class="button"
                                            <?= $thumbUrl ? '' : 'hidden'; ?>>Remove Image</button>
                                </p>
                            </div>
                            <p class="description" style="margin-top:6px;">Optional thumbnail from the WordPress Media Library. Shown in the location list and on event venue/lodging panels.</p>
                        </td>
                    </tr>
                </table>

                <div id="eim-lib-address-fields" <?= $isOther ? 'style="display:none;"' : ''; ?>>
                    <h2 class="title">Address</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="eim_lib_street">Street Address</label></th>
                            <td>
                                <input type="text" id="eim_lib_street" name="street_address" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $location->streetAddress); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_lib_city">City</label></th>
                            <td>
                                <input type="text" id="eim_lib_city" name="city" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $location->city); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_lib_state">State</label></th>
                            <td>
                                <input type="text" id="eim_lib_state" name="state" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $location->state); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_lib_zip">ZIP Code</label></th>
                            <td>
                                <input type="text" id="eim_lib_zip" name="zip_code" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $location->zipCode); ?>">
                            </td>
                        </tr>
                    </table>
                </div>

                <h2 class="title">Categories</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php
                            $selCats  = [];
                            $catNonce = wp_create_nonce('eim_suggest_categories_nonce');
                            if (!$isNew) {
                                foreach (Category::forEntity('location', $location->id) as $cat) {
                                    $selCats[] = [
                                        'id'          => $cat->id,
                                        'name'        => $cat->name,
                                        'parent_name' => $cat->parentName,
                                        'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                    ];
                                }
                            }
                            $this->renderCategoryPicker('eim-location-cat-picker', $selCats, $catNonce);
                            ?>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Description</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_lib_description">Description</label></th>
                        <td>
                            <textarea id="eim_lib_description" name="description" rows="4" class="large-text"><?= esc_textarea($isNew ? '' : $location->description); ?></textarea>
                            <p class="description">Optional notes or details about this location. Shown to admins only.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Add Location' : 'Update Location'); ?>
            </form>
        </div>
        <script>
        (() => {
            'use strict';
            document.addEventListener('DOMContentLoaded', () => {
                const field   = document.getElementById('eim_loc_image_attachment_id');
                const preview = document.getElementById('eim_loc_image_preview');
                const select  = document.getElementById('eim_loc_image_select');
                const remove  = document.getElementById('eim_loc_image_remove');
                if (!field || !preview || !select || !window.wp?.media) return;

                let frame = null;

                select.addEventListener('click', () => {
                    if (!frame) {
                        frame = window.wp.media({
                            title: 'Select Location Image',
                            button: { text: 'Use This Image' },
                            library: { type: 'image' },
                            multiple: false,
                        });
                        frame.on('select', () => {
                            const att = frame.state().get('selection').first()?.toJSON();
                            if (!att) return;
                            renderSelection({
                                id:       att.id || 0,
                                title:    att.title || att.filename || '',
                                thumbUrl: att.sizes?.thumbnail?.url || att.sizes?.medium?.url || att.url || '',
                                fullUrl:  att.sizes?.full?.url || att.url || '',
                            });
                        });
                    }
                    frame.open();
                });

                if (remove) {
                    remove.addEventListener('click', () => renderSelection(null));
                }

                function renderSelection(image) {
                    const hasImage = image && Number(image.id) > 0 && image.thumbUrl;
                    field.value = hasImage ? String(image.id) : '0';
                    preview.replaceChildren();
                    if (hasImage) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'button-link eim-location-image-thumb';
                        btn.dataset.fullSrc = image.fullUrl || image.thumbUrl;
                        btn.dataset.caption = image.title || '';
                        btn.setAttribute('aria-label', 'View full-size image');
                        const img = document.createElement('img');
                        img.src = image.thumbUrl;
                        img.alt = '';
                        img.loading = 'lazy';
                        btn.appendChild(img);
                        preview.appendChild(btn);
                    } else {
                        const empty = document.createElement('span');
                        empty.className = 'description';
                        empty.textContent = 'No image selected.';
                        preview.appendChild(empty);
                    }
                    if (remove) remove.hidden = !hasImage;
                    select.textContent = hasImage
                        ? (select.dataset.changeLabel || 'Change Image')
                        : (select.dataset.selectLabel || 'Select Image');
                }
            });
        })();
        </script>
        <?php
    }

    private function sanitizeImageAttachmentId(int $attachmentId): int
    {
        if ($attachmentId <= 0) {
            return 0;
        }
        $post = get_post($attachmentId);
        return ($post && $post->post_type === 'attachment') ? $attachmentId : 0;
    }
}
