<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventNote;

/**
 * The Notes tab on the event edit screen.
 *
 * Admins add timestamped notes about an event, tag them with categories (the
 * shared category library), and edit or delete them. One form adds notes; on
 * a master (parent) event it also asks which event the note is about: the
 * master event or one of its child events.
 *
 * Lists (each with its own search, "search in column", filters, sortable
 * columns, pagination, bulk delete and CSV/JSON export):
 *   - LIST_EVENT    — notes about this event itself (every event);
 *   - LIST_CHILDREN — notes about this event's child events (master events
 *                     only), with a "Filter by Child Event" dropdown.
 *
 * Uses the shared list helpers (renderSearchBar, renderLeadingCells,
 * renderBulkActions, renderPaginationBar); assets/js/admin-event-notes.js
 * drives each list over AJAX. EventsPage delegates the actions in ACTIONS
 * here and calls render() for the tab.
 */
final class EventNotesSection extends AbstractAdminPage
{
    /** eim_action / action values handled here (dispatched from EventsPage::handleAction()). */
    public const ACTIONS = [
        'save_event_note',
        'delete_event_note',
        'bulk_delete_event_notes',
        'export_event_notes_csv',
        'export_event_notes_json',
    ];

    /** Notes about the event itself. */
    public const LIST_EVENT = 'event';

    /** Notes about a master event's child events. */
    public const LIST_CHILDREN = 'children';

    private const ANCHOR       = '#eim-etab-notes';
    private const SEARCH_NONCE = 'eim_search_event_notes_nonce';

    /** HTML id prefix per list. */
    private const PREFIXES = [
        self::LIST_EVENT    => 'eim-event-notes',
        self::LIST_CHILDREN => 'eim-child-notes',
    ];

