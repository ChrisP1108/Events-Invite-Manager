<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Shared rendering helpers for the plugin's admin page classes.
 */
abstract class AbstractAdminPage
{
    /**
     * Renders the search/filter bar used above list tables.
     *
     * Outputs a shared .eim-list-table-controls wrapper containing a search
     * input, an optional column-filter dropdown, a live result-count label,
     * and a WordPress spinner element. JavaScript on each page drives the
     * AJAX refresh; this method is purely responsible for the initial
     * server-rendered markup.
     *
     * @param string                              $inputId        HTML id for the <input type="search">.
     * @param string                              $countId        HTML id for the result-count <span>.
     * @param string                              $spinnerId      HTML id for the spinner <span>.
     * @param string                              $placeholder    Visible placeholder and accessible label text.
     * @param int                                 $count          Initial row count shown before any search.
     * @param string                              $currentSearch  Current search value pre-filled into the input.
     * @param array<int,array{value:string,label:string}> $filterOptions Column options for the field dropdown.
     * @param string                              $currentField   Currently selected field option value.
     * @return void
     */
    protected function renderSearchBar(
        string $inputId,
        string $countId,
        string $spinnerId,
        string $placeholder,
        int    $count,
        string $currentSearch  = '',
        array  $filterOptions  = [],
        string $currentField   = ''
    ): void {
        // Fewer than two items and no active filter — a search bar is redundant.
        if ($count < 2 && $currentSearch === '' && $currentField === '') {
            return;
        }
        ?>
        <div class="eim-list-table-controls">
            <label class="screen-reader-text" for="<?= esc_attr($inputId); ?>"><?= esc_html($placeholder); ?></label>
            <input type="search"
                   id="<?= esc_attr($inputId); ?>"
                   class="regular-text"
                   value="<?= esc_attr($currentSearch); ?>"
                   placeholder="<?= esc_attr($placeholder); ?>"
                   autocomplete="off">
            <?php if (!empty($filterOptions)): ?>
                <label class="screen-reader-text" for="<?= esc_attr($inputId); ?>-field">Search in column</label>
                <select id="<?= esc_attr($inputId); ?>-field" class="eim-search-field-select">
                    <option value="">Any</option>
                    <?php foreach ($filterOptions as $option): ?>
                        <option value="<?= esc_attr($option['value']); ?>"
                                <?= selected($currentField, $option['value'], false); ?>>
                            <?= esc_html($option['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <label class="screen-reader-text" for="<?= esc_attr($inputId); ?>-per-page">Per page</label>
            <select id="<?= esc_attr($inputId); ?>-per-page" class="eim-per-page-select">
                <option value="5">5 / page</option>
                <option value="10" selected>10 / page</option>
                <option value="25">25 / page</option>
                <option value="50">50 / page</option>
                <option value="100">100 / page</option>
            </select>
            <span id="<?= esc_attr($countId); ?>" class="description">
                <?= esc_html($count); ?> result<?= $count === 1 ? '' : 's'; ?>
            </span>
            <span id="<?= esc_attr($spinnerId); ?>" class="spinner" aria-hidden="true"></span>
        </div>
        <?php
    }

    /**
     * Outputs the pagination nav placeholder used by window.eimRenderPagination().
     *
     * The element starts hidden; JS shows and populates it after each AJAX response
     * whenever total results exceed the current per-page limit.
     *
     * @param string $inputId The same $inputId passed to renderSearchBar() for this table.
     * @return void
     */
    protected function renderPaginationBar(string $inputId): void
    {
        echo '<nav id="' . esc_attr($inputId) . '-pagination" class="eim-pagination-nav" hidden aria-label="Pagination"></nav>';
    }

    /**
     * Renders the reusable bulk action control shown above list tables.
     *
     * Row checkboxes live inside the table and point back to this form via the
     * HTML form attribute, allowing the controls to sit between search and table.
     *
     * @param string               $formId      Unique form id used by row checkbox form attributes.
     * @param string               $actionUrl   Form submit URL.
     * @param string               $eimAction   eim_action value dispatched by AdminMenu.
     * @param string               $nonceAction Nonce action used for wp_nonce_field().
     * @param array<string,string|int> $hiddenFields Extra hidden fields to submit with the bulk action.
     * @return void
     */
    protected function renderBulkActions(
        string $formId,
        string $actionUrl,
        string $eimAction,
        string $nonceAction,
        array  $hiddenFields = []
    ): void {
        ?>
        <form id="<?= esc_attr($formId); ?>"
              class="eim-bulk-actions"
              method="post"
              action="<?= esc_url($actionUrl); ?>"
              data-eim-bulk-form>
            <?php wp_nonce_field($nonceAction); ?>
            <input type="hidden" name="eim_action" value="<?= esc_attr($eimAction); ?>">
            <?php foreach ($hiddenFields as $name => $value): ?>
                <input type="hidden" name="<?= esc_attr((string) $name); ?>" value="<?= esc_attr((string) $value); ?>">
            <?php endforeach; ?>
            <label class="screen-reader-text" for="<?= esc_attr($formId); ?>-action">Bulk action</label>
            <select id="<?= esc_attr($formId); ?>-action" name="bulk_action">
                <option value="">Bulk actions</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="button">Apply</button>
        </form>
        <?php
    }

    protected function renderBulkActionFormShell(
        string $formId,
        string $actionUrl,
        string $eimAction,
        string $nonceAction,
        array  $hiddenFields = []
    ): void {
        ?>
        <form id="<?= esc_attr($formId); ?>"
              method="post"
              action="<?= esc_url($actionUrl); ?>"
              data-eim-bulk-form
              hidden>
            <?php wp_nonce_field($nonceAction); ?>
            <input type="hidden" name="eim_action" value="<?= esc_attr($eimAction); ?>">
            <?php foreach ($hiddenFields as $name => $value): ?>
                <input type="hidden" name="<?= esc_attr((string) $name); ?>" value="<?= esc_attr((string) $value); ?>">
            <?php endforeach; ?>
        </form>
        <?php
    }

    protected function renderBulkActionControls(string $formId): void
    {
        ?>
        <div class="eim-bulk-actions">
            <label class="screen-reader-text" for="<?= esc_attr($formId); ?>-action">Bulk action</label>
            <select id="<?= esc_attr($formId); ?>-action" name="bulk_action" form="<?= esc_attr($formId); ?>">
                <option value="">Bulk actions</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="button" form="<?= esc_attr($formId); ?>">Apply</button>
        </div>
        <?php
    }

    protected function renderBulkSelectHeader(string $group): string
    {
        return sprintf(
            '<input type="checkbox" class="eim-bulk-select-all" data-eim-bulk-group="%s" aria-label="%s">',
            esc_attr($group),
            esc_attr__('Select all visible rows', 'events-invite-manager')
        );
    }

    protected function renderBulkSelectCell(string $formId, string $group, int $id, string $label): string
    {
        return sprintf(
            '<td class="eim-bulk-select-cell"><input type="checkbox" form="%s" class="eim-bulk-select-row" data-eim-bulk-group="%s" name="bulk_ids[]" value="%d" aria-label="%s"></td>',
            esc_attr($formId),
            esc_attr($group),
            $id,
            esc_attr(sprintf(__('Select %s', 'events-invite-manager'), $label))
        );
    }

    /**
     * Returns sanitized IDs from a submitted bulk action request.
     *
     * @return int[]
     */
    protected function bulkActionIds(): array
    {
        $raw = wp_unslash($_POST['bulk_ids'] ?? []);
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    protected function requestedBulkAction(): string
    {
        return sanitize_key(wp_unslash($_POST['bulk_action'] ?? ''));
    }

    protected function giftImageThumbnailMarkup(int $attachmentId, string $label): string
    {
        if ($attachmentId <= 0) {
            return '<span class="eim-gift-image-empty" aria-hidden="true">—</span>';
        }

        $thumbUrl = wp_get_attachment_image_url($attachmentId, 'thumbnail');
        $fullUrl  = wp_get_attachment_image_url($attachmentId, 'full');

        if (!is_string($thumbUrl) || $thumbUrl === '' || !is_string($fullUrl) || $fullUrl === '') {
            return '<span class="eim-gift-image-empty" aria-hidden="true">—</span>';
        }

        return sprintf(
            '<button type="button" class="button-link eim-gift-image-thumb" data-full-src="%s" data-caption="%s" aria-label="%s"><img src="%s" alt="" loading="lazy"></button>',
            esc_url($fullUrl),
            esc_attr($label),
            esc_attr(sprintf(__('View full-size image for %s', 'events-invite-manager'), $label)),
            esc_url($thumbUrl)
        );
    }

    protected function lineItemImageThumbnailMarkup(int $attachmentId, string $label): string
    {
        if ($attachmentId <= 0) {
            return '<span class="eim-li-image-empty" aria-hidden="true">—</span>';
        }

        $thumbUrl = wp_get_attachment_image_url($attachmentId, 'thumbnail');
        $fullUrl  = wp_get_attachment_image_url($attachmentId, 'full');

        if (!is_string($thumbUrl) || $thumbUrl === '' || !is_string($fullUrl) || $fullUrl === '') {
            return '<span class="eim-li-image-empty" aria-hidden="true">—</span>';
        }

        return sprintf(
            '<button type="button" class="button-link eim-li-image-thumb" data-full-src="%s" data-caption="%s" aria-label="%s"><img src="%s" alt="" loading="lazy"></button>',
            esc_url($fullUrl),
            esc_attr($label),
            esc_attr(sprintf(__('View full-size image for %s', 'events-invite-manager'), $label)),
            esc_url($thumbUrl)
        );
    }

    protected function inviteeImageThumbnailMarkup(int $attachmentId, string $label): string
    {
        if ($attachmentId <= 0) {
            return '<span class="eim-invitee-image-empty" aria-hidden="true">—</span>';
        }

        $thumbUrl = wp_get_attachment_image_url($attachmentId, 'thumbnail');
        $fullUrl  = wp_get_attachment_image_url($attachmentId, 'full');

        if (!is_string($thumbUrl) || $thumbUrl === '' || !is_string($fullUrl) || $fullUrl === '') {
            return '<span class="eim-invitee-image-empty" aria-hidden="true">—</span>';
        }

        return sprintf(
            '<button type="button" class="button-link eim-invitee-image-thumb" data-full-src="%s" data-caption="%s" aria-label="%s"><img src="%s" alt="" loading="lazy"></button>',
            esc_url($fullUrl),
            esc_attr($label),
            esc_attr(sprintf(__('View full-size image for %s', 'events-invite-manager'), $label)),
            esc_url($thumbUrl)
        );
    }

    /**
     * Builds a sortable column header link with AJAX data attributes and GET fallback.
     *
     * The rendered <a> tag carries data-sort and data-order attributes consumed
     * by the page-specific JavaScript to trigger an AJAX re-fetch. The href
     * provides a no-JS fallback that reloads the page with updated sort params.
     *
     * @param string $label        Visible column label.
     * @param string $key          Sort key (must be validated by the calling page).
     * @param string $pageSlug     Admin menu page slug for the GET fallback URL.
     * @param string $currentSort  Currently active sort key.
     * @param string $currentOrder Currently active sort direction ('asc' or 'desc').
     * @param string $search       Current search string to preserve in the fallback URL.
     * @return string              HTML anchor element (not escaped — caller must echo directly).
     */
    protected function sortLink(
        string $label,
        string $key,
        string $pageSlug,
        string $currentSort,
        string $currentOrder,
        string $search    = '',
        array  $extraArgs = []
    ): string {
        $isCurrent = $currentSort === $key;
        $nextOrder = $isCurrent && $currentOrder === 'asc' ? 'desc' : 'asc';

        // Extra args (e.g. action=edit&id=X) supply the base; sort params always win.
        $args = array_merge($extraArgs, ['page' => $pageSlug, 'sort' => $key, 'order' => $nextOrder]);

        if ($search !== '') {
            $args['s'] = $search;
        }

        $url       = add_query_arg($args, admin_url('admin.php'));
        $indicator = $isCurrent ? ($currentOrder === 'asc' ? '^' : 'v') : '';

        return sprintf(
            '<a href="%s" class="eim-sort-link" data-sort="%s" data-order="%s">%s <span aria-hidden="true">%s</span></a>',
            esc_url($url),
            esc_attr($key),
            esc_attr($nextOrder),
            esc_html($label),
            esc_html($indicator)
        );
    }

    /**
     * Sanitizes a sort direction string to 'asc' or 'desc'.
     *
     * @param string $order Raw order value from user input.
     * @return string
     */
    protected function sanitizeSortOrder(string $order): string
    {
        return strtolower($order) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Renders a generic error message with a back link.
     *
     * @param string $message Human-readable error text.
     * @param string $backUrl URL for the back link.
     * @return void
     */
    protected function renderError(string $message, string $backUrl): void
    {
        ?>
        <div class="wrap">
            <div class="notice notice-error">
                <p><?= esc_html($message); ?> <a href="<?= esc_url($backUrl); ?>">Go back.</a></p>
            </div>
        </div>
        <?php
    }

    /**
     * Renders an admin notice based on the eim_message / eim_error query params.
     *
     * @param string $messageKey Message key from the redirect URL.
     * @param string $errorKey   Error key from the redirect URL.
     * @param int    $count      Optional count used in bulk-operation messages.
     * @return void
     */
    protected function renderNotice(string $messageKey, string $errorKey = '', int $count = 0): void
    {
        $successes = [
            'event_created'         => 'Event created successfully.',
            'event_updated'         => 'Event updated successfully.',
            'event_deleted'         => 'Event deleted.',
            'invitee_created'       => 'Invitee added successfully.',
            'invitee_updated'       => 'Invitee updated successfully.',
            'invitee_deleted'       => 'Invitee deleted.',
            'event_invitee_added'   => 'Invitee(s) added to event successfully.',
            'event_invitee_removed' => 'Invitee removed from event.',
            'primary_updated'       => 'Primary recipient updated.',
            'invite_sent'           => 'Invite email sent successfully.',
            'invites_sent'          => "Sent {$count} invite email(s) to unsent groups.",
            'lodging_created'       => 'Lodging location added successfully.',
            'lodging_updated'       => 'Lodging location updated successfully.',
            'lodging_deleted'       => 'Lodging location deleted.',
            'location_created'      => 'Location added successfully.',
            'location_updated'      => 'Location updated successfully.',
            'location_deleted'      => 'Location deleted.',
            'cg_created'            => 'Connection group created.',
            'cg_updated'            => 'Connection group updated.',
            'cg_deleted'            => 'Connection group deleted.',
            'cg_member_added'       => 'Member added to connection group.',
            'cg_member_removed'     => 'Member removed from connection group.',
            'menu_item_created'          => 'Menu item added to library.',
            'menu_item_updated'          => 'Menu item updated.',
            'menu_item_deleted'          => 'Menu item deleted from library.',
            'menu_item_added_to_event'   => 'Menu item added to event.',
            'menu_item_removed_from_event' => 'Menu item removed from event.',
            'budget_plan_created'        => 'Budget plan created.',
            'budget_plan_updated'        => 'Budget plan updated.',
            'budget_plan_deleted'        => 'Budget plan deleted.',
            'line_item_saved'            => 'Line item saved.',
            'line_item_deleted'          => 'Line item deleted.',
            'newsletter_created'         => 'Newsletter created.',
            'newsletter_updated'         => 'Newsletter updated.',
            'newsletter_deleted'         => 'Newsletter deleted.',
            'nl_tag_added'               => 'Tag added.',
            'nl_tag_deleted'             => 'Tag deleted.',
            'vendor_created'             => 'Vendor added to library.',
            'vendor_updated'             => 'Vendor updated.',
            'vendor_deleted'             => 'Vendor deleted.',
            'gift_created'               => 'Gift added to registry.',
            'gift_updated'               => 'Gift updated.',
            'gift_deleted'               => 'Gift deleted.',
            'gift_added_to_event'        => 'Gift added to event registry.',
            'gift_removed_from_event'    => 'Gift removed from event registry.',
            'seat_saved'                 => 'Seat assignment saved.',
            'riar_deleted'               => 'Request deleted.',
            'riar_approved'              => 'Request approved — invitee added to connection group.',
            'riar_denied'                => 'Request denied.',
            'category_created'           => 'Category created.',
            'category_updated'           => 'Category updated.',
            'category_deleted'           => 'Category deleted.',
            'bulk_deleted'               => 'Selected items deleted.',
        ];

        $errors = [
            'name_required'              => 'Event name is required.',
            'cg_name_required'           => 'Connection group name is required.',
            'lodging_name_required'      => 'Location name is required.',
            'location_name_required'     => 'Location name is required.',
            'venue_invalid_location'     => 'Please select the venue from the locations library — free-text entries are not allowed.',
            'lodging_invalid_location'   => 'Please select each lodging location from the locations library — free-text entries are not allowed.',
            'lodging_duplicate_location' => 'Each lodging location can only be added once per event.',
            'required_fields'            => 'First name, last name, and email address are all required.',
            'invalid_email'              => 'Please enter a valid email address.',
            'invitee_required'           => 'Please select an existing invitee before adding them to this event.',
            'invitee_already_invited'    => 'That invitee is already assigned to this event.',
            'invitee_limit_reached'      => 'This event has reached its maximum invitee limit. Increase or remove the limit to add more invitees.',
            'invite_failed'              => 'Failed to send the invite email. Please check your email configuration.',
            'group_not_found'            => 'Invitation group not found.',
            'lodging_create_failed'      => 'Could not add that lodging location. It may already be assigned to this event.',
            'not_found'                  => 'Invitee or event not found.',
            'invalid_request'            => 'Invalid request — one or more required items could not be found.',
            'menu_item_label_required'   => 'A label is required to add a menu item.',
            'menu_item_vendor_required'  => 'Create and select a vendor before saving a food or beverage item.',
            'line_item_label_required'   => 'A label is required for each line item.',
            'budget_name_required'       => 'A plan name is required.',
            'budget_save_failed'         => 'Could not save the budget plan. Please try again.',
            'per_attending_needs_event'  => '"Per attending guest" mode requires an event to be selected.',
            'newsletter_title_required'  => 'A title is required for the newsletter.',
            'nl_tag_name_required'       => 'A tag name is required.',
            'vendor_name_required'       => 'A company name is required to save a vendor.',
            'gift_name_required'         => 'A gift name is required.',
            'category_name_required'     => 'A category name is required.',
            'bulk_no_selection'          => 'Select at least one item before applying a bulk action.',
            'bulk_invalid_action'        => 'Choose a valid bulk action before applying.',
            'riar_not_found'             => 'Request not found.',
            'riar_create_failed'         => 'Could not create the invitee. Please try again.',
        ];

        if (isset($successes[$messageKey])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($successes[$messageKey]) . '</p></div>';
        }

        if (isset($errors[$errorKey])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($errors[$errorKey]) . '</p></div>';
        }
    }

    /**
     * Renders a multi-select typeahead category picker.
     *
     * The rendered container is picked up by EimCategoryPicker (admin-categories.js)
     * on DOMContentLoaded. Selected category IDs are submitted as hidden inputs
     * named $inputName (default "category_ids[]").
     *
     * @param string $containerId HTML id for the outer wrapper div.
     * @param array  $selected    Pre-selected categories — each must have keys id, name,
     *                            parent_name (string|null), and label (string).
     * @param string $nonce       Value of the eim_suggest_categories nonce.
     * @param string $inputName   Form field name for the submitted IDs.
     */
    protected function renderCategoryPicker(
        string $containerId,
        array  $selected,
        string $nonce,
        string $inputName = 'category_ids[]'
    ): void {
        $selectedJson = wp_json_encode(array_values($selected));
        ?>
        <div id="<?= esc_attr($containerId); ?>"
             class="eim-category-picker"
             data-nonce="<?= esc_attr($nonce); ?>"
             data-input-name="<?= esc_attr($inputName); ?>"
             data-selected="<?= esc_attr($selectedJson); ?>">
        </div>
        <?php
    }

    /**
     * Renders a typeahead event picker with a sortable, searchable selected-events table.
     *
     * The picker replaces the traditional checkbox fieldset wherever an admin needs to
     * associate one or more events with a record. JavaScript (EventPicker class) drives
     * the autocomplete search and the selected-items list.
     *
     * @param string $containerId  HTML id for the outer wrapper div.
     * @param array  $linked       Pre-formatted linked events. Each entry must be an
     *                             associative array with keys:
     *                               id (int), name (string), start_label (string),
     *                               end_label (string), start_raw (string), end_raw (string).
     * @param string $inputName    Form field name for the submitted IDs (default 'event_ids[]').
     * @return void
     */
    protected function renderEventPicker(string $containerId, array $linked, string $inputName = 'event_ids[]'): void
    {
        $count = count($linked);
        ?>
        <div id="<?= esc_attr($containerId); ?>" class="eim-event-picker"
             data-input-name="<?= esc_attr($inputName); ?>">

            <?php /* --- autocomplete search row --- */ ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <div class="eim-event-picker-positioner" style="position:relative;display:inline-block;">
                    <input type="text" class="eim-event-picker-search regular-text"
                           placeholder="Search events to add…" autocomplete="off">
                </div>
            </div>

            <?php /* --- selected events list --- */ ?>
            <div class="eim-event-picker-list-wrap"<?= $count === 0 ? ' style="display:none;"' : ''; ?>>

                <?php /* filter bar — hidden by JS when < 2 rows */ ?>
                <div class="eim-event-picker-filter-bar"
                     style="margin-bottom:6px;<?= $count < 2 ? 'display:none;' : ''; ?>">
                    <label class="screen-reader-text"
                           for="<?= esc_attr($containerId); ?>-filter">Filter selected events</label>
                    <input type="search"
                           id="<?= esc_attr($containerId); ?>-filter"
                           class="eim-event-picker-filter regular-text"
                           placeholder="Filter selected events…"
                           autocomplete="off">
                    <span class="eim-event-picker-count description">
                        <?= esc_html($count); ?> event<?= $count === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <table class="eim-event-picker-table wp-list-table widefat fixed striped"
                       data-sort="name" data-order="asc">
                    <thead>
                        <tr>
                            <th>
                                <a href="#" class="eim-sort-link eim-event-sort"
                                   data-sort="name" data-order="desc">
                                    Event Name <span aria-hidden="true">^</span>
                                </a>
                            </th>
                            <th style="width:26%;">
                                <a href="#" class="eim-sort-link eim-event-sort"
                                   data-sort="start" data-order="asc">
                                    Start <span aria-hidden="true"></span>
                                </a>
                            </th>
                            <th style="width:26%;">
                                <a href="#" class="eim-sort-link eim-event-sort"
                                   data-sort="end" data-order="asc">
                                    End <span aria-hidden="true"></span>
                                </a>
                            </th>
                            <th style="width:8%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="eim-event-picker-tbody">
                        <?php foreach ($linked as $ev): ?>
                        <tr data-event-id="<?= esc_attr($ev['id']); ?>"
                            data-name="<?= esc_attr(strtolower($ev['name'])); ?>"
                            data-start="<?= esc_attr($ev['start_raw']); ?>"
                            data-end="<?= esc_attr($ev['end_raw']); ?>">
                            <td><?= esc_html($ev['name']); ?></td>
                            <td><?= esc_html($ev['start_label'] ?: '—'); ?></td>
                            <td><?= esc_html($ev['end_label']   ?: '—'); ?></td>
                            <td>
                                <button type="button"
                                        class="button button-small eim-event-picker-remove">Remove</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php /* hidden inputs submitted with the form */ ?>
            <div class="eim-event-picker-hidden-inputs">
                <?php foreach ($linked as $ev): ?>
                <input type="hidden"
                       name="<?= esc_attr($inputName); ?>"
                       value="<?= esc_attr($ev['id']); ?>"
                       data-event-id="<?= esc_attr($ev['id']); ?>">
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
