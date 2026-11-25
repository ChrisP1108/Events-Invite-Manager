<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

if (!defined('ABSPATH')) exit;

/**
 * A private planning note about an event. Admin-only: notes are never
 * returned by the guest-facing REST API.
 *
 * Each note belongs to one event (event_id). A parent ("master") event's
 * Notes tab manages notes for the parent and all of its child events, so a
 * note can be about the master event or a specific child. Categories are
 * assigned through the shared eim_category_map pivot with entity_type
 * CATEGORY_ENTITY.
 *
 * created_at and updated_at are stored in UTC ('Y-m-d H:i:s').
 */
final class EventNote
{
    /** entity_type used in eim_category_map. */
    public const CATEGORY_ENTITY = 'event_note';

    /** Longest note accepted, in characters. */
    public const MAX_LENGTH = 10000;

    /** Sort keys accepted by listForScope(). */
    public const SORT_KEYS = ['note', 'event', 'categories', 'created_at', 'updated_at'];

    /** Search-in-column keys accepted by listForScope(). */
    public const SEARCH_FIELDS = ['note', 'event', 'categories'];

    /**
     * @param int      $id
     * @param int      $eventId        The event this note is about.
     * @param string   $eventName
     * @param ?int     $eventParentId  The note's event's parent, or null when it is a master/standalone event.
     * @param string   $note           Plain text (newlines kept).
     * @param int      $createdBy      WordPress user ID of the author (0 if unknown).
     * @param string   $authorName     Display name of the author ('' if the user no longer exists).
     * @param string   $createdAt      UTC.
     * @param string   $updatedAt      UTC.
     */
    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly string  $eventName,
        public readonly ?int    $eventParentId,
        public readonly string  $note,
        public readonly int     $createdBy,
        public readonly string  $authorName,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /** Returns a note by ID, or null. */
    public static function find(int $id): ?self
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(self::selectSql() . ' WHERE n.id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $row ? self::fromRow($row) : null;
    }

