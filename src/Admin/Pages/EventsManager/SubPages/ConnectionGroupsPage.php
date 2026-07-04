<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Category;
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
    /**
     * Dispatches connection-group form submissions and GET actions.
     *
     * @param string $action The action slug.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_connection_group'            => $this->handleSaveGroup(),
            'delete_connection_group'          => $this->handleDeleteGroup(),
            'bulk_delete_connection_groups'    => $this->handleBulkDeleteGroups(),
            'add_member_to_connection_group'   => $this->handleAddMember(),
            'remove_member_from_connection_group' => $this->handleRemoveMember(),
            'bulk_remove_connection_group_members' => $this->handleBulkRemoveMembers(),
            default                            => null,
        };
    }

    /**
     * Renders the Connection Groups page, routing to the list or add/edit form.
     *
     * @return void
     */
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
     * Expected GET params: nonce, query, sort, order, field.
     * Returns JSON: { success: true, data: { html, count } }
     */
    public function handleAjaxSearchGroups(): void
    {
        check_ajax_referer('eim_search_connection_groups_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeGroupFieldKey((string) ($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = $this->perPageParam();
        $all     = ConnectionGroup::listForAdmin($query, $sort, $order, $field);

        $allIds        = array_map(static fn(ConnectionGroup $g) => $g->id, $all);
        $eventsByGroup = ConnectionGroup::eventsForGroups($allIds);

        if ($sort === 'invited_to') {
            $all = $this->phpSortByEvents($all, $eventsByGroup, $order);
        }

        $total  = count($all);
        $groups = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderGroupRows($groups, $query, $eventsByGroup, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => $total,
            'total' => $total,
        ]);
    }

    /**
     * Sanitizes a connection group sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'name'.
     */
    private function sanitizeGroupSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['name', 'members', 'invited_to'], true) ? $key : 'name';
    }

    /**
     * Sanitizes a connection group search field key against the allowed column list.
     *
     * @param string $field Raw field key.
     * @return string Validated key, or '' for any-column search.
     */
    private function sanitizeGroupFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['name', 'members', 'invited_to'], true) ? $field : '';
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

    /**
     * AJAX: persists a new drag-sorted member order for a connection group.
     *
     * Expected POST params: nonce, connection_group_id, ids[] (ordered invitee IDs).
     */
    public function handleAjaxSaveMemberOrder(): void
    {
        check_ajax_referer('eim_sort_cg_members_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $groupId = (int) ($_POST['connection_group_id'] ?? 0);
        $ids     = wp_unslash($_POST['ids'] ?? []);

        if ($groupId <= 0 || !is_array($ids) || ConnectionGroup::find($groupId) === null) {
            wp_send_json_error('Invalid request.', 400);
        }

        if (!ConnectionGroup::updateMemberOrder($groupId, array_map('intval', $ids))) {
            wp_send_json_error('Unable to save member order.', 500);
        }

        wp_send_json_success(['message' => 'Member order saved.']);
    }

    // -------------------------------------------------------------------------
    // Form handlers
    // -------------------------------------------------------------------------

    /** Handles creating or updating a connection group from the admin form. */
    private function handleSaveGroup(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_connection_group')) {
            wp_die('Security check failed.');
        }

        $id          = (int) ($_POST['connection_group_id'] ?? 0);
        $name        = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        if (empty($name)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'cg_name_required',
            ]));
            exit;
        }

        if ($id > 0) {
            ConnectionGroup::update($id, $name);
            Category::syncToEntity('connection_group', $id, $categoryIds);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, [
                'action'      => 'edit',
                'id'          => $id,
                'eim_message' => 'cg_updated',
            ]));
        } else {
            $group = ConnectionGroup::create($name);
            if ($group) {
                Category::syncToEntity('connection_group', $group->id, $categoryIds);
            }
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, [
                'action'      => 'edit',
                'id'          => $group?->id ?? 0,
                'eim_message' => 'cg_created',
            ]));
        }
        exit;
    }

    /** Handles deleting a connection group via a GET nonce link. */
    private function handleDeleteGroup(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_connection_group_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::syncToEntity('connection_group', $id, []);
        ConnectionGroup::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, [
            'eim_message' => 'cg_deleted',
        ]));
        exit;
    }

    private function handleBulkDeleteGroups(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_connection_groups')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('connection_group', $id, []);
            ConnectionGroup::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    /** Handles adding a member to an existing connection group. */
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

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, [
            'action'      => 'edit',
            'id'          => $groupId,
            'eim_message' => 'cg_member_added',
        ]) . '#eim-cg-members');
        exit;
    }

    /** Handles removing a member from a connection group via a GET nonce link. */
    private function handleRemoveMember(): void
    {
        $groupId   = (int) ($_GET['group_id']   ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_cg_member_' . $groupId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        ConnectionGroup::removeMember($groupId, $inviteeId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, [
            'action'      => 'edit',
            'id'          => $groupId,
            'eim_message' => 'cg_member_removed',
        ]) . '#eim-cg-members');
        exit;
    }

    private function handleBulkRemoveMembers(): void
    {
        $groupId = (int) ($_POST['connection_group_id'] ?? 0);

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_remove_cg_members_' . $groupId)) {
            wp_die('Security check failed.');
        }

        $redirectUrl = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $groupId]);

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect($redirectUrl . '&eim_error=bulk_invalid_action#eim-cg-members');
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect($redirectUrl . '&eim_error=bulk_no_selection#eim-cg-members');
            exit;
        }

        foreach ($ids as $inviteeId) {
            ConnectionGroup::removeMember($groupId, $inviteeId);
        }

        wp_redirect($redirectUrl . '&eim_message=bulk_deleted#eim-cg-members');
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** Renders the connection groups list table with search bar and sortable columns. */
    private function renderGroupList(): void
    {
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error']   ?? '');
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort    = $this->sanitizeGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeGroupFieldKey((string) ($_GET['field'] ?? ''));
        $all     = ConnectionGroup::listForAdmin($search, $sort, $order, $field);
        $addUrl  = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'add']);

        $allIds        = array_map(static fn(ConnectionGroup $g) => $g->id, $all);
        $eventsByGroup = ConnectionGroup::eventsForGroups($allIds);

        if ($sort === 'invited_to') {
            $all = $this->phpSortByEvents($all, $eventsByGroup, $order);
        }

        $total  = count($all);
        $groups = array_slice($all, 0, 10);
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

            <?php $this->renderSearchBar(
                'eim-connection-group-search',
                'eim-connection-group-count',
                'eim-connection-group-loading',
                'Search groups, members, or events...',
                $total,
                $search,
                [
                    ['value' => 'name',       'label' => 'Name'],
                    ['value' => 'members',    'label' => 'Members'],
                    ['value' => 'invited_to', 'label' => 'Invited To'],
                ],
                $field
            ); ?>

            <?php $this->renderBulkActions(
                'eim-connection-groups-bulk-form',
                AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS),
                'bulk_delete_connection_groups',
                'eim_bulk_delete_connection_groups'
            ); ?>

            <table id="eim-connection-groups-table"
                   class="wp-list-table widefat fixed striped"
                   style="margin-top:12px;"
                   data-sort="<?= esc_attr($sort); ?>"
                   data-order="<?= esc_attr($order); ?>"
                   data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                        <?= $this->renderLeadingHeaderCells('connection-groups'); ?>
                        <th style="width:22%;"><?= $this->sortLink('Name',       'name',       AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_CONNECTION_GROUPS]); ?></th>
                        <th style="width:30%;"><?= $this->sortLink('Members',    'members',     AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_CONNECTION_GROUPS]); ?></th>
                        <th><?= $this->sortLink('Invited To', 'invited_to', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_CONNECTION_GROUPS]); ?></th>
                        <th style="width:14%;">Categories</th>
                        <th style="width:12%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="eim-connection-groups-table-body">
                    <?php $this->renderGroupRows($groups, $search, $eventsByGroup); ?>
                </tbody>
            </table>
            <?php $this->renderPaginationBar('eim-connection-group-search'); ?>
        </div>
        <?php
    }

    /**
     * Renders connection group table rows for the initial page and AJAX responses.
     *
     * @param ConnectionGroup[]                                   $groups
     * @param string                                              $search
     * @param array<int, array<int, array{id:int,name:string}>>  $eventsByGroup
     * @return void
     */
    private function renderGroupRows(array $groups, string $search = '', array $eventsByGroup = [], int $offset = 0): void
    {
        if (empty($groups)) {
            $addUrl = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'add']);
            ?>
            <tr>
                <td colspan="100">
                    <?= $search
                        ? 'No results found based upon search criteria.'
                        : 'No connection groups yet. <a href="' . esc_url($addUrl) . '">Add the first one.</a>'; ?>
                </td>
            </tr>
            <?php
            return;
        }

        $groupIds   = array_map(static fn(ConnectionGroup $g): int => $g->id, $groups);
        $catsByGroup = Category::forEntities('connection_group', $groupIds);

        foreach ($groups as $i => $group) {
            $editUrl   = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $group->id]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'delete_connection_group', 'id' => $group->id]),
                'eim_delete_connection_group_' . $group->id
            );
            $groupEvents = $eventsByGroup[$group->id] ?? [];
            $cats        = $catsByGroup[$group->id]   ?? [];
            ?>
            <tr>
                <?= $this->renderLeadingCells('eim-connection-groups-bulk-form', 'connection-groups', $group->id, $group->name, $offset + $i + 1); ?>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($group->name); ?></a></strong>
                </td>
                <td>
                    <?php $members = $group->getMembers(); ?>
                    <?php if (empty($members)): ?>
                        <span style="color:#999;">No members</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($members as $member): ?>
                                <?php $memberEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $member->id]); ?>
                                <a class="eim-connection-tag" href="<?= esc_url($memberEditUrl); ?>">
                                    <?= esc_html($member->fullName()); ?>
                                </a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($groupEvents)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($groupEvents as $ev): ?>
                                <?php $evUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $ev['id']]); ?>
                                <a class="eim-event-tag" href="<?= esc_url($evUrl); ?>"><?= esc_html($ev['name']); ?></a>
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
                       onclick="return confirm('Delete the group &quot;<?= esc_js($group->name); ?>&quot;? Members will not be deleted.');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Sorts a connection group array by event count (then name) in PHP.
     *
     * @param ConnectionGroup[]                                  $groups
     * @param array<int, array<int, array{id:int,name:string}>> $eventsByGroup
     * @param string                                             $order
     * @return ConnectionGroup[]
     */
    private function phpSortByEvents(array $groups, array $eventsByGroup, string $order): array
    {
        $mul = $order === 'desc' ? -1 : 1;
        usort($groups, static function (ConnectionGroup $a, ConnectionGroup $b) use ($eventsByGroup, $mul): int {
            $aCount = count($eventsByGroup[$a->id] ?? []);
            $bCount = count($eventsByGroup[$b->id] ?? []);
            $cmp    = $aCount <=> $bCount;
            return $cmp !== 0 ? $mul * $cmp : strcasecmp($a->name, $b->name);
        });

        return $groups;
    }

    /**
     * Renders the add/edit form for a connection group, including the member management section.
     *
     * @param ConnectionGroup|null $group Existing group to edit, or null when adding.
     */
    private function renderGroupForm(?ConnectionGroup $group): void
    {
        if (isset($_GET['id']) && $group === null) {
            $this->renderError('Connection group not found.', AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS));
            return;
        }

        $isNew      = $group === null;
        $message    = (string) ($_GET['eim_message'] ?? '');
        $error      = (string) ($_GET['eim_error']   ?? '');
        $backUrl    = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS);
        $title      = $isNew ? 'Add Connection Group' : 'Edit Connection Group: ' . $group->name;
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
                      action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS)); ?>">
                </form>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS)); ?>">
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
                        <th scope="row"><label>Categories</label></th>
                        <td>
                            <?php
                            $selCats   = [];
                            $catNonce  = wp_create_nonce('eim_suggest_categories_nonce');
                            if (!$isNew) {
                                foreach (Category::forEntity('connection_group', $group->id) as $cat) {
                                    $selCats[] = [
                                        'id'          => $cat->id,
                                        'name'        => $cat->name,
                                        'parent_name' => $cat->parentName,
                                        'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                    ];
                                }
                            }
                            $this->renderCategoryPicker('eim-cg-cat-picker', $selCats, $catNonce);
                            ?>
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
                    <?php $memberCount = count($members); ?>
                    <?php $canReorder  = $memberCount > 1; ?>

                    <?php if ($memberCount >= 2): ?>
                    <div style="margin-bottom:6px;display:flex;align-items:center;gap:12px;">
                        <label class="screen-reader-text" for="eim-cg-members-search">Filter members</label>
                        <input type="search"
                               id="eim-cg-members-search"
                               class="regular-text"
                               placeholder="Filter members…"
                               autocomplete="off"
                               style="max-width:280px;">
                        <span id="eim-cg-members-count"
                              class="description"><?= esc_html($memberCount); ?> member<?= $memberCount === 1 ? '' : 's'; ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($canReorder): ?>
                        <p class="description eim-sortable-hint">Drag rows by the handle to set the order names appear in invite emails. Order numbers update automatically. Reordering is disabled while filtering.</p>
                        <p class="description eim-sort-status" aria-live="polite"></p>
                    <?php endif; ?>

                    <?php $this->renderBulkActions(
                        'eim-cg-members-bulk-form',
                        AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $group->id]),
                        'bulk_remove_connection_group_members',
                        'eim_bulk_remove_cg_members_' . $group->id,
                        ['connection_group_id' => $group->id]
                    ); ?>

                    <table id="eim-cg-members-table"
                           class="wp-list-table widefat fixed striped<?= $canReorder ? ' eim-cg-sortable-members' : ''; ?>"
                           style="margin-bottom:16px;"
                           data-connection-group-id="<?= esc_attr($group->id); ?>"
                           data-sort="name"
                           data-order="asc">
                        <thead>
                            <tr>
                                <?php if ($canReorder): ?>
                                    <th class="eim-drag-column"><span class="screen-reader-text">Move</span></th>
                                <?php endif; ?>
                                <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('cg-members-' . $group->id); ?></th>
                                <th style="width:8%;">Order</th>
                                <th>
                                    <a href="#" class="eim-sort-link eim-cg-member-sort"
                                       data-sort="name" data-order="desc">
                                        Name <span aria-hidden="true">^</span>
                                    </a>
                                </th>
                                <th style="width:30%;">
                                    <a href="#" class="eim-sort-link eim-cg-member-sort"
                                       data-sort="email" data-order="asc">
                                        Email <span aria-hidden="true"></span>
                                    </a>
                                </th>
                                <th style="width:18%;">Role</th>
                                <th style="width:10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="eim-cg-members-tbody">
                            <?php foreach ($members as $position => $member): ?>
                                <?php
                                $removeUrl = wp_nonce_url(
                                    AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'remove_member_from_connection_group', 'group_id' => $group->id, 'invitee_id' => $member->id]),
                                    'eim_remove_cg_member_' . $group->id . '_' . $member->id
                                );
                                $editUrl = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $member->id]);
                                $displayOrder = $position + 1;
                                ?>
                                <tr class="<?= $canReorder ? 'eim-sortable-row' : ''; ?>"
                                    data-id="<?= esc_attr($member->id); ?>"
                                    data-order="<?= esc_attr($displayOrder); ?>"
                                    data-name="<?= esc_attr(strtolower($member->fullName())); ?>"
                                    data-email="<?= esc_attr(strtolower($member->email)); ?>">
                                    <?php if ($canReorder): ?>
                                        <td class="eim-drag-column">
                                            <button type="button" class="button-link eim-drag-handle" aria-label="Drag to reorder <?= esc_attr($member->fullName()); ?>">
                                                <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                    <?= $this->renderBulkSelectCell('eim-cg-members-bulk-form', 'cg-members-' . $group->id, $member->id, $member->fullName()); ?>
                                    <td class="eim-order-cell"><?= esc_html($displayOrder); ?></td>
                                    <td><a href="<?= esc_url($editUrl); ?>"><?= esc_html($member->fullName()); ?></a></td>
                                    <td><?= esc_html($member->email); ?></td>
                                    <td><?php if ($member->role !== ''): ?><?= esc_html($member->role); ?><?php else: ?><span style="color:#646970;">—</span><?php endif; ?></td>
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
