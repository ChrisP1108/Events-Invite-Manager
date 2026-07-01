<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\MenuItem;

/**
 * Handles global invitee-related admin actions, rendering, and AJAX search.
 */
final class InviteesPage extends AbstractAdminPage
{
    /**
     * Dispatches invitee form submissions and GET actions.
     *
     * @param string $action The action slug.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_invitee'   => $this->handleSaveInvitee(),
            'delete_invitee' => $this->handleDeleteInvitee(),
            'bulk_delete_invitees' => $this->handleBulkDeleteInvitees(),
            default          => null,
        };
    }

    /**
     * AJAX: searches the global invitee list table.
     *
     * Expected GET params: nonce, query, sort, order, field.
     */
    public function handleAjaxSearchInvitees(): void
    {
        check_ajax_referer('eim_search_invitees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort'] ?? 'last_name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeInviteeFieldKey((string) ($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;
        $all     = Invitee::listForAdmin($query, $sort, $order, $field);
        $total   = count($all);
        $rows    = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderInviteeRows($rows, [], $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
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

    /**
     * Renders the Invitees admin page, routing to the list or add/edit form.
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

    /** Handles creating or updating an invitee profile from the admin form. */
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
            'image_attachment_id' => $this->sanitizeInviteeImageAttachmentId((int) ($_POST['image_attachment_id'] ?? 0)),
        ];

        if (empty($data['first_name']) || empty($data['last_name'])) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'required_fields',
            ]));
            exit;
        }

        if ($data['email'] !== '' && !is_email($data['email'])) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'invalid_email',
            ]));
            exit;
        }

        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        if ($id > 0) {
            Invitee::update($id, $data);
            Category::syncToEntity('invitee', $id, $categoryIds);
            $message = 'invitee_updated';
        } else {
            $inviteeId = Invitee::create($data);
            if (is_int($inviteeId) && $inviteeId > 0) {
                Category::syncToEntity('invitee', $inviteeId, $categoryIds);
            }
            $message = 'invitee_created';
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, [
            'eim_message' => $message,
        ]));
        exit;
    }

    /** Handles deleting an invitee profile via a GET nonce link. */
    private function handleDeleteInvitee(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_invitee_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::syncToEntity('invitee', $id, []);
        Invitee::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, [
            'eim_message' => 'invitee_deleted',
        ]));
        exit;
    }

    private function handleBulkDeleteInvitees(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_invitees')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('invitee', $id, []);
            Invitee::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    /**
     * Formats a MySQL datetime in the site's admin date/time format.
     *
     * @param string|null $datetime
     * @return string
     */
    private function formatAdminDateTime(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }

        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return '';
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Formats a stored RSVP status for admin display.
     *
     * @param string $status
     * @return string
     */
    private function rsvpStatusLabel(string $status): string
    {
        return match ($status) {
            InvitationGroup::RSVP_ATTENDING => 'Attending',
            InvitationGroup::RSVP_DECLINED  => 'Declined',
            default                         => 'Pending',
        };
    }

    /**
     * Returns a display label for an event-specific food or beverage response.
     *
     * @param array<string,mixed> $event
     * @param string              $type food|beverage
     * @return string
     */
    private function eventMenuSelectionLabel(array $event, string $type): string
    {
        $id = (int) ($event[$type . '_option_id'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        $label = trim((string) ($event[$type . '_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $item = MenuItem::find($id);

        if ($item === null) {
            return 'Unavailable option';
        }

        return $item->label . ' (not assigned to this event)';
    }

    /**
     * Encodes a Details modal payload for use in a data attribute.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    private function rsvpDetailsAttribute(array $payload): string
    {
        return esc_attr((string) wp_json_encode($payload));
    }

    /**
     * Builds the Details modal payload for an invitee's response to one event.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function eventRsvpDetailsPayload(array $event): array
    {
        return [
            'title'    => (string) ($event['name'] ?? 'Event Details'),
            'sections' => [
                [
                    'heading' => 'RSVP Response',
                    'rows'    => [
                        ['label' => 'Status',         'value' => $this->rsvpStatusLabel((string) ($event['rsvp_status'] ?? InvitationGroup::RSVP_PENDING))],
                        ['label' => 'Registered',     'value' => $this->formatAdminDateTime($event['registered_at'] ?? null)],
                        ['label' => 'Food',           'value' => $this->eventMenuSelectionLabel($event, 'food')],
                        ['label' => 'Beverage',       'value' => $this->eventMenuSelectionLabel($event, 'beverage')],
                        ['label' => 'Dietary Notes',  'value' => (string) ($event['dietary_notes'] ?? '')],
                    ],
                ],
            ],
        ];
    }

    /** Renders the global invitees list table with search bar and sortable columns. */
    private function renderInviteesList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort'] ?? 'last_name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeInviteeFieldKey((string) ($_GET['field'] ?? ''));
        $all     = Invitee::listForAdmin($search, $sort, $order, $field);
        $total   = count($all);
        $rows    = array_slice($all, 0, 10);
        $addUrl  = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'add']);

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
                Use <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS)); ?>">Connection Groups</a>
                to define relationships like couples or families.
            </p>

            <?php $this->renderSearchBar(
                'eim-invitee-search',
                'eim-invitee-count',
                'eim-invitee-loading',
                'Search invitees, events, or connected people...',
                $total,
                $search,
                [
                    ['value' => 'first_name',        'label' => 'First Name'],
                    ['value' => 'last_name',         'label' => 'Last Name'],
                    ['value' => 'email',             'label' => 'Email'],
                    ['value' => 'phone',             'label' => 'Phone'],
                    ['value' => 'events',            'label' => 'Invited Events'],
                    ['value' => 'connection_groups', 'label' => 'Connection Groups'],
                ],
                $field
            ); ?>

            <?php $this->renderBulkActions(
                'eim-invitees-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_INVITEES),
                'bulk_delete_invitees',
                'eim_bulk_delete_invitees'
            ); ?>

            <table id="eim-invitees-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('invitees'); ?>
                        <th class="eim-invitee-image-column">Image</th>
                        <th style="width:11%;"><?= $this->sortLink('First Name', 'first_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_INVITEES]); ?></th>
                        <th style="width:11%;"><?= $this->sortLink('Last Name', 'last_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_INVITEES]); ?></th>
                        <th style="width:17%;"><?= $this->sortLink('Email', 'email', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_INVITEES]); ?></th>
                        <th style="width:10%;"><?= $this->sortLink('Phone', 'phone', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_INVITEES]); ?></th>
                        <th style="width:8%;"><?= $this->sortLink('Has Address', 'has_address', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_INVITEES]); ?></th>
                        <th style="width:13%"><?= $this->sortLink('Invited Events', 'events', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_INVITEES]); ?></th>
                        <th style="width:13%;">Connection Groups</th>
                        <th style="width:11%;">Categories</th>
                        <th style="width:9%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-invitees-table-body">
                    <?php $this->renderInviteeRows($rows, $groupsByInvitee, $search); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-invitee-search'); ?>
        </div>
        <?php
    }

    /**
     * @param array<int, array{invitee: Invitee, events: array}>  $rows
     * @param array<int, ConnectionGroup[]>                        $groupsByInvitee
     */
    private function renderInviteeRows(array $rows, array $groupsByInvitee = [], string $search = '', int $offset = 0): void
    {
        if (empty($rows)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No invitees found.';
            echo $this->renderNoResultsRow($msg);
            return;
        }

        // Load groups for AJAX path where they weren't pre-loaded.
        if (empty($groupsByInvitee)) {
            $ids = array_map(static fn($r) => $r['invitee']->id, $rows);
            $groupsByInvitee = ConnectionGroup::forInvitees($ids);
        }

        $inviteeIds  = array_map(static fn($r) => $r['invitee']->id, $rows);
        $catsByInvitee = Category::forEntities('invitee', $inviteeIds);

        foreach ($rows as $i => $row) {
            /** @var Invitee $invitee */
            $invitee     = $row['invitee'];
            $editUrl     = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $invitee->id]);
            $deleteUrl   = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'delete_invitee', 'id' => $invitee->id]),
                'eim_delete_invitee_' . $invitee->id
            );
            $connGroups  = $groupsByInvitee[$invitee->id] ?? [];
            $cats        = $catsByInvitee[$invitee->id]   ?? [];
            ?>
            <tr>
                <?= $this->renderLeadingCells('eim-invitees-bulk-form', 'invitees', $invitee->id, $invitee->fullName(), $offset + $i + 1); ?>
                <td><?= $this->inviteeImageThumbnailMarkup($invitee->imageAttachmentId, $invitee->fullName()); ?></td>
                <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->firstName); ?></a></td>
                <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->lastName); ?></a></td>
                <td><a href="mailto:<?= esc_attr($invitee->email); ?>"><?= esc_html($invitee->email); ?></a></td>
                <td><?= esc_html($invitee->phone ?: '—'); ?></td>
                <td><?= $invitee->hasAddress() ? 'True' : 'False'; ?></td>
                <td>
                    <?php if (empty($row['events'])): ?>
                        <span style="color:#999;">Not invited yet</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($row['events'] as $event): ?>
                                <a class="eim-event-tag"
                                   href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event['id']]) . '#eim-event-invitees'); ?>">
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
                                   href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $cg->id])); ?>">
                                    <?= esc_html($cg->name); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php foreach ($cats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($cats)): ?><span style="color:#999;">—</span><?php endif; ?>
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

    /**
     * Renders the add/edit form for an invitee profile.
     *
     * @param Invitee|null $invitee Existing invitee to edit, or null when adding.
     */
    private function renderInviteeForm(?Invitee $invitee): void
    {
        if (isset($_GET['id']) && $invitee === null) {
            $this->renderError('Invitee not found.', AdminMenu::tabUrl(AdminMenu::TAB_INVITEES));
            return;
        }

        $isNew        = $invitee === null;
        $message      = (string) ($_GET['eim_message'] ?? '');
        $error        = (string) ($_GET['eim_error']   ?? '');
        $backUrl      = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES);
        $title        = $isNew ? 'Add Invitee' : 'Edit Invitee';
        $events       = $isNew ? [] : Invitee::eventsForInvitee($invitee->id);
        $connGroups   = $isNew ? [] : ConnectionGroup::forInvitee($invitee->id);
        $cgAddUrl     = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'add']);
        $imageAttachmentId = $isNew ? 0 : $invitee->imageAttachmentId;
        $imageThumbUrl = $imageAttachmentId > 0 ? wp_get_attachment_image_url($imageAttachmentId, 'thumbnail') : '';
        $imageFullUrl  = $imageAttachmentId > 0 ? wp_get_attachment_image_url($imageAttachmentId, 'full') : '';
        $hasImage      = is_string($imageThumbUrl) && $imageThumbUrl !== '' && is_string($imageFullUrl) && $imageFullUrl !== '';
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Invitees</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES)); ?>">
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
                        <th scope="row"><label for="eim_email">Email Address</label></th>
                        <td><input type="email" id="eim_email" name="email" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->email); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eim_phone">Phone</label></th>
                        <td><input type="tel" id="eim_phone" name="phone" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->phone); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Image</th>
                        <td>
                            <input type="hidden"
                                   id="eim_invitee_image_attachment_id"
                                   name="image_attachment_id"
                                   value="<?= esc_attr($imageAttachmentId); ?>">
                            <div class="eim-invitee-image-picker">
                                <div id="eim_invitee_image_preview" class="eim-invitee-image-preview">
                                    <?php if ($hasImage): ?>
                                        <?= $this->inviteeImageThumbnailMarkup($imageAttachmentId, $isNew ? 'Invitee image' : $invitee->fullName()); ?>
                                    <?php else: ?>
                                        <span class="description">No image selected.</span>
                                    <?php endif; ?>
                                </div>
                                <p class="eim-invitee-image-actions">
                                    <button type="button"
                                            id="eim_invitee_image_select"
                                            class="button"
                                            data-select-label="Select Image"
                                            data-change-label="Change Image">
                                        <?= $hasImage ? 'Change Image' : 'Select Image'; ?>
                                    </button>
                                    <button type="button"
                                            id="eim_invitee_image_remove"
                                            class="button"
                                            <?= $hasImage ? '' : 'hidden'; ?>>
                                        Remove Image
                                    </button>
                                </p>
                            </div>
                            <p class="description" style="margin-top:6px;">Optional. Choose an image from the WordPress Media Library.</p>
                        </td>
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
                                               href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $cg->id])); ?>">
                                                <?= esc_html($cg->name); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <p class="description" style="margin-top:6px;">
                                    Manage connection groups from the
                                    <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS)); ?>">Connection Groups page</a>.
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
                                            <?php
                                            $eventUrl       = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event['id']]) . '#eim-event-invitees';
                                            $detailsPayload = $this->eventRsvpDetailsPayload($event);
                                            ?>
                                            <span class="eim-event-detail-dropdown">
                                                <button type="button"
                                                        class="eim-event-tag eim-event-detail-trigger"
                                                        aria-haspopup="true"
                                                        aria-expanded="false">
                                                    <?= esc_html($event['name']); ?>
                                                </button>
                                                <div class="eim-member-dropdown-menu eim-event-detail-menu" role="menu" hidden>
                                                    <a href="<?= esc_url($eventUrl); ?>" role="menuitem">Open Event</a>
                                                    <button type="button"
                                                            class="eim-rsvp-details-trigger"
                                                            role="menuitem"
                                                            data-eim-rsvp-details="<?= $this->rsvpDetailsAttribute($detailsPayload); ?>">Details</button>
                                                </div>
                                            </span>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <p class="description">Add or remove from events on each event edit screen.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php
                            $selCats  = [];
                            $catNonce = wp_create_nonce('eim_suggest_categories_nonce');
                            if (!$isNew) {
                                foreach (Category::forEntity('invitee', $invitee->id) as $cat) {
                                    $selCats[] = [
                                        'id'          => $cat->id,
                                        'name'        => $cat->name,
                                        'parent_name' => $cat->parentName,
                                        'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                    ];
                                }
                            }
                            $this->renderCategoryPicker('eim-invitee-cat-picker', $selCats, $catNonce);
                            ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Add Invitee' : 'Update Invitee'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Sanitizes an invitee list sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'last_name'.
     */
    private function sanitizeSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['first_name', 'last_name', 'email', 'phone', 'has_address', 'events'], true)
            ? $key
            : 'last_name';
    }

    /**
     * Sanitizes an invitee search field key against the allowed column list.
     *
     * @param string $field Raw field key.
     * @return string Validated key, or '' for any-column search.
     */
    private function sanitizeInviteeFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['first_name', 'last_name', 'email', 'phone', 'events', 'connection_groups'], true)
            ? $field
            : '';
    }

    private function sanitizeInviteeImageAttachmentId(int $attachmentId): int
    {
        if ($attachmentId <= 0 || !wp_attachment_is_image($attachmentId)) {
            return 0;
        }

        return $attachmentId;
    }
}
