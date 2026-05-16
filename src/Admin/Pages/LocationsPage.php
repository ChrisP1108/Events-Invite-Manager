<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
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

        $query     = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort      = $this->sanitizeLocationSortKey((string) ($_GET['sort'] ?? 'name'));
        $order     = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field     = $this->sanitizeLocationFieldKey((string) ($_GET['field'] ?? ''));
        $locations = Location::listForAdmin($query, $sort, $order, $field);

        ob_start();
        $this->renderLocationRows($locations, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => count($locations),
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
            'name'           => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'is_other'       => $isOther,
            'street_address' => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
            'city'           => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'state'          => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
            'zip_code'       => sanitize_text_field(wp_unslash($_POST['zip_code'] ?? '')),
            'has_lodging'    => !empty($_POST['has_lodging']),
            'booking_url'    => esc_url_raw(wp_unslash($_POST['booking_url'] ?? '')),
        ];

        if (empty($data['name'])) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_LOCATIONS,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'location_name_required',
            ], admin_url('admin.php')));
            exit;
        }

        if ($id > 0) {
            Location::update($id, $data);
            $message = 'location_updated';
        } else {
            Location::create($data);
            $message = 'location_created';
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_LOCATIONS,
            'eim_message' => $message,
        ], admin_url('admin.php')));
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

        Location::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_LOCATIONS,
            'eim_message' => 'location_deleted',
        ], admin_url('admin.php')));
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
        $locations = Location::listForAdmin($search, $sort, $order, $field);
        $addUrl    = admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=add');
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
                count($locations),
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

            <table id="eim-locations-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>">
                <thead>
                    <tr>
                        <th style="width:28%;"><?= $this->sortLink('Name', 'name', AdminMenu::PAGE_LOCATIONS, $sort, $order, $search); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Type', 'is_other', AdminMenu::PAGE_LOCATIONS, $sort, $order, $search); ?></th>
                        <th style="width:12%;"><?= $this->sortLink('Lodging', 'has_lodging', AdminMenu::PAGE_LOCATIONS, $sort, $order, $search); ?></th>
                        <th style="width:25%;">Address / Booking</th>
                        <th style="width:24%;">Used In</th>
                        <th style="width:18%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-locations-table-body">
                    <?php $this->renderLocationRows($locations, $search); ?>
                </tbody>
            </table>

            <?php if (empty($locations) && $search === ''): ?>
                <p style="margin-top:12px;">No locations yet. <a href="<?= esc_url($addUrl); ?>">Add the first location.</a></p>
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
    private function renderLocationRows(array $locations, string $search = ''): void
    {
        if (empty($locations)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No locations found.';
            echo '<tr class="eim-no-results"><td colspan="6">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $usageByLocation = Location::eventUsageForLocations(array_map(static fn(Location $loc): int => $loc->id, $locations));

        foreach ($locations as $loc) {
            $editUrl   = admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=edit&id=' . $loc->id);
            $deleteUrl = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=delete_location&id=' . $loc->id),
                'eim_delete_location_' . $loc->id
            );
            ?>
            <tr>
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
                                $eventUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $eventUsage['id']);
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
        $backUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS);
        $title   = $isNew ? 'Add Location' : 'Edit Location';
        $isOther = !$isNew && $location->isOther;
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Locations</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS)); ?>">
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

                <?php submit_button($isNew ? 'Add Location' : 'Update Location'); ?>
            </form>
        </div>
        <?php
    }
}
