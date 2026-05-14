<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\Invitee;

/**
 * Admin CRUD page for global invitee connection groups.
 *
 * Connection groups (couples, families, households) are reusable suggestions.
 * They are shown as checkboxes when an admin adds a primary invitee to an event,
 * so connected people can be included in the same invitation group.
 */
final class ConnectionGroupsPage extends AbstractAdminPage
{
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_connection_group'            => $this->handleSaveGroup(),
            'delete_connection_group'          => $this->handleDeleteGroup(),
            'add_member_to_connection_group'   => $this->handleAddMember(),
            'remove_member_from_connection_group' => $this->handleRemoveMember(),
            default                            => null,
        };
    }

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderGroupForm(null),
            'edit'  => $this->renderGroupForm(ConnectionGroup::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderGroupList(),
        };
    }

    /**
     * AJAX: searches the connection groups list table.
     *
     * Expected GET params: nonce, query.
     * Returns JSON: { success: true, data: { html, count } }
     */
    public function handleAjaxSearchGroups(): void
    {
        check_ajax_referer('eim_search_connection_groups_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query  = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort   = $this->sanitizeGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order  = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $groups = ConnectionGroup::listForAdmin($query, $sort, $order);

        ob_start();
        $this->renderGroupRows($groups, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => count($groups),
        ]);
    }

    private function sanitizeGroupSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['name', 'type', 'members'], true) ? $key : 'name';
    }

    /**
     * AJAX: searches invitees not already in a connection group (for the member picker).
     *
     * Expected GET params: nonce, query, group_id, exclude_ids (CSV).
     * Returns JSON: { success: true, data: [ { id, name, email, label }, ... ] }
     */
    public function handleAjaxSuggestMembers(): void
    {
        check_ajax_referer('eim_suggest_invitees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query      = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $groupId    = (int) ($_GET['group_id'] ?? 0);
        $excludeRaw = sanitize_text_field(wp_unslash($_GET['exclude_ids'] ?? ''));
        $excludeIds = array_filter(array_map('intval', explode(',', $excludeRaw)));

        $results = ConnectionGroup::searchAvailableMembers($query, $groupId, $excludeIds);

        wp_send_json_success(array_map(static fn(Invitee $inv): array => [
            'id'    => $inv->id,
            'name'  => $inv->fullName(),
            'email' => $inv->email,
            'label' => trim($inv->fullName() . ' - ' . $inv->email),
        ], $results));
    }

    // -------------------------------------------------------------------------
    // Form handlers
    // -------------------------------------------------------------------------

    private function handleSaveGroup(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_connection_group')) {
            wp_die('Security check failed.');
        }

        $id   = (int) ($_POST['connection_group_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $type = sanitize_key($_POST['type'] ?? 'custom');

        if (empty($name)) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_CONNECTION_GROUPS,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'cg_name_required',
            ], admin_url('admin.php')));
            exit;
        }

        if ($id > 0) {
            ConnectionGroup::update($id, $name, $type);
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_CONNECTION_GROUPS,
                'action'      => 'edit',
                'id'          => $id,
                'eim_message' => 'cg_updated',
            ], admin_url('admin.php')));
        } else {
            $group = ConnectionGroup::create($name, $type);
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_CONNECTION_GROUPS,
                'action'      => 'edit',
                'id'          => $group?->id ?? 0,
                'eim_message' => 'cg_created',
            ], admin_url('admin.php')));
        }
        exit;
    }

    private function handleDeleteGroup(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_connection_group_' . $id)) {
            wp_die('Security check failed.');
        }

        ConnectionGroup::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_CONNECTION_GROUPS,
            'eim_message' => 'cg_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    private function handleAddMember(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_cg_member')) {
            wp_die('Security check failed.');
        }

        $groupId   = (int) ($_POST['connection_group_id'] ?? 0);
        $inviteeId = (int) ($_POST['member_invitee_id']   ?? 0);
        $role      = sanitize_text_field(wp_unslash($_POST['member_role'] ?? ''));

        if ($groupId > 0 && $inviteeId > 0) {
            ConnectionGroup::addMember($groupId, $inviteeId, $role);
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_CONNECTION_GROUPS,
            'action'      => 'edit',
            'id'          => $groupId,
            'eim_message' => 'cg_member_added',
        ], admin_url('admin.php')) . '#eim-cg-members');
        exit;
    }

    private function handleRemoveMember(): void
    {
        $groupId   = (int) ($_GET['group_id']   ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_cg_member_' . $groupId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        ConnectionGroup::removeMember($groupId, $inviteeId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_CONNECTION_GROUPS,
            'action'      => 'edit',
            'id'          => $groupId,
            'eim_message' => 'cg_member_removed',
        ], admin_url('admin.php')) . '#eim-cg-members');
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private function renderGroupList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort    = $this->sanitizeGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $groups  = ConnectionGroup::listForAdmin($search, $sort, $order);
        $addUrl  = admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=add');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Connection Groups</h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Group</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <p class="description" style="margin-bottom:16px;">
                Connection groups define relationships between invitees (couples, families, households).
                When adding an invitee to an event, members of their connection groups are suggested so
                you can include them in the same invitation.
            </p>

            <?php $this->renderSearchBar('eim-connection-group-search', 'eim-connection-group-count', 'eim-connection-group-loading', 'Search groups or members...', count($groups), $search); ?>

            <table id="eim-connection-groups-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:12px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>">
                <thead>
                    <tr>
                        <th style="width:28%;"><?= $this->sortLink('Name',    'name',    AdminMenu::PAGE_CONNECTION_GROUPS, $sort, $order, $search); ?></th>
                        <th style="width:10%;"><?= $this->sortLink('Type',    'type',    AdminMenu::PAGE_CONNECTION_GROUPS, $sort, $order, $search); ?></th>
                        <th><?= $this->sortLink('Members', 'members', AdminMenu::PAGE_CONNECTION_GROUPS, $sort, $order, $search); ?></th>
                        <th style="width:14%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-connection-groups-table-body">
                    <?php $this->renderGroupRows($groups, $search); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Renders connection group table rows for the initial page and AJAX responses.
     *
     * @param ConnectionGroup[] $groups
     * @param string            $search
     * @return void
     */
    private function renderGroupRows(array $groups, string $search = ''): void
    {
        if (empty($groups)) {
            $addUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=add');
            ?>
            <tr>
                <td colspan="4">
                    <?= $search
                        ? 'No groups match that search.'
                        : 'No connection groups yet. <a href="' . esc_url($addUrl) . '">Add the first one.</a>'; ?>
                </td>
            </tr>
            <?php
            return;
        }

        foreach ($groups as $group) {
            $editUrl   = admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=edit&id=' . $group->id);
            $deleteUrl = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS . '&action=delete_connection_group&id=' . $group->id),
                'eim_delete_connection_group_' . $group->id
            );
            ?>
            <tr>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($group->name); ?></a></strong>
                </td>
                <td>
                    <span class="eim-cg-type-badge eim-cg-type-<?= esc_attr($group->type); ?>">
                        <?= esc_html($group->typeLabel()); ?>
                    </span>
                </td>
                <td>
                    <?php $members = $group->getMembers(); ?>
                    <?php if (empty($members)): ?>
                        <span style="color:#999;">No members</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($members as $member): ?>
                                <?php $memberEditUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=edit&id=' . $member->id); ?>
                                <a class="eim-connection-tag" href="<?= esc_url($memberEditUrl); ?>">
                                    <?= esc_html($member->fullName()); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete the group &quot;<?= esc_js($group->name); ?>&quot;? Members will not be deleted.');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    private function renderGroupForm(?ConnectionGroup $group): void
    {
        if (isset($_GET['id']) && $group === null) {
            $this->renderError('Connection group not found.', admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS));
            return;
        }

        $isNew      = $group === null;
        $message    = (string) ($_GET['eim_message'] ?? '');
        $error      = (string) ($_GET['eim_error']   ?? '');
        $backUrl    = admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS);
        $title      = $isNew ? 'Add Connection Group' : 'Edit Connection Group: ' . esc_html($group->name);
        $addMemberId = 'eim-add-member-form';
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Connection Groups</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <?php if (!$isNew): ?>
                <form id="<?= esc_attr($addMemberId); ?>"
                      method="post"
                      action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS)); ?>">
                </form>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS)); ?>">
                <?php wp_nonce_field('eim_save_connection_group'); ?>
                <input type="hidden" name="eim_action"            value="save_connection_group">
                <input type="hidden" name="connection_group_id"   value="<?= esc_attr($isNew ? 0 : $group->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="eim_cg_name">Group Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_cg_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $group->name); ?>" required>
                            <p class="description">e.g. "Chris &amp; Jamie", "The Smith Family"</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_cg_type">Type</label>
                        </th>
                        <td>
                            <select id="eim_cg_type" name="type">
                                <?php foreach (ConnectionGroup::TYPES as $typeVal): ?>
                                    <option value="<?= esc_attr($typeVal); ?>"
                                        <?php selected($isNew ? 'custom' : $group->type, $typeVal); ?>>
                                        <?= esc_html(ucfirst($typeVal)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button($isNew ? 'Create Group' : 'Update Group'); ?>
            </form>

            <?php if (!$isNew): ?>
                <hr id="eim-cg-members" style="margin:32px 0 20px;">
                <h2>Members</h2>
                <p class="description">
                    People in this connection group. When an admin adds one member to an event,
                    the other members will be suggested as optional inclusions.
                </p>

                <?php
                $members = $group->getMembers();
                if (!empty($members)):
                ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th style="width:24%;">Email</th>
                                <th style="width:18%;">Role</th>
                                <th style="width:10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <?php
                                $removeUrl = wp_nonce_url(
                                    admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS
                                        . '&action=remove_member_from_connection_group'
                                        . '&group_id=' . $group->id
                                        . '&invitee_id=' . $member->id),
                                    'eim_remove_cg_member_' . $group->id . '_' . $member->id
                                );
                                $editUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=edit&id=' . $member->id);
                                ?>
                                <tr>
                                    <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($member->fullName()); ?></a></td>
                                    <td><?= esc_html($member->email); ?></td>
                                    <td><span style="color:#646970;">—</span></td>
                                    <td>
                                        <a href="<?= esc_url($removeUrl); ?>"
                                           onclick="return confirm('Remove <?= esc_js($member->fullName()); ?> from this group?');">Remove</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="margin:0 0 12px;">No members yet.</p>
                <?php endif; ?>

                <?php /* Add member section */ ?>
                <div style="border:1px solid #dcdcde;border-radius:4px;padding:16px;background:#f6f7f7;max-width:680px;">
                    <h3 style="margin:0 0 10px;">Add Member</h3>
                    <input form="<?= esc_attr($addMemberId); ?>"
                           type="hidden" name="_wpnonce"
                           value="<?= esc_attr(wp_create_nonce('eim_add_cg_member')); ?>">
                    <input form="<?= esc_attr($addMemberId); ?>"
                           type="hidden" name="eim_action"
                           value="add_member_to_connection_group">
                    <input form="<?= esc_attr($addMemberId); ?>"
                           type="hidden" name="connection_group_id"
                           value="<?= esc_attr($group->id); ?>">
                    <input form="<?= esc_attr($addMemberId); ?>"
                           type="hidden" id="eim_cg_member_invitee_id"
                           name="member_invitee_id" value="">

                    <div class="eim-invitee-picker-wrap" style="margin-bottom:8px;">
                        <label class="screen-reader-text" for="eim_cg_member_search">Search invitees to add</label>
                        <input type="text"
                               id="eim_cg_member_search"
                               class="regular-text"
                               placeholder="Search invitees to add..."
                               autocomplete="off"
                               data-group-id="<?= esc_attr($group->id); ?>"
                               data-existing-ids="<?= esc_attr(implode(',', array_map(static fn(Invitee $m) => $m->id, $members))); ?>">
                        <button form="<?= esc_attr($addMemberId); ?>" type="submit" class="button">Add Member</button>
                    </div>
                    <p id="eim_cg_member_selected" class="description"></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
