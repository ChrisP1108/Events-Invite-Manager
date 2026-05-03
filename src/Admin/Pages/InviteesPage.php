<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Invitee;

/**
 * Handles global invitee-related admin actions, rendering, and AJAX search.
 */
final class InviteesPage extends AbstractAdminPage
{
    /**
     * Dispatches invitee-page form submissions and GET actions.
     *
     * @param string $action
     * @return void
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_invitee'   => $this->handleSaveInvitee(),
            'delete_invitee' => $this->handleDeleteInvitee(),
            default          => null,
        };
    }

    /**
     * Handles the wp_ajax_eim_search_invitees AJAX action for the global list table.
     *
     * Searches invitee profile fields and invited event names, then returns rendered
     * table rows so the browser can replace the table body without a full page load.
     *
     * Expected GET params: nonce, query, sort, order.
     * Returns JSON: { success: true, data: { html, count } }
     *
     * @return void
     */
    public function handleAjaxSearchInvitees(): void
    {
        check_ajax_referer('eim_search_invitees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort  = $this->sanitizeSortKey((string) ($_GET['sort'] ?? 'last_name'));
        $order = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $rows  = Invitee::listForAdmin($query, $sort, $order);

        ob_start();
        $this->renderInviteeRows($rows);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => count($rows),
        ]);
    }

    /**
     * Handles the wp_ajax_eim_suggest_invitees AJAX action for event invitee assignment.
     *
     * Searches global invitees that are not already invited to the supplied event.
     * Used by the event edit page's autocomplete picker.
     *
     * Expected GET params: nonce, query, event_id.
     * Returns JSON: { success: true, data: [ { id, name, email, phone, label }, ... ] }
     *
     * @return void
     */
    public function handleAjaxSuggestInvitees(): void
    {
        check_ajax_referer('eim_suggest_invitees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $eventId = (int) ($_GET['event_id'] ?? 0);

        if ($eventId <= 0 || mb_strlen($query) < 2) {
            wp_send_json_success([]);
        }

        $results = Invitee::searchAvailableForEvent($query, $eventId);
        $payload = array_map(static fn(Invitee $invitee): array => [
            'id'    => $invitee->id,
            'name'  => $invitee->fullName(),
            'email' => $invitee->email,
            'phone' => $invitee->phone,
            'label' => trim($invitee->fullName() . ' - ' . $invitee->email),
        ], $results);

        wp_send_json_success($payload);
    }

    /**
     * Renders the Invitees admin page, dispatching to the list or add/edit form.
     *
     * Invitees are managed globally here. Event assignment is handled from each
     * event's edit screen by selecting existing invitees.
     *
     * @return void
     */
    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderInviteeForm(null),
            'edit'  => $this->renderInviteeForm(Invitee::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderInviteesList(),
        };
    }

    /**
     * Processes creating or updating an invitee profile from the admin form.
     *
     * @return void
     */
    private function handleSaveInvitee(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_invitee')) {
            wp_die('Security check failed.');
        }

        $id = (int) ($_POST['invitee_id'] ?? 0);
        $data = [
            'first_name'     => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'      => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'email'          => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone'          => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'street_address' => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
            'city'           => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'state'          => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
            'zip_code'       => sanitize_text_field(wp_unslash($_POST['zip_code'] ?? '')),
        ];

        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_INVITEES,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'required_fields',
            ], admin_url('admin.php')));
            exit;
        }

        if ($id > 0) {
            Invitee::update($id, $data);
            $message = 'invitee_updated';
        } else {
            Invitee::create($data);
            $message = 'invitee_created';
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_INVITEES,
            'eim_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Processes deleting a single invitee profile via a GET request with a nonce.
     *
     * Deleting an invitee also removes their event invitation associations.
     *
     * @return void
     */
    private function handleDeleteInvitee(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_invitee_' . $id)) {
            wp_die('Security check failed.');
        }

        Invitee::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_INVITEES,
            'eim_message' => 'invitee_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the global invitees list table.
     *
     * @return void
     */
    private function renderInviteesList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error'] ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort'] ?? 'last_name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $rows    = Invitee::listForAdmin($search, $sort, $order);
        $addUrl  = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=add');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Invitees</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Invitee</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Add and edit invitee profiles here. Invite people to specific events from the event edit screen.
            </p>

            <div class="eim-invitee-table-controls">
                <label class="screen-reader-text" for="eim-invitee-search">Search invitees</label>
                <input type="search"
                       id="eim-invitee-search"
                       class="regular-text"
                       value="<?= esc_attr($search); ?>"
                       placeholder="Search invitees or invited events..."
                       autocomplete="off">
                <span id="eim-invitee-count" class="description">
                    <?= esc_html(count($rows)); ?> result<?= count($rows) === 1 ? '' : 's'; ?>
                </span>
                <span id="eim-invitee-loading" class="spinner" aria-hidden="true"></span>
            </div>

            <table id="eim-invitees-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>">
                <thead>
                    <tr>
                        <th style="width:14%;"><?= $this->sortLink('First Name', 'first_name', $sort, $order, $search); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Last Name', 'last_name', $sort, $order, $search); ?></th>
                        <th style="width:22%;"><?= $this->sortLink('Email', 'email', $sort, $order, $search); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Phone', 'phone', $sort, $order, $search); ?></th>
                        <th><?= $this->sortLink('Invited Events', 'events', $sort, $order, $search); ?></th>
                        <th style="width:12%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-invitees-table-body">
                    <?php $this->renderInviteeRows($rows); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Renders invitee table rows for both the initial page load and AJAX responses.
     *
     * @param array<int, array{invitee: Invitee, events: array<int, array{id: int, name: string}>}> $rows
     * @return void
     */
    private function renderInviteeRows(array $rows): void
    {
        if (empty($rows)) {
            ?>
            <tr class="eim-no-results">
                <td colspan="6">No invitees found.</td>
            </tr>
            <?php
            return;
        }

        foreach ($rows as $row) {
            /** @var Invitee $invitee */
            $invitee = $row['invitee'];
            $editUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=edit&id=' . $invitee->id);
            $deleteUrl = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=delete_invitee&id=' . $invitee->id),
                'eim_delete_invitee_' . $invitee->id
            );
            ?>
            <tr>
                <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->firstName); ?></a></td>
                <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->lastName); ?></a></td>
                <td><a href="mailto:<?= esc_attr($invitee->email); ?>"><?= esc_html($invitee->email); ?></a></td>
                <td><a href="tel:<?= esc_html($invitee->phone ?: '-');?>"><?= esc_html(str_replace('-', '', $invitee->phone)); ?></a></td>
                <td>
                    <?php if (empty($row['events'])): ?>
                        <span style="color:#999;">Not invited yet</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($row['events'] as $event): ?>
                                <?php $eventUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $event['id'] . '#eim-event-invitees'); ?>
                                <a class="eim-event-tag" href="<?= esc_url($eventUrl); ?>">
                                    <?= esc_html($event['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete <?= esc_js($invitee->fullName()); ?> and remove them from all events?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Renders the add/edit invitee profile form.
     *
     * @param Invitee|null $invitee Existing invitee to edit, or null when adding a new one.
     * @return void
     */
    private function renderInviteeForm(?Invitee $invitee): void
    {
        if (isset($_GET['id']) && $invitee === null) {
            $this->renderError('Invitee not found.', admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES));
            return;
        }

        $isNew   = $invitee === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error'] ?? '');
        $backUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES);
        $title   = $isNew ? 'Add Invitee' : 'Edit Invitee';
        $events  = $isNew ? [] : Invitee::eventsForInvitee($invitee->id);
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">Back to Invitees</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES)); ?>">
                <?php wp_nonce_field('eim_save_invitee'); ?>
                <input type="hidden" name="eim_action" value="save_invitee">
                <input type="hidden" name="invitee_id" value="<?= esc_attr($isNew ? 0 : $invitee->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="eim_first_name">First Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_first_name" name="first_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->firstName); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_last_name">Last Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_last_name" name="last_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->lastName); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_email">Email Address <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="email" id="eim_email" name="email" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->email); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_phone">Phone</label>
                        </th>
                        <td>
                            <input type="tel" id="eim_phone" name="phone" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->phone); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_street_address">Street Address</label>
                        </th>
                        <td>
                            <input type="text" id="eim_street_address" name="street_address" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->streetAddress); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_city">City</label>
                        </th>
                        <td>
                            <input type="text" id="eim_city" name="city" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->city); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_state">State</label>
                        </th>
                        <td>
                            <input type="text" id="eim_state" name="state" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->state); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_zip_code">ZIP Code</label>
                        </th>
                        <td>
                            <input type="text" id="eim_zip_code" name="zip_code" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->zipCode); ?>">
                        </td>
                    </tr>
                    <?php if (!$isNew): ?>
                        <tr>
                            <th scope="row">Invited Events</th>
                            <td>
                                <?php if (empty($events)): ?>
                                    <span style="color:#999;">Not invited to any events yet.</span>
                                <?php else: ?>
                                    <span class="eim-tag-list">
                                        <?php foreach ($events as $event): ?>
                                            <?php $eventUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $event['id'] . '#eim-event-invitees'); ?>
                                            <a class="eim-event-tag" href="<?= esc_url($eventUrl); ?>">
                                                <?= esc_html($event['name']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <p class="description">Add or remove this invitee from events on each event edit screen.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($isNew ? 'Add Invitee' : 'Update Invitee'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Builds a sortable table header link with AJAX data attributes and GET fallback.
     *
     * @param string $label
     * @param string $key
     * @param string $currentSort
     * @param string $currentOrder
     * @param string $search
     * @return string
     */
    private function sortLink(string $label, string $key, string $currentSort, string $currentOrder, string $search): string
    {
        $isCurrent = $currentSort === $key;
        $nextOrder = $isCurrent && $currentOrder === 'asc' ? 'desc' : 'asc';
        $url = add_query_arg([
            'page'  => AdminMenu::PAGE_INVITEES,
            'sort'  => $key,
            'order' => $nextOrder,
            's'     => $search !== '' ? $search : null,
        ], admin_url('admin.php'));
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
     * Sanitizes an invitee table sort key against the allowed column list.
     *
     * @param string $key
     * @return string
     */
    private function sanitizeSortKey(string $key): string
    {
        $key = sanitize_key($key);

        return in_array($key, ['first_name', 'last_name', 'email', 'phone', 'events'], true)
            ? $key
            : 'last_name';
    }

    /**
     * Sanitizes an invitee table sort direction.
     *
     * @param string $order
     * @return string
     */
    private function sanitizeSortOrder(string $order): string
    {
        return strtolower($order) === 'desc' ? 'desc' : 'asc';
    }
}
