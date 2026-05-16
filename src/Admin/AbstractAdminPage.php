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
            <span id="<?= esc_attr($countId); ?>" class="description">
                <?= esc_html($count); ?> result<?= $count === 1 ? '' : 's'; ?>
            </span>
            <span id="<?= esc_attr($spinnerId); ?>" class="spinner" aria-hidden="true"></span>
        </div>
        <?php
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
            'menu_item_deleted'          => 'Menu item deleted from library.',
            'menu_item_added_to_event'   => 'Menu item added to event.',
            'menu_item_removed_from_event' => 'Menu item removed from event.',
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
        ];

        if (isset($successes[$messageKey])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($successes[$messageKey]) . '</p></div>';
        }

        if (isset($errors[$errorKey])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($errors[$errorKey]) . '</p></div>';
        }
    }
}
