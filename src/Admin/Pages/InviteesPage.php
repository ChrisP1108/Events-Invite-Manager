<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\Invitee;

/**
 * Handles global invitee-related admin actions, rendering, and AJAX search.
 */
final class InviteesPage extends AbstractAdminPage
{
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_invitee'   => $this->handleSaveInvitee(),
            'delete_invitee' => $this->handleDeleteInvitee(),
            default          => null,
        };
    }

    /**
     * AJAX: searches the global invitee list table.
     *
     * Expected GET params: nonce, query, sort, order.
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

        wp_send_json_success(['html' => $html, 'count' => count($rows)]);
    }

    /**
     * AJAX: autocomplete for the event edit invitee picker.
     *
     * Expected GET params: nonce, query, event_id.
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

        wp_send_json_success(array_map(static fn(Invitee $inv): array => [
            'id'    => $inv->id,
            'name'  => $inv->fullName(),
            'email' => $inv->email,
            'phone' => $inv->phone,
            'label' => trim($inv->fullName() . ' - ' . $inv->email),
        ], $results));
    }

    /**
     * AJAX: returns connection-group peers for the selected invitee + event.
     *
     * Returns each peer with an already_invited flag so the UI can disable
     * checkboxes for people already invited to the event.
     *
     * Expected GET params: nonce, invitee_id, event_id.
     */
    public function handleAjaxGetConnectionsForEvent(): void
    {
        check_ajax_referer('eim_suggest_invitees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $eventId   = (int) ($_GET['event_id']   ?? 0);

        if ($inviteeId <= 0 || $eventId <= 0) {
            wp_send_json_success([]);
        }

        wp_send_json_success(ConnectionGroup::connectedInviteesForEvent($inviteeId, $eventId));
    }

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderInviteeForm(null),
            'edit'  => $this->renderInviteeForm(Invitee::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderInviteesList(),
        };
    }

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

    private function renderInviteesList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort'] ?? 'last_name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $rows    = Invitee::listForAdmin($search, $sort, $order);
        $addUrl  = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=add');

        $inviteeIds      = array_map(static fn($r) => $r['invitee']->id, $rows);
        $groupsByInvitee = ConnectionGroup::forInvitees($inviteeIds);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Invitees</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Invitee</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Manage invitee profiles here. Assign people to events from the event edit screen.
                Use <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS)); ?>">Connection Groups</a>
                to define relationships like couples or families.
            </p>

            <?php $this->renderSearchBar('eim-invitee-search', 'eim-invitee-count', 'eim-invitee-loading', 'Search invitees, events, or connected people...', count($rows), $search); ?>

            <table id="eim-invitees-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>">
                <thead>
                    <tr>
                        <th style="width:12%;"><?= $this->sortLink('First Name', 'first_name', AdminMenu::PAGE_INVITEES, $sort, $order, $search); ?></th>
                        <th style="width:12%;"><?= $this->sortLink('Last Name', 'last_name', AdminMenu::PAGE_INVITEES, $sort, $order, $search); ?></th>
                        <th style="width:18%;"><?= $this->sortLink('Email', 'email', AdminMenu::PAGE_INVITEES, $sort, $order, $search); ?></th>
                        <th style="width:11%;"><?= $this->sortLink('Phone', 'phone', AdminMenu::PAGE_INVITEES, $sort, $order, $search); ?></th>
                        <th><?= $this->sortLink('Invited Events', 'events', AdminMenu::PAGE_INVITEES, $sort, $order, $search); ?></th>
                        <th style="width:17%;">Connection Groups</th>
                        <th style="width:10%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-invitees-table-body">
                    <?php $this->renderInviteeRows($rows, $groupsByInvitee); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<int, array{invitee: Invitee, events: array}>  $rows
     * @param array<int, ConnectionGroup[]>                        $groupsByInvitee
     */
    private function renderInviteeRows(array $rows, array $groupsByInvitee = []): void
    {
        if (empty($rows)) {
            ?>
            <tr class="eim-no-results"><td colspan="7">No invitees found.</td></tr>
            <?php
            return;
        }

        // Load groups for AJAX path where they weren't pre-loaded.
        if (empty($groupsByInvitee)) {
            $ids = array_map(static fn($r) => $r['invitee']->id, $rows);
            $groupsByInvitee = ConnectionGroup::forInvitees($ids);
        }

        foreach ($rows as $row) {
            /** @var Invitee $invitee */
            $invitee     = $row['invitee'];
            $editUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=edit&id=' . $invitee->id);
            $deleteUrl   = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=delete_invitee&id=' . $invitee->id),
                'eim_delete_invitee_' . $invitee->id
            );
            $connGroups  = $groupsByInvitee[$invitee->id] ?? [];
            ?>
            <tr>
                <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->firstName); ?></a></td>
                <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->lastName); ?></a></td>
                <td><a href="mailto:<?= esc_attr($invitee->email); ?>"><?= esc_html($invitee->email); ?></a></td>
                <td><?= esc_html($invitee->phone ?: '—'); ?></td>
                <td>
                    <?php if (empty($row['events'])): ?>
                        <span style="color:#999;">Not invited yet</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($row['events'] as $event): ?>
                                <a class="eim-event-tag"
                                   href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $event['id'] . '#eim-event-invitees')); ?>">
                                    <?= esc_html($event['name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($connGroups)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($connGroups as $cg): ?>
                                <a class="eim-connection-tag"
                                   href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=edit&id=' . $cg->id)); ?>"
                                   title="<?= esc_attr($cg->typeLabel()); ?>">
                                    <?= esc_html($cg->name); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete <?= esc_js($invitee->fullName()); ?> and remove them from all events and groups?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    private function renderInviteeForm(?Invitee $invitee): void
    {
        if (isset($_GET['id']) && $invitee === null) {
            $this->renderError('Invitee not found.', admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES));
            return;
        }

        $isNew        = $invitee === null;
        $message      = (string) ($_GET['eim_message'] ?? '');
        $error        = (string) ($_GET['eim_error']   ?? '');
        $backUrl      = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES);
        $title        = $isNew ? 'Add Invitee' : 'Edit Invitee';
        $events       = $isNew ? [] : Invitee::eventsForInvitee($invitee->id);
        $connGroups   = $isNew ? [] : ConnectionGroup::forInvitee($invitee->id);
        $cgAddUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=add');
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Invitees</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES)); ?>">
                <?php wp_nonce_field('eim_save_invitee'); ?>
                <input type="hidden" name="eim_action"  value="save_invitee">
                <input type="hidden" name="invitee_id"  value="<?= esc_attr($isNew ? 0 : $invitee->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eim_first_name">First Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_first_name" name="first_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->firstName); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_last_name">Last Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="text" id="eim_last_name" name="last_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->lastName); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_email">Email Address <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td><input type="email" id="eim_email" name="email" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->email); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_phone">Phone</label></th>
                        <td><input type="tel" id="eim_phone" name="phone" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->phone); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_street_address">Street Address</label></th>
                        <td><input type="text" id="eim_street_address" name="street_address" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->streetAddress); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_city">City</label></th>
                        <td><input type="text" id="eim_city" name="city" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->city); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_state">State</label></th>
                        <td><input type="text" id="eim_state" name="state" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->state); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_zip_code">ZIP Code</label></th>
                        <td><input type="text" id="eim_zip_code" name="zip_code" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->zipCode); ?>"></td>
                    </tr>

                    <?php if (!$isNew): ?>
                        <tr>
                            <th scope="row">Connection Groups</th>
                            <td>
                                <?php if (empty($connGroups)): ?>
                                    <span style="color:#999;">Not in any connection group.</span>
                                <?php else: ?>
                                    <span class="eim-tag-list">
                                        <?php foreach ($connGroups as $cg): ?>
                                            <a class="eim-connection-tag"
                                               href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=edit&id=' . $cg->id)); ?>">
                                                <span class="eim-cg-type-badge eim-cg-type-<?= esc_attr($cg->type); ?>" style="margin-right:4px;">
                                                    <?= esc_html($cg->typeLabel()); ?>
                                                </span>
                                                <?= esc_html($cg->name); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <p class="description" style="margin-top:6px;">
                                    Manage connection groups from the
                                    <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS)); ?>">Connection Groups page</a>.
                                    <a href="<?= esc_url($cgAddUrl); ?>">Create a new group →</a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Invited Events</th>
                            <td>
                                <?php if (empty($events)): ?>
                                    <span style="color:#999;">Not invited to any events yet.</span>
                                <?php else: ?>
                                    <span class="eim-tag-list">
                                        <?php foreach ($events as $event): ?>
                                            <a class="eim-event-tag"
                                               href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $event['id'] . '#eim-event-invitees')); ?>">
                                                <?= esc_html($event['name']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <p class="description">Add or remove from events on each event edit screen.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($isNew ? 'Add Invitee' : 'Update Invitee'); ?>
            </form>
        </div>
        <?php
    }

    private function sanitizeSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['first_name', 'last_name', 'email', 'phone', 'events'], true)
            ? $key
            : 'last_name';
    }
}
