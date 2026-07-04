<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\EventMessage;

/**
 * Global Messages admin sub-page.
 *
 * Shows all messages submitted by invitees across every event, with live search,
 * column sort, pagination, and inline mark-read / delete actions.
 */
final class MessagesPage extends AbstractAdminPage
{
    /**
     * Dispatches non-AJAX form actions for this tab.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'bulk_delete_messages' => $this->handleBulkDelete(),
            default                => null,
        };
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    /**
     * Handles wp_ajax_eim_search_messages.
     *
     * Returns paginated, filtered, sorted table rows for the live-search UI.
     */
    public function handleAjaxSearch(): void
    {
        check_ajax_referer('eim_search_messages_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort']     ?? 'created_at'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order']  ?? 'desc'));
        $field   = $this->sanitizeFieldKey((string) ($_GET['field']   ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = $this->perPageParam();

        $all   = EventMessage::listForAdmin($query, $sort, $order, $field);
        $total = count($all);
        $paged = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderRows($paged, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    // ── Render ───────────────────────────────────────────────────────────────

    public function renderPage(): void
    {
        $message = sanitize_key($_GET['eim_message'] ?? '');
        $error   = sanitize_key($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['search'] ?? ''));
        $sort    = $this->sanitizeSortKey((string) ($_GET['sort']  ?? 'created_at'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'desc'));
        $field   = $this->sanitizeFieldKey((string) ($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = 10;

        $all   = EventMessage::listForAdmin($search, $sort, $order, $field);
        $total = count($all);
        $paged = array_slice($all, ($page - 1) * $perPage, $perPage);
        ?>
        <div class="wrap">
            <?php $this->renderNotice($message, $error); ?>
            <h2>Messages</h2>
            <?php
            $this->renderSearchBar(
                'eim-global-messages-search',
                'eim-global-messages-count',
                'eim-global-messages-loading',
                'Search messages…',
                $total,
                $search,
                [
                    ['value' => 'event',            'label' => 'Event'],
                    ['value' => 'connection_group',  'label' => 'Connection Group'],
                    ['value' => 'message',           'label' => 'Message'],
                ],
                $field
            );

            $bulkFormId = 'eim-messages-bulk-form';
            $bulkUrl    = AdminMenu::tabUrl(AdminMenu::TAB_MESSAGES);

            $this->renderBulkActions($bulkFormId, $bulkUrl, 'bulk_delete_messages', 'eim_bulk_delete_messages');
            ?>
            <table id="eim-global-messages-table"
                   class="wp-list-table widefat fixed striped"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('messages'); ?>
                        <th style="width:11%;"><?= $this->sortLink('Date',            'created_at',            AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_MESSAGES]); ?></th>
                        <th style="width:15%;"><?= $this->sortLink('Event',            'event_name',            AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_MESSAGES]); ?></th>
                        <th style="width:35%;"><?= $this->sortLink('Message',         'message',               AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_MESSAGES]); ?></th>
                        <th style="width:9%;"><?= $this->sortLink('Status',           'is_read',               AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_MESSAGES]); ?></th>
                        <th style="width:16%;"><?= $this->sortLink('Connection Group', 'connection_group_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_MESSAGES]); ?></th>
                        <th style="width:11%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-global-messages-table-body">
                    <?php $this->renderRows($paged); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-global-messages-search'); ?>
        </div>
        <?php
    }

    private function renderRows(array $messages, int $offset = 0): void
    {
        if (empty($messages)) {
            ?>
            <tr>
                <td colspan="100" style="text-align:center;color:#999;padding:20px;">
                    No messages found.
                </td>
            </tr>
            <?php
            return;
        }

        foreach ($messages as $i => $msg) {
            $eventUrl  = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $msg->eventId]);
            $cgUrl     = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $msg->connectionGroupId]);
            $truncated = mb_strlen($msg->message) > 140 ? mb_substr($msg->message, 0, 140) . '…' : $msg->message;

            if ($msg->isRead) {
                $badgeStyle = 'background:#f0f0f1;color:#646970;';
                $badgeLabel = 'Read';
            } else {
                $badgeStyle = 'background:#fff3cd;color:#856404;';
                $badgeLabel = 'Unread';
            }
            ?>
            <tr data-message-id="<?= esc_attr($msg->id); ?>"
                data-is-read="<?= $msg->isRead ? '1' : '0'; ?>">
                <?= $this->renderLeadingCells('eim-messages-bulk-form', 'messages', $msg->id, $msg->message, $offset + $i + 1); ?>
                <td><?= esc_html($this->formatDate($msg->createdAt)); ?></td>
                <td>
                    <?php if ($msg->eventName !== null): ?>
                        <a href="<?= esc_url($eventUrl); ?>" class="eim-event-chip">
                            <?= esc_html($msg->eventName); ?>
                        </a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td title="<?= esc_attr($msg->message); ?>">
                    <?= esc_html($truncated); ?>
                </td>
                <td>
                    <span class="eim-msg-status-badge"
                          style="<?= esc_attr($badgeStyle); ?>padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;white-space:nowrap;">
                        <?= esc_html($badgeLabel); ?>
                    </span>
                </td>
                <td>
                    <a href="<?= esc_url($cgUrl); ?>">
                        <?= esc_html($msg->connectionGroupName ?? '—'); ?>
                    </a>
                </td>
                <td style="white-space:nowrap;">
                    <button type="button"
                            class="button button-small eim-msg-thread"
                            data-event-id="<?= esc_attr($msg->eventId); ?>"
                            data-group-id="<?= esc_attr($msg->connectionGroupId); ?>"
                            data-group-name="<?= esc_attr($msg->connectionGroupName ?? ''); ?>">
                        Thread
                    </button>
                    <button type="button"
                            class="button button-small eim-msg-toggle-read"
                            data-message-id="<?= esc_attr($msg->id); ?>"
                            data-is-read="<?= $msg->isRead ? '1' : '0'; ?>">
                        <?= $msg->isRead ? 'Mark Unread' : 'Mark Read'; ?>
                    </button>
                    <button type="button"
                            class="button button-small eim-msg-delete"
                            style="color:#d63638;"
                            data-message-id="<?= esc_attr($msg->id); ?>">
                        Delete
                    </button>
                </td>
            </tr>
            <?php
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function handleBulkDelete(): void
    {
        if (!current_user_can('manage_options')) return;
        check_admin_referer('eim_bulk_delete_messages');

        $action = $this->requestedBulkAction();

        if ($action !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MESSAGES, [
                'eim_error' => $action === '' ? 'bulk_no_selection' : 'bulk_invalid_action',
            ]));
            exit;
        }

        $ids = $this->bulkActionIds();

        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MESSAGES, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            EventMessage::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_MESSAGES, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    private function sanitizeSortKey(string $key): string
    {
        return in_array($key, ['event_name', 'connection_group_name', 'message', 'is_read', 'created_at'], true)
            ? $key : 'created_at';
    }

    private function sanitizeFieldKey(string $key): string
    {
        return in_array($key, ['event', 'connection_group', 'message'], true) ? $key : '';
    }

    private function formatDate(string $datetime): string
    {
        if ($datetime === '') return '—';
        try {
            $dt = new \DateTime($datetime, new \DateTimeZone('UTC'));
            return $dt->format('M j, Y g:i a');
        } catch (\Exception) {
            return $datetime;
        }
    }
}
