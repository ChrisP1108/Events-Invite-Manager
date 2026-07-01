<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\RequestedInviteeAddOn;

/**
 * Manages the Requested Invitee Add-Ons list: frontend-submitted requests for new
 * connection-group members that require admin approval before being created.
 */
final class RequestedInviteesPage extends AbstractAdminPage
{
    /**
     * Dispatches non-AJAX form actions for this tab.
     *
     * @param string $action
     * @return void
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'delete_riar'       => $this->handleDelete(),
            'bulk_delete_riars' => $this->handleBulkDelete(),
            default             => null,
        };
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * Handles wp_ajax_eim_search_requested_invitees.
     *
     * Returns paginated, filtered, sorted table rows for the live-search UI.
     *
     * @return void
     */
    public function handleAjaxSearch(): void
    {
        check_ajax_referer('eim_search_requested_invitees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort'] ?? 'created_at'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'desc'));
        $field   = $this->sanitizeFieldKey((string) ($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true)
            ? (int) $_GET['per_page'] : 10;

        $all   = RequestedInviteeAddOn::listForAdmin($query, $sort, $order, $field);
        $total = count($all);
        $paged = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderRows($paged, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * Handles wp_ajax_eim_approve_invitee_request.
     *
     * Approves a request: creates the invitee, adds to connection group, auto-RSVPs
     * if invitation group context is present. Returns the updated status badge HTML.
     *
     * @return void
     */
    public function handleAjaxApprove(): void
    {
        check_ajax_referer('eim_approve_invitee_request_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $id     = (int) ($_POST['id'] ?? 0);
        $result = RequestedInviteeAddOn::approve($id);

        if (!$result['success']) {
            wp_send_json_error($result['error'] ?? 'unknown_error');
        }

        $inviteeUrl = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, [
            'action' => 'edit',
            'id'     => $result['invitee_id'],
        ]);

        wp_send_json_success([
            'status'      => 'approved',
            'invitee_id'  => $result['invitee_id'],
            'invitee_url' => $inviteeUrl,
            'badge_html'  => $this->statusBadge('approved'),
        ]);
    }

    /**
     * Handles wp_ajax_eim_deny_invitee_request.
     *
     * Marks the request as denied; it remains visible for future admin review.
     *
     * @return void
     */
    public function handleAjaxDeny(): void
    {
        check_ajax_referer('eim_deny_invitee_request_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $id = (int) ($_POST['id'] ?? 0);

        if (!RequestedInviteeAddOn::deny($id)) {
            wp_send_json_error('update_failed');
        }

        wp_send_json_success([
            'status'     => 'denied',
            'badge_html' => $this->statusBadge('denied'),
        ]);
    }

    /**
     * Handles wp_ajax_eim_update_invitee_request.
     *
     * Saves admin edits to the request's personal info fields (typo corrections etc.)
     *
     * @return void
     */
    public function handleAjaxUpdate(): void
    {
        check_ajax_referer('eim_update_invitee_request_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $id = (int) ($_POST['id'] ?? 0);

        $data = [
            'first_name'     => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'      => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'email'          => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone'          => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'street_address' => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
            'city'           => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'state'          => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
            'zip_code'       => sanitize_text_field(wp_unslash($_POST['zip_code'] ?? '')),
            'notes'          => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
        ];

        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
            wp_send_json_error('required_fields');
        }

        if (!is_email($data['email'])) {
            wp_send_json_error('invalid_email');
        }

        if (!RequestedInviteeAddOn::update($id, $data)) {
            wp_send_json_error('update_failed');
        }

        wp_send_json_success(['data' => $data]);
    }

    // -------------------------------------------------------------------------
    // Page rendering
    // -------------------------------------------------------------------------

    /**
     * Renders the Requested Invitee Add-Ons page (list only — no add/edit form).
     *
     * @return void
     */
    public function renderPage(): void
    {
        RequestedInviteeAddOn::maybeCreateTable();
        $this->renderList();
    }

    // -------------------------------------------------------------------------
    // Private rendering helpers
    // -------------------------------------------------------------------------

    private function renderList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort']  ?? 'created_at'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'desc'));
        $field   = $this->sanitizeFieldKey((string) ($_GET['field'] ?? ''));

        $all      = RequestedInviteeAddOn::listForAdmin($search, $sort, $order, $field);
        $total    = count($all);
        $requests = array_slice($all, 0, 10);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Requested Invitee Add-Ons</h1>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                These are requests submitted by invitees on the front-end RSVP form to add a new person to their connection group.
                Review the details and approve or deny each request.
            </p>

            <?php $this->renderSearchBar(
                'eim-riar-search',
                'eim-riar-count',
                'eim-riar-loading',
                'Search by name, email, or group…',
                $total,
                $search,
                [
                    ['value' => 'first_name',       'label' => 'First Name'],
                    ['value' => 'last_name',         'label' => 'Last Name'],
                    ['value' => 'email',             'label' => 'Email'],
                    ['value' => 'phone',             'label' => 'Phone'],
                    ['value' => 'connection_group',  'label' => 'Connection Group'],
                    ['value' => 'event',             'label' => 'Event'],
                    ['value' => 'status',            'label' => 'Status'],
                ],
                $field
            ); ?>

            <?php $this->renderBulkActions(
                'eim-riars-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES),
                'bulk_delete_riars',
                'eim_bulk_delete_riars'
            ); ?>

            <table id="eim-riars-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('riars'); ?>
                        <th style="width:8%;"><?= $this->sortLink('First Name', 'first_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:8%;"><?= $this->sortLink('Last Name', 'last_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:13%;"><?= $this->sortLink('Email', 'email', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:9%;"><?= $this->sortLink('Phone', 'phone', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Connection Group', 'connection_group_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:14%;"><?= $this->sortLink('Event', 'event_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:7%;">Details</th>
                        <th style="width:9%;"><?= $this->sortLink('Status', 'status', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:7%;"><?= $this->sortLink('Requested', 'created_at', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_REQUESTED_INVITEES]); ?></th>
                        <th style="width:7%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-riars-table-body">
                    <?php $this->renderRows($requests, $search); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-riar-search'); ?>

            <?php if (empty($requests) && $search === ''): ?>
                <p style="margin-top:12px;">No add-on requests yet. They will appear here when invitees submit them via the front-end RSVP form.</p>
            <?php endif; ?>
        </div>

        <?php $this->renderModal(); ?>
        <?php
    }

    /**
     * Renders table rows for both the initial page load and AJAX responses.
     *
     * @param RequestedInviteeAddOn[] $requests
     * @param string                  $search
     * @return void
     */
    private function renderRows(array $requests, string $search = '', int $offset = 0): void
    {
        if (empty($requests)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No requests found.';
            echo $this->renderNoResultsRow($msg);
            return;
        }

        foreach ($requests as $i => $req) {
            $cgUrl      = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $req->connectionGroupId]);
            $eventUrl   = $req->eventId
                ? AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $req->eventId])
                : null;
            $deleteUrl  = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES, ['action' => 'delete_riar', 'id' => $req->id]),
                'eim_delete_riar_' . $req->id
            );

            $thumbUrl = $req->imageAttachmentId > 0
                ? (string) wp_get_attachment_image_url($req->imageAttachmentId, 'thumbnail')
                : '';
            $fullUrl = $req->imageAttachmentId > 0
                ? (string) wp_get_attachment_image_url($req->imageAttachmentId, 'full')
                : '';

            $inviteeUrl = $req->approvedInviteeId
                ? AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $req->approvedInviteeId])
                : null;

            $requestData = wp_json_encode([
                'id'                  => $req->id,
                'firstName'           => $req->firstName,
                'lastName'            => $req->lastName,
                'email'               => $req->email,
                'phone'               => $req->phone,
                'streetAddress'       => $req->streetAddress,
                'city'                => $req->city,
                'state'               => $req->state,
                'zipCode'             => $req->zipCode,
                'imageThumbUrl'       => $thumbUrl,
                'imageFullUrl'        => $fullUrl,
                'notes'               => $req->notes,
                'status'              => $req->status,
                'connectionGroupId'   => $req->connectionGroupId,
                'connectionGroupName' => $req->connectionGroupName,
                'connectionGroupUrl'  => $cgUrl,
                'eventId'             => $req->eventId,
                'eventName'           => $req->eventName,
                'eventUrl'            => $eventUrl,
                'approvedInviteeId'   => $req->approvedInviteeId,
                'approvedInviteeUrl'  => $inviteeUrl,
                'createdAt'           => $req->createdAt,
            ]);
            ?>
            <tr data-riar-id="<?= esc_attr($req->id); ?>" data-request="<?= esc_attr($requestData); ?>">
                <?= $this->renderLeadingCells('eim-riars-bulk-form', 'riars', $req->id, $req->fullName(), $offset + $i + 1); ?>
                <td><?= esc_html($req->firstName); ?></td>
                <td><?= esc_html($req->lastName); ?></td>
                <td><?= esc_html($req->email); ?></td>
                <td><?= esc_html($req->phone ?: '—'); ?></td>
                <td>
                    <?php if ($req->connectionGroupName): ?>
                        <a href="<?= esc_url($cgUrl); ?>"><?= esc_html($req->connectionGroupName); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($req->eventName && $eventUrl): ?>
                        <a href="<?= esc_url($eventUrl); ?>"><?= esc_html($req->eventName); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="button button-small eim-riar-details-btn"
                            aria-label="<?= esc_attr('View details for ' . $req->fullName()); ?>">
                        Details
                    </button>
                </td>
                <td class="eim-riar-status-cell">
                    <?= $this->statusBadge($req->status); ?>
                </td>
                <td>
                    <span style="white-space:nowrap;"><?= esc_html(date('M j, Y', strtotime($req->createdAt))); ?></span>
                </td>
                <td>
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete this request from <?= esc_js($req->fullName()); ?>?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Renders the details/edit modal markup (hidden; JS populates and shows it).
     *
     * @return void
     */
    private function renderModal(): void
    {
        ?>
        <div id="eim-riar-modal-overlay" class="eim-modal-overlay" hidden aria-hidden="true">
            <div id="eim-riar-modal"
                 class="eim-modal-dialog"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="eim-riar-modal-title">

                <div class="eim-modal-header">
                    <h2 id="eim-riar-modal-title" style="margin:0;">Request Details</h2>
                    <button type="button"
                            id="eim-riar-modal-close"
                            class="eim-modal-close button-link"
                            aria-label="Close">&#x2715;</button>
                </div>

                <div class="eim-modal-body">

                    <div id="eim-riar-modal-image" style="margin-bottom:16px;display:none;">
                        <img id="eim-riar-modal-img" src="" alt="" style="max-width:80px;max-height:80px;border-radius:4px;object-fit:cover;">
                    </div>

                    <form id="eim-riar-edit-form" novalidate>
                        <input type="hidden" id="eim-riar-edit-id" name="id" value="">

                        <div class="eim-modal-field-grid">
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-first-name">First Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                                <input type="text" id="eim-riar-edit-first-name" name="first_name" class="regular-text" required>
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-last-name">Last Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                                <input type="text" id="eim-riar-edit-last-name" name="last_name" class="regular-text" required>
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-email">Email <span aria-hidden="true" style="color:#d63638;">*</span></label>
                                <input type="email" id="eim-riar-edit-email" name="email" class="regular-text" required>
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-phone">Phone</label>
                                <input type="text" id="eim-riar-edit-phone" name="phone" class="regular-text">
                            </div>
                            <div class="eim-modal-field eim-modal-field--full">
                                <label for="eim-riar-edit-street">Street Address</label>
                                <input type="text" id="eim-riar-edit-street" name="street_address" class="regular-text">
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-city">City</label>
                                <input type="text" id="eim-riar-edit-city" name="city" class="regular-text">
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-state">State</label>
                                <input type="text" id="eim-riar-edit-state" name="state" class="regular-text">
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-zip">ZIP Code</label>
                                <input type="text" id="eim-riar-edit-zip" name="zip_code" class="regular-text">
                            </div>
                            <div class="eim-modal-field eim-modal-field--full">
                                <label for="eim-riar-edit-notes">Notes about this person</label>
                                <textarea id="eim-riar-edit-notes" name="notes" rows="3" class="large-text"></textarea>
                            </div>
                        </div>

                        <div id="eim-riar-cg-info" style="margin-top:12px;padding:10px 12px;background:#f6f7f7;border-radius:4px;font-size:13px;">
                            <strong>Connection Group:</strong>
                            <a id="eim-riar-cg-link" href="#"></a>
                        </div>

                        <div id="eim-riar-approved-info" style="margin-top:10px;display:none;padding:10px 12px;background:#edfaef;border-radius:4px;font-size:13px;">
                            <strong>Approved &mdash; Invitee created:</strong>
                            <a id="eim-riar-invitee-link" href="#">View Invitee</a>
                        </div>
                    </form>
                </div>

                <div class="eim-modal-footer">
                    <div class="eim-modal-footer-left">
                        <button type="button" id="eim-riar-save-btn" class="button button-secondary">Save Changes</button>
                        <span id="eim-riar-save-notice" style="margin-left:8px;font-size:13px;display:none;"></span>
                    </div>
                    <div class="eim-modal-footer-right">
                        <button type="button" id="eim-riar-deny-btn" class="button" style="margin-right:6px;">Deny</button>
                        <button type="button" id="eim-riar-approve-btn" class="button button-primary">Approve</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form submission handlers
    // -------------------------------------------------------------------------

    private function handleDelete(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_riar_' . $id)) {
            wp_die('Security check failed.');
        }

        RequestedInviteeAddOn::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES, ['eim_message' => 'riar_deleted']));
        exit;
    }

    private function handleBulkDelete(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_riars')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            RequestedInviteeAddOn::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    // -------------------------------------------------------------------------
    // Private utilities
    // -------------------------------------------------------------------------

    /**
     * Returns an inline HTML badge for a request status value.
     *
     * @param string $status 'pending' | 'approved' | 'denied'
     * @return string
     */
    private function statusBadge(string $status): string
    {
        [$bg, $color, $label] = match ($status) {
            'approved' => ['#dff0d8', '#3c763d', 'Approved'],
            'denied'   => ['#f2dede', '#a94442', 'Denied'],
            default    => ['#fcf8e3', '#8a6d3b', 'Pending'],
        };

        return sprintf(
            '<span class="eim-status-badge" style="background:%s;color:%s;padding:2px 8px;border-radius:3px;font-size:12px;white-space:nowrap;">%s</span>',
            esc_attr($bg),
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Validates a sort column key against the allowed list.
     *
     * @param string $key
     * @return string
     */
    private function sanitizeSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['first_name', 'last_name', 'email', 'phone', 'connection_group_name', 'event_name', 'status', 'created_at'], true)
            ? $key : 'created_at';
    }

    /**
     * Validates a search field key against the allowed list.
     *
     * @param string $field
     * @return string
     */
    private function sanitizeFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['first_name', 'last_name', 'email', 'phone', 'connection_group', 'event', 'status'], true)
            ? $field : '';
    }
}