    /**
     * Dispatches a notes action.
     *
     * @param string $action One of ACTIONS.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_event_note'         => $this->handleSave(),
            'delete_event_note'       => $this->handleDelete(),
            'bulk_delete_event_notes' => $this->handleBulkDelete(),
            'export_event_notes_csv'  => $this->handleExport('csv'),
            'export_event_notes_json' => $this->handleExport('json'),
            default                   => null,
        };
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    /**
     * Renders the Notes tab for an existing event.
     */
    public function render(Event $event): void
    {
        EventNote::maybeCreateTable();

        $scope    = $this->scopeEvents($event);
        $children = $this->childEvents($event);
        $editing  = $this->noteBeingEdited(array_keys($scope));
        ?>
        <h2 style="margin-top:20px;">Notes</h2>
        <p class="description" style="margin-bottom:12px;">
            <?php if ($children !== []): ?>
                Private planning notes about this master event and its child events. Choose which event each note is about and tag notes with categories. Notes about this event and notes about its child events are listed separately below. Notes are only visible to administrators.
            <?php else: ?>
                Private planning notes about this event. Tag notes with categories to filter them later. Notes are only visible to administrators.
            <?php endif; ?>
        </p>

        <?php $this->renderForm($event, $scope, $editing); ?>

        <?php if ($children !== []): ?>
            <h3 style="margin:24px 0 4px;">Notes for <?= esc_html($event->name); ?> <span class="description" style="font-weight:400;">(master event)</span></h3>
        <?php endif; ?>
        <?php $this->renderList($event, self::LIST_EVENT); ?>

        <?php if ($children !== []): ?>
            <h3 style="margin:32px 0 4px;">Child Event Notes</h3>
            <p class="description" style="margin:0 0 8px;">
                Notes about <?= esc_html(implode(', ', array_map(static fn(Event $child): string => $child->name, $children))); ?>.
            </p>
            <?php $this->renderList($event, self::LIST_CHILDREN); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * One notes list: search bar, filters, export buttons, bulk actions,
     * table and pagination.
     *
     * @param string $list LIST_EVENT or LIST_CHILDREN.
     */
    private function renderList(Event $event, string $list): void
    {
        $ids        = $this->listEventIds($event, $list);
        $p          = self::PREFIXES[$list];
        $children   = $list === self::LIST_CHILDREN;
        $notes      = EventNote::listForScope($ids);
        $total      = count($notes);
        $categories = EventNote::categoriesInUse($ids);
        $eventNames = $children ? array_map(static fn(Event $c): string => $c->name, $this->childEvents($event)) : [];
        $exportArgs = ['event_id' => $event->id, 'list' => $list];
        $nonce      = 'eim_export_event_notes_' . $event->id;
        $csvUrl     = wp_nonce_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'export_event_notes_csv'] + $exportArgs), $nonce);
        $jsonUrl    = wp_nonce_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'export_event_notes_json'] + $exportArgs), $nonce);
        $showFilter = count($eventNames) > 1 || !empty($categories);
        ?>
        <?php $this->renderSearchBar(
            $p . '-search',
            $p . '-count',
            $p . '-loading',
            $children ? 'Search child event notes…' : 'Search notes…',
            $total,
            '',
            array_values(array_filter([
                ['value' => 'note', 'label' => 'Note'],
                $children ? ['value' => 'event', 'label' => 'Child Event'] : null,
                ['value' => 'categories', 'label' => 'Categories'],
            ]))
        ); ?>

        <?php if ($showFilter): ?>
            <div class="eim-notes-filters" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:6px 0 10px;">
                <?php if (count($eventNames) > 1): ?>
                    <label for="<?= esc_attr($p); ?>-event-filter" style="font-weight:600;white-space:nowrap;">Filter by Child Event:</label>
                    <select id="<?= esc_attr($p); ?>-event-filter" class="eim-search-field-select" autocomplete="off">
                        <option value="">— All Child Events —</option>
                        <?php foreach ($eventNames as $id => $name): ?>
                            <option value="<?= esc_attr($id); ?>"><?= esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (!empty($categories)): ?>
                    <label for="<?= esc_attr($p); ?>-category-filter" style="font-weight:600;white-space:nowrap;">Filter by Category:</label>
                    <select id="<?= esc_attr($p); ?>-category-filter" class="eim-search-field-select" autocomplete="off">
                        <option value="">— All Categories —</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= esc_attr($category->id); ?>"><?= esc_html(EventNote::categoryLabel($category)); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button type="button" id="<?= esc_attr($p); ?>-filter-clear" class="button button-small" style="display:none;">Clear</button>
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0;">
            <a href="<?= esc_url($csvUrl); ?>" class="button button-secondary" data-eim-notes-export="<?= esc_attr($list); ?>" data-base-url="<?= esc_url($csvUrl); ?>">⬇ Export <?= $children ? 'Child Event Notes' : 'Notes'; ?> to CSV</a>
            <a href="<?= esc_url($jsonUrl); ?>" class="button button-secondary" data-eim-notes-export="<?= esc_attr($list); ?>" data-base-url="<?= esc_url($jsonUrl); ?>">⬇ Export <?= $children ? 'Child Event Notes' : 'Notes'; ?> to JSON</a>
            <span class="description">Exports the notes that match this list's search and filters.</span>
        </div>

        <?php $this->renderBulkActions(
            $p . '-bulk-form',
            $this->editUrl($event),
            'bulk_delete_event_notes',
            'eim_bulk_delete_event_notes_' . $event->id,
            ['event_id' => $event->id]
        ); ?>

        <?php
        // Note takes all remaining width (it is the content people read); the
        // other columns get just enough for their short values. Created and
        // Last Edited share one Date column with a sort link each.
        ?>
        <table id="<?= esc_attr($p); ?>-table"
               class="wp-list-table widefat fixed striped"
               style="margin-top:8px;"
               data-eim-notes-list="<?= esc_attr($list); ?>"
               data-prefix="<?= esc_attr($p); ?>"
               data-event-id="<?= esc_attr($event->id); ?>"
               data-sort="created_at"
               data-order="desc"
               data-total="<?= esc_attr($total); ?>">
            <thead>
                <tr>
                    <?= $this->renderLeadingHeaderCells($p . '-' . $event->id); ?>
                    <th><?= $this->sortLinkFor('Note', 'note'); ?></th>
                    <?php if ($children): ?>
                        <th style="width:12%;"><?= $this->sortLinkFor('Child Event', 'event'); ?></th>
                    <?php endif; ?>
                    <th style="width:17%;"><?= $this->sortLinkFor('Categories', 'categories'); ?></th>
                    <th style="width:14%;">
                        <?= $this->sortLinkFor('Created', 'created_at', true); ?>
                        <span aria-hidden="true" style="color:#999;">·</span>
                        <?= $this->sortLinkFor('Edited', 'updated_at'); ?>
                    </th>
                    <th style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody id="<?= esc_attr($p); ?>-table-body">
                <?php $this->renderRows($event, $list, array_slice($notes, 0, 10), false); ?>
            </tbody>
        </table>
        <?php $this->renderPaginationBar($p . '-search'); ?>
        <?php
    }

    /**
     * The add/edit form.
     *
     * @param array<int, string> $scope Event ID => label.
     */
    private function renderForm(Event $event, array $scope, ?EventNote $editing): void
    {
        $selectedEvent = $editing?->eventId ?? $event->id;
        $selCats = $editing === null ? [] : array_map(static fn(Category $c): array => [
            'id'          => $c->id,
            'name'        => $c->name,
            'parent_name' => $c->parentName,
            'label'       => EventNote::categoryLabel($c),
        ], Category::forEntity(EventNote::CATEGORY_ENTITY, $editing->id));
        ?>
        <div id="eim-event-note-form-box" style="border:1px solid #dcdcde;border-radius:4px;padding:14px;background:#f6f7f7;margin:0 0 16px;">
            <h3 style="margin:0 0 8px;font-size:14px;"><?= $editing ? 'Edit Note' : 'Add a Note'; ?></h3>
            <?php if ($editing): ?>
                <p class="description" style="margin:0 0 8px;">
                    Added <?= esc_html(EventNote::formatTimestamp($editing->createdTimestamp())); ?><?= $editing->authorName !== '' ? ' by ' . esc_html($editing->authorName) : ''; ?><?php if ($editing->wasEdited()): ?> · Last edited <?= esc_html(EventNote::formatTimestamp($editing->updatedTimestamp())); ?><?php endif; ?>
                </p>
            <?php endif; ?>
            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>">
                <?php wp_nonce_field('eim_save_event_note_' . $event->id); ?>
                <input type="hidden" name="eim_action" value="save_event_note">
                <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">
                <input type="hidden" name="note_id" value="<?= esc_attr($editing?->id ?? 0); ?>">
                <table class="form-table" role="presentation" style="margin-top:0;">
                    <?php if (count($scope) > 1): ?>
                        <tr>
                            <th scope="row"><label for="eim_note_event_id">Event</label></th>
                            <td>
                                <select id="eim_note_event_id" name="note_event_id">
                                    <?php foreach ($scope as $id => $label): ?>
                                        <option value="<?= esc_attr($id); ?>" <?php selected($selectedEvent, $id); ?>><?= esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">The master event or the child event this note is about.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <input type="hidden" name="note_event_id" value="<?= esc_attr($event->id); ?>">
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label for="eim_note_text">Note <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td>
                            <textarea id="eim_note_text" name="note" class="large-text" rows="4" required
                                      maxlength="<?= esc_attr(EventNote::MAX_LENGTH); ?>"><?= esc_textarea($editing?->note ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Categories</th>
                        <td><?php $this->renderCategoryPicker('eim-note-cat-picker', $selCats, wp_create_nonce('eim_suggest_categories_nonce')); ?></td>
                    </tr>
                </table>
                <p style="margin:0;display:flex;gap:8px;align-items:center;">
                    <?php submit_button($editing ? 'Update Note' : 'Add Note', 'primary', 'submit', false); ?>
                    <?php if ($editing): ?>
                        <a href="<?= esc_url($this->editUrl($event) . self::ANCHOR); ?>" class="button">Cancel</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Table rows for a page of notes.
     *
     * @param string      $list LIST_EVENT or LIST_CHILDREN.
     * @param EventNote[] $notes
     */
    private function renderRows(Event $event, string $list, array $notes, bool $noMatches, int $offset = 0): void
    {
        if (empty($notes)) {
            $empty = $list === self::LIST_CHILDREN ? 'No notes for the child events yet.' : 'No notes for this event yet.';
            echo $this->renderNoResultsRow($noMatches ? 'No results found based upon search criteria.' : $empty); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $p          = self::PREFIXES[$list];
        $catsByNote = Category::forEntities(EventNote::CATEGORY_ENTITY, array_map(static fn(EventNote $n): int => $n->id, $notes));

        foreach ($notes as $i => $note) {
            $cats      = $catsByNote[$note->id] ?? [];
            $editUrl   = add_query_arg('note_id', $note->id, $this->editUrl($event)) . self::ANCHOR;
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'delete_event_note', 'event_id' => $event->id, 'note_id' => $note->id]),
                'eim_delete_event_note_' . $note->id
            );
            $label     = 'note from ' . EventNote::formatTimestamp($note->createdTimestamp());
            ?>
            <tr id="eim-note-<?= esc_attr($note->id); ?>">
                <?= $this->renderLeadingCells($p . '-bulk-form', $p . '-' . $event->id, $note->id, $label, $offset + $i + 1); ?>
                <td><div class="eim-note-text" style="white-space:pre-wrap;word-break:break-word;"><?= esc_html($note->note); ?></div></td>
                <?php if ($list === self::LIST_CHILDREN): ?>
                    <td><?= esc_html($note->eventName); ?></td>
                <?php endif; ?>
                <td>
                    <?php if (empty($cats)): ?>
                        <span style="color:#999;">—</span>
                    <?php else: ?>
                        <span class="eim-tag-list">
                            <?php foreach ($cats as $cat): ?>
                                <span class="eim-connection-tag"><?= esc_html(EventNote::categoryLabel($cat)); ?></span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= esc_html(EventNote::formatTimestamp($note->createdTimestamp())); ?>
                    <?php if ($note->authorName !== ''): ?>
                        <br><span class="description">by <?= esc_html($note->authorName); ?></span>
                    <?php endif; ?>
                    <?php if ($note->wasEdited()): ?>
                        <br><span class="description">Edited <?= esc_html(EventNote::formatTimestamp($note->updatedTimestamp())); ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($deleteUrl); ?>" style="color:#b32d2e;"
                       onclick="return confirm('Delete this note? This cannot be undone.');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * AJAX (wp_ajax_eim_search_event_notes): filtered, sorted, paginated rows
     * for one list (`list` = event or children).
     */
    public function handleAjaxSearch(): void
    {
        check_ajax_referer(self::SEARCH_NONCE, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $event = Event::find((int) ($_GET['event_id'] ?? 0));

        if ($event === null) {
            wp_send_json_error('Event not found.', 404);
        }

        $list    = $this->listParam($_GET, $event);
        $ids     = $this->listEventIds($event, $list);
        $filters = $this->filtersFrom($_GET, $ids);
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = $this->perPageParam();
        $all     = EventNote::listForScope($ids, $filters['query'], $filters['field'], $filters['event'], $filters['category'], $filters['sort'], $filters['order']);
        $total   = count($all);
        $offset  = ($page - 1) * $perPage;

        ob_start();
        $this->renderRows($event, $list, array_slice($all, $offset, $perPage), $this->hasFilters($filters), $offset);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    // =========================================================================
    // Actions
    // =========================================================================

    private function handleSave(): void
    {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'eim_save_event_note_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        if ($event === null) {
            wp_die('Event not found.');
        }

        $redirect    = $this->editUrl($event);
        $scopeIds    = array_keys($this->scopeEvents($event));
        $noteId      = (int) ($_POST['note_id'] ?? 0);
        $noteEventId = (int) ($_POST['note_event_id'] ?? $eventId);
        $text        = trim(sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))));
        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));
        $editArg     = $noteId > 0 ? '&note_id=' . $noteId : '';

        if (!in_array($noteEventId, $scopeIds, true)) {
            $this->redirect($redirect . $editArg . '&eim_error=event_note_invalid_event');
        }

        if ($text === '') {
            $this->redirect($redirect . $editArg . '&eim_error=event_note_required');
        }

        if (mb_strlen($text) > EventNote::MAX_LENGTH) {
            $this->redirect($redirect . $editArg . '&eim_error=event_note_too_long');
        }

        if ($noteId > 0) {
            $note = EventNote::find($noteId);

            if ($note === null || !in_array($note->eventId, $scopeIds, true)) {
                $this->redirect($redirect . '&eim_error=event_note_not_found');
            }

            EventNote::update($noteId, $noteEventId, $text, $categoryIds);
            $this->redirect($redirect . '&eim_message=event_note_updated');
        }

        $newId = EventNote::create($noteEventId, $text, get_current_user_id(), $categoryIds);
        $this->redirect($redirect . ($newId === false ? '&eim_error=event_note_save_failed' : '&eim_message=event_note_added'));
    }

    private function handleDelete(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $noteId  = (int) ($_GET['note_id'] ?? 0);

        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'eim_delete_event_note_' . $noteId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        if ($event === null) {
            wp_die('Event not found.');
        }

        $note = EventNote::find($noteId);

        if ($note === null || !in_array($note->eventId, array_keys($this->scopeEvents($event)), true)) {
            $this->redirect($this->editUrl($event) . '&eim_error=event_note_not_found');
        }

        EventNote::delete($noteId);
        $this->redirect($this->editUrl($event) . '&eim_message=event_note_deleted');
    }

    private function handleBulkDelete(): void
    {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'eim_bulk_delete_event_notes_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        if ($event === null) {
            wp_die('Event not found.');
        }

        $redirect = $this->editUrl($event);

        if ($this->requestedBulkAction() !== 'delete') {
            $this->redirect($redirect . '&eim_error=bulk_invalid_action');
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            $this->redirect($redirect . '&eim_error=bulk_no_selection');
        }

        $scopeIds = array_keys($this->scopeEvents($event));

        foreach ($ids as $noteId) {
            $note = EventNote::find($noteId);

            if ($note !== null && in_array($note->eventId, $scopeIds, true)) {
                EventNote::delete($noteId);
            }
        }

        $this->redirect($redirect . '&eim_message=bulk_deleted');
    }

    /**
     * Streams a list's notes (matching the link's search and filters) as CSV or JSON.
     *
     * `list` = event (this event's notes), children (its child events' notes)
     * or all (both; the default when no list is given).
     *
     * @param string $format 'csv' or 'json'.
     */
    private function handleExport(string $format): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);

        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'eim_export_event_notes_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        if ($event === null) {
            wp_die('Event not found.');
        }

        $requested = sanitize_key((string) ($_GET['list'] ?? ''));
        $list      = in_array($requested, [self::LIST_EVENT, self::LIST_CHILDREN], true) ? $this->listParam($_GET, $event) : 'all';
        $ids       = $list === 'all' ? array_keys($this->scopeEvents($event)) : $this->listEventIds($event, $list);
        $filters   = $this->filtersFrom($_GET, $ids);
        $notes     = EventNote::listForScope($ids, $filters['query'], $filters['field'], $filters['event'], $filters['category'], $filters['sort'], $filters['order']);
        $rows      = $this->exportRowsFor($event, $notes);
        $suffix    = match ($list) {
            self::LIST_CHILDREN => '-child-event-notes',
            self::LIST_EVENT    => '-notes',
            default             => '-all-notes',
        };
        $base = sanitize_file_name('event-' . $event->id . '-' . $event->name . $suffix);

        header('Pragma: no-cache');
        header('Expires: 0');

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $base . '.json"');

            echo wp_json_encode([
                'exported_at' => wp_date('c'),
                'event'       => ['id' => $event->id, 'name' => $event->name],
                'list'        => match ($list) {
                    self::LIST_CHILDREN => 'child event notes',
                    self::LIST_EVENT    => 'notes about this event',
                    default             => 'all notes (this event and its child events)',
                },
                'filters'     => [
                    'search'      => $filters['query'],
                    'search_in'   => $filters['field'] !== '' ? $filters['field'] : 'any',
                    'event_id'    => $filters['event'] ?: null,
                    'category_id' => $filters['category'] ?: null,
                ],
                'notes'       => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');

        $out = fopen('php://output', 'w');
        $this->writeCsvRows($out, $rows);
        fclose($out);
        exit;
    }

    // =========================================================================
    // Event export (used by EventsPage's full event CSV/JSON export)
    // =========================================================================

    /**
     * Every note about the event and (for a master event) its child events, as export rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Event $event): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->exportRowsFor($event, EventNote::listForScope(array_keys($this->scopeEvents($event)), '', '', 0, 0, 'created_at', 'asc'));
    }

    /**
     * Writes a NOTES section (this event's and its child events' notes) into the full event CSV export.
     *
     * @param resource $out
     */
    public function writeEventExportCsv($out, Event $event): void
    {
        fputcsv($out, []);
        fputcsv($out, ['SECTION', 'NOTES']);
        $this->writeCsvRows($out, $this->exportRows($event));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Events the form can attach a note to: event ID => option label. A
     * master event lists itself first, then its children.
     *
     * @return array<int, string>
     */
    private function scopeEvents(Event $event): array
    {
        $children = $this->childEvents($event);

        if ($children === []) {
            return [$event->id => $event->name];
        }

        $labels = [$event->id => $event->name . ' (master event)'];

        foreach ($children as $child) {
            $labels[$child->id] = $child->name . ' (child event)';
        }

        return $labels;
    }

    /**
     * Child events of a master event, keyed by ID (none for a child or standalone event).
     *
     * @return array<int, Event>
     */
    private function childEvents(Event $event): array
    {
        if ($event->parentEventId !== null) {
            return [];
        }

        $children = [];

        foreach (Event::childrenOf($event->id) as $child) {
            $children[$child->id] = $child;
        }

        return $children;
    }

    /**
     * The event IDs a list shows.
     *
     * @return int[]
     */
    private function listEventIds(Event $event, string $list): array
    {
        return $list === self::LIST_CHILDREN ? array_keys($this->childEvents($event)) : [$event->id];
    }

    /**
     * The requested list, falling back to LIST_EVENT (and to it when the event has no children).
     *
     * @param array<string, mixed> $source
     */
    private function listParam(array $source, Event $event): string
    {
        $list = sanitize_key((string) ($source['list'] ?? ''));

        return $list === self::LIST_CHILDREN && $this->childEvents($event) !== [] ? self::LIST_CHILDREN : self::LIST_EVENT;
    }

    /**
     * Search, filter and sort values from a request (AJAX or export link).
     *
     * @param array<string, mixed> $source $_GET.
     * @param int[]                $ids    Event IDs of the list.
     * @return array{query: string, field: string, event: int, category: int, sort: string, order: string}
     */
    private function filtersFrom(array $source, array $ids): array
    {
        $field   = sanitize_key((string) ($source['field'] ?? ''));
        $eventId = (int) ($source['note_event'] ?? 0);
        $sort    = sanitize_key((string) ($source['sort'] ?? 'created_at'));

        return [
            'query'    => sanitize_text_field(wp_unslash((string) ($source['query'] ?? ''))),
            'field'    => in_array($field, EventNote::SEARCH_FIELDS, true) ? $field : '',
            'event'    => in_array($eventId, $ids, true) ? $eventId : 0,
            'category' => max(0, (int) ($source['category'] ?? 0)),
            'sort'     => in_array($sort, EventNote::SORT_KEYS, true) ? $sort : 'created_at',
            // Newest first unless asked otherwise.
            'order'    => strtolower((string) ($source['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @param array{query: string, event: int, category: int} $filters */
    private function hasFilters(array $filters): bool
    {
        return $filters['query'] !== '' || $filters['event'] > 0 || $filters['category'] > 0;
    }

    /**
     * The note named in ?note_id=, if it belongs to this event or its children.
     *
     * @param int[] $scopeIds
     */
    private function noteBeingEdited(array $scopeIds): ?EventNote
    {
        $note = EventNote::find((int) ($_GET['note_id'] ?? 0));

        return $note !== null && in_array($note->eventId, $scopeIds, true) ? $note : null;
    }

    /**
     * @param EventNote[] $notes
     * @return array<int, array<string, mixed>>
     */
    private function exportRowsFor(Event $event, array $notes): array
    {
        $catsByNote = Category::forEntities(EventNote::CATEGORY_ENTITY, array_map(static fn(EventNote $n): int => $n->id, $notes));
        $isMaster   = $this->childEvents($event) !== [];

        return array_map(static function (EventNote $note) use ($catsByNote, $event, $isMaster): array {
            $role = $note->eventParentId !== null ? 'child event' : ($isMaster && $note->eventId === $event->id ? 'master event' : 'event');

            return [
                'id'          => $note->id,
                'event_id'    => $note->eventId,
                'event_name'  => $note->eventName,
                'event_type'  => $role,
                'note'        => $note->note,
                'categories'  => array_map(static fn(Category $c): string => EventNote::categoryLabel($c), $catsByNote[$note->id] ?? []),
                'created_at'  => EventNote::isoTimestamp($note->createdTimestamp()),
                'last_edited' => $note->wasEdited() ? EventNote::isoTimestamp($note->updatedTimestamp()) : null,
                'author'      => $note->authorName,
            ];
        }, $notes);
    }

    /**
     * @param resource                         $out
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeCsvRows($out, array $rows): void
    {
        fputcsv($out, ['Note ID', 'Event ID', 'Event', 'Event Type', 'Note', 'Categories', 'Created', 'Last Edited', 'Author']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['event_id'],
                $row['event_name'],
                ucfirst((string) $row['event_type']),
                $row['note'],
                implode(', ', $row['categories']),
                $row['created_at'],
                $row['last_edited'] ?? '',
                $row['author'],
            ]);
        }
    }

    /**
     * A client-side column sort link (the list script re-sorts over AJAX),
     * like EventsPage::clientSortLink() for the other event sub-tables.
     * Lists start sorted by Created, newest first.
     */
    private function sortLinkFor(string $label, string $key, bool $isDefault = false): string
    {
        return sprintf(
            '<a href="#" class="eim-sort-link" data-sort="%s" data-order="%s">%s <span aria-hidden="true">%s</span></a>',
            esc_attr($key),
            'asc',
            esc_html($label),
            esc_html($isDefault ? 'v' : '')
        );
    }

    private function editUrl(Event $event): string
    {
        return AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event->id]);
    }

    private function tableExists(): bool
    {
        global $wpdb;

        $table = DatabaseManager::eventNotesTable();

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** @return never Redirects back to the Notes tab. */
    private function redirect(string $url): never
    {
        wp_redirect($url . self::ANCHOR);
        exit;
    }
}