    /**
     * The event IDs a Notes tab covers: the event itself, plus its child
     * events when it is a parent ("master") event.
     *
     * @return int[] The event first, then its children in display order.
     */
    public static function scopeEventIds(Event $event): array
    {
        $ids = [$event->id];

        if ($event->parentEventId === null) {
            foreach (Event::childrenOf($event->id) as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Lists notes for a set of events with optional search, filters and sorting.
     *
     * @param int[]  $eventIds      Events in scope (see scopeEventIds()).
     * @param string $query         Search text.
     * @param string $field         One of SEARCH_FIELDS, or '' for any column.
     * @param int    $filterEventId Only notes about this event (must be in $eventIds), or 0.
     * @param int    $categoryId    Only notes with this category or one of its sub-categories, or 0.
     * @param string $sort          One of SORT_KEYS.
     * @param string $order         'asc' or 'desc'.
     * @return self[]
     */
    public static function listForScope(
        array  $eventIds,
        string $query = '',
        string $field = '',
        int    $filterEventId = 0,
        int    $categoryId = 0,
        string $sort = 'created_at',
        string $order = 'desc'
    ): array {
        global $wpdb;

        $eventIds = array_values(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0));

        if (empty($eventIds)) {
            return [];
        }

        if ($filterEventId > 0) {
            if (!in_array($filterEventId, $eventIds, true)) {
                return [];
            }
            $eventIds = [$filterEventId];
        }

        $categories = DatabaseManager::categoriesTable();
        $map        = DatabaseManager::categoryMapTable();
        $where      = ['n.event_id IN (' . implode(',', $eventIds) . ')'];
        $args       = [];

        // Category names attached to a note (used by search).
        $categoryMatch = "EXISTS (SELECT 1 FROM {$map} cm INNER JOIN {$categories} c ON c.id = cm.category_id
                          WHERE cm.entity_type = %s AND cm.entity_id = n.id AND LOWER(c.name) LIKE %s)";

        if ($query !== '') {
            $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';

            switch ($field) {
                case 'note':
                    $where[] = 'LOWER(n.note) LIKE %s';
                    $args[]  = $like;
                    break;
                case 'event':
                    $where[] = 'LOWER(e.name) LIKE %s';
                    $args[]  = $like;
                    break;
                case 'categories':
                    $where[] = $categoryMatch;
                    array_push($args, self::CATEGORY_ENTITY, $like);
                    break;
                default:
                    $where[] = "(LOWER(n.note) LIKE %s OR LOWER(e.name) LIKE %s OR {$categoryMatch})";
                    array_push($args, $like, $like, self::CATEGORY_ENTITY, $like);
            }
        }

        if ($categoryId > 0) {
            // A parent category also matches notes tagged with its sub-categories.
            $where[] = "EXISTS (SELECT 1 FROM {$map} cf INNER JOIN {$categories} cc ON cc.id = cf.category_id
                        WHERE cf.entity_type = %s AND cf.entity_id = n.id AND (cc.id = %d OR cc.parent_id = %d))";
            array_push($args, self::CATEGORY_ENTITY, $categoryId, $categoryId);
        }

        $orderSql = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $sortCol  = match ($sort) {
            'note'       => 'n.note',
            'event'      => 'e.name',
            'updated_at' => 'n.updated_at',
            default      => 'n.created_at',
        };

        $sql = self::selectSql() . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$sortCol} {$orderSql}, n.id {$orderSql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows  = $args === [] ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, ...$args));
        $notes = array_map(static fn(object $row): self => self::fromRow($row), $rows ?? []);

        // Categories are relational, so that column is sorted in PHP.
        if ($sort === 'categories') {
            $notes = self::sortByCategories($notes, $orderSql === 'ASC');
        }

        return $notes;
    }

    /**
     * The categories used by notes about these events, for the category filter.
     *
     * @param int[] $eventIds
     * @return Category[] Ordered by label.
     */
    public static function categoriesInUse(array $eventIds): array
    {
        global $wpdb;

        $eventIds = array_values(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0));

        if (empty($eventIds)) {
            return [];
        }

        $notes = DatabaseManager::eventNotesTable();
        $map   = DatabaseManager::categoryMapTable();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $noteIds = $wpdb->get_col("SELECT id FROM {$notes} WHERE event_id IN (" . implode(',', $eventIds) . ')');

        if (empty($noteIds)) {
            return [];
        }

        $byId = [];
        foreach (Category::forEntities(self::CATEGORY_ENTITY, array_map('intval', $noteIds)) as $categories) {
            foreach ($categories as $category) {
                $byId[$category->id] = $category;
            }
        }

        uasort($byId, static fn(Category $a, Category $b): int => strcasecmp(self::categoryLabel($a), self::categoryLabel($b)));

        return array_values($byId);
    }

    /** "Parent › Child" for sub-categories, otherwise the name. */
    public static function categoryLabel(Category $category): string
    {
        return $category->parentName ? $category->parentName . ' › ' . $category->name : $category->name;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Creates a note. Returns the new ID or false.
     *
     * @param int[] $categoryIds
     */
    public static function create(int $eventId, string $note, int $userId, array $categoryIds = []): int|false
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $ok  = $wpdb->insert(DatabaseManager::eventNotesTable(), [
            'event_id'   => $eventId,
            'note'       => $note,
            'created_by' => max(0, $userId),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%d', '%s', '%s']);

        if ($ok === false) {
            return false;
        }

        $id = (int) $wpdb->insert_id;
        Category::syncToEntity(self::CATEGORY_ENTITY, $id, $categoryIds);

        $created = self::find($id);
        if ($created !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_EVENT_NOTE, EimChangeEvent::ADDED, $created);
        }

        return $id;
    }

    /**
     * Updates a note's event, text and categories, and its updated_at timestamp.
     *
     * @param int[] $categoryIds
     */
    public static function update(int $id, int $eventId, string $note, array $categoryIds = []): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::eventNotesTable(),
            ['event_id' => $eventId, 'note' => $note, 'updated_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            return false;
        }

        Category::syncToEntity(self::CATEGORY_ENTITY, $id, $categoryIds);

        $updated = self::find($id);
        if ($updated !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_EVENT_NOTE, EimChangeEvent::EDITED, $updated);
        }

        return true;
    }

    /** Deletes a note and its category assignments. */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $snapshot = self::find($id);
        $result   = $wpdb->delete(DatabaseManager::eventNotesTable(), ['id' => $id], ['%d']);

        if ($result === false || $result === 0) {
            return false;
        }

        Category::syncToEntity(self::CATEGORY_ENTITY, $id, []);

        if ($snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_EVENT_NOTE, EimChangeEvent::DELETED, $snapshot);
        }

        return true;
    }

    /** Deletes every note about an event (used when the event is deleted). */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;

        $table = DatabaseManager::eventNotesTable();

        // The table may not exist yet on a site that hasn't run the upgrade.
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE event_id = %d", $eventId)));

        foreach ($ids as $id) {
            Category::syncToEntity(self::CATEGORY_ENTITY, $id, []);
        }

        $wpdb->delete($table, ['event_id' => $eventId], ['%d']);
    }

    // -------------------------------------------------------------------------
    // Presentation helpers
    // -------------------------------------------------------------------------

    /** The creation time as a Unix timestamp. */
    public function createdTimestamp(): int
    {
        return self::utcTimestamp($this->createdAt);
    }

    /** The last edit time as a Unix timestamp. */
    public function updatedTimestamp(): int
    {
        return self::utcTimestamp($this->updatedAt);
    }

    /** Whether the note was changed after it was created. */
    public function wasEdited(): bool
    {
        return $this->updatedTimestamp() > $this->createdTimestamp();
    }

    /** "Nov 24, 2026 6:30 PM MST" in the site time zone. */
    public static function formatTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? (string) wp_date('M j, Y g:i A T', $timestamp) : '';
    }

    /** ISO 8601 in the site time zone, for exports. */
    public static function isoTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }

    // -------------------------------------------------------------------------
    // Schema guard
    // -------------------------------------------------------------------------

    /**
     * Creates the notes table if it is missing. Called when the Notes tab is
     * rendered, as a guard against a silent dbDelta failure on upgrade.
     */
    public static function maybeCreateTable(): void
    {
        global $wpdb;

        $table = DatabaseManager::eventNotesTable();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(DatabaseManager::eventNotesTableSql());
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function selectSql(): string
    {
        global $wpdb;

        $notes  = DatabaseManager::eventNotesTable();
        $events = DatabaseManager::eventsTable();

        return "SELECT n.*, e.name AS event_name, e.parent_event_id AS event_parent_id, u.display_name AS author_name
                FROM {$notes} n
                INNER JOIN {$events} e ON e.id = n.event_id
                LEFT JOIN {$wpdb->users} u ON u.ID = n.created_by";
    }

    /**
     * @param self[] $notes
     * @return self[]
     */
    private static function sortByCategories(array $notes, bool $ascending): array
    {
        if (count($notes) < 2) {
            return $notes;
        }

        $labels = [];
        $byNote = Category::forEntities(self::CATEGORY_ENTITY, array_map(static fn(self $n): int => $n->id, $notes));

        foreach ($notes as $note) {
            $labels[$note->id] = strtolower(implode(', ', array_map(
                static fn(Category $c): string => self::categoryLabel($c),
                $byNote[$note->id] ?? []
            )));
        }

        usort($notes, static function (self $a, self $b) use ($labels, $ascending): int {
            // Notes without categories always go last.
            $la = $labels[$a->id];
            $lb = $labels[$b->id];

            if ($la === '' || $lb === '') {
                return ($la === '') <=> ($lb === '');
            }

            $cmp = strcmp($la, $lb) ?: $b->createdTimestamp() <=> $a->createdTimestamp();

            return $ascending ? $cmp : -$cmp;
        });

        return $notes;
    }

    private static function utcTimestamp(string $utc): int
    {
        if ($utc === '' || str_starts_with($utc, '0000-00-00')) {
            return 0;
        }

        $time = strtotime($utc . ' UTC');

        return $time === false ? 0 : $time;
    }

    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int) $row->id,
            eventId:       (int) $row->event_id,
            eventName:     (string) ($row->event_name ?? ''),
            eventParentId: isset($row->event_parent_id) && $row->event_parent_id !== null ? (int) $row->event_parent_id : null,
            note:          (string) $row->note,
            createdBy:     (int) ($row->created_by ?? 0),
            authorName:    (string) ($row->author_name ?? ''),
            createdAt:     (string) $row->created_at,
            updatedAt:     (string) $row->updated_at,
        );
    }
}
