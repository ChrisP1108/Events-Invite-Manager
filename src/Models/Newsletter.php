<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single newsletter post (eim_newsletters).
 *
 * Newsletters are standalone content items used for email blasts and website
 * display. They are linked to one or more events via eim_newsletter_events,
 * and categorised/tagged via the eim_newsletter_category_map /
 * eim_newsletter_tag_map pivot tables.
 *
 * The listForAdmin() method returns enriched instances with $events,
 * $categories, and $tags populated from GROUP_CONCAT subqueries so the list
 * page can be rendered in a single database round-trip.
 */
final class Newsletter
{
    /**
     * @param array<int, array{id:int,name:string}> $events
     * @param array<int, array{id:int,name:string}> $categories
     * @param array<int, array{id:int,name:string}> $tags
     */
    public function __construct(
        public readonly int     $id,
        public readonly string  $title,
        public readonly string  $content,
        public readonly string  $status,
        public readonly ?string $publishDate,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly array   $events     = [],
        public readonly array   $categories = [],
        public readonly array   $tags       = [],
    ) {}

    // ─── Queries ────────────────────────────────────────────────────────────

    /**
     * Returns newsletters for the admin list table, optionally filtered by a
     * search string. Events, categories, and tags are included via
     * GROUP_CONCAT so the list can be rendered without extra queries.
     *
     * @param string $query  Optional search string; empty string returns all rows.
     * @param string $sort   Sort key ('title', 'status', 'publish_date', 'events', 'categories', 'tags').
     * @param string $order  Sort direction ('asc' or 'desc').
     * @param string $field  Restrict search to one field; empty string searches all.
     * @return self[]
     */
    public static function listForAdmin(string $query, string $sort = 'title', string $order = 'asc', string $field = ''): array
    {
        global $wpdb;

        $table      = DatabaseManager::newslettersTable();
        $evTable    = DatabaseManager::newsletterEventsTable();
        $eventsT    = DatabaseManager::eventsTable();
        $catMapT    = DatabaseManager::newsletterCategoryMapTable();
        $catsT      = DatabaseManager::newsletterCategoriesTable();
        $tagMapT    = DatabaseManager::newsletterTagMapTable();
        $tagsT      = DatabaseManager::newsletterTagsTable();

        $dbSortAllowed = ['title', 'status', 'publish_date'];
        $phpSortKeys   = ['events', 'categories', 'tags'];

        $sortCol  = in_array($sort, $dbSortAllowed, true) ? $sort : 'title';
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $orderBy  = in_array($sort, $dbSortAllowed, true)
            ? "ORDER BY n.{$sortCol} {$orderSql}, n.title ASC"
            : 'ORDER BY n.title ASC';

        // GROUP_CONCAT subexpressions used in every variant of the main query.
        $eventConcat    = "(SELECT GROUP_CONCAT(DISTINCT CONCAT(e.id, ':', e.name) ORDER BY e.name SEPARATOR '|') FROM {$evTable} ne INNER JOIN {$eventsT} e ON e.id = ne.event_id WHERE ne.newsletter_id = n.id)";
        $categoryConcat = "(SELECT GROUP_CONCAT(DISTINCT CONCAT(c.id, ':', c.name) ORDER BY c.name SEPARATOR '|') FROM {$catMapT} ncm INNER JOIN {$catsT} c ON c.id = ncm.category_id WHERE ncm.newsletter_id = n.id)";
        $tagConcat      = "(SELECT GROUP_CONCAT(DISTINCT CONCAT(t.id, ':', t.name) ORDER BY t.name SEPARATOR '|') FROM {$tagMapT} ntm INNER JOIN {$tagsT} t ON t.id = ntm.tag_id WHERE ntm.newsletter_id = n.id)";

        $selectCols = "n.*, {$eventConcat} AS event_list, {$categoryConcat} AS category_list, {$tagConcat} AS tag_list";

        if ($query === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results("SELECT {$selectCols} FROM {$table} n {$orderBy}");
        } else {
            $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';

            switch ($field) {
                case 'title':
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sql = $wpdb->prepare(
                        "SELECT {$selectCols} FROM {$table} n WHERE LOWER(n.title) LIKE %s {$orderBy}",
                        $like
                    );
                    break;

                case 'status':
                    $statusVal = stripos('published', $query) !== false ? 'published' : 'draft';
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sql = $wpdb->prepare(
                        "SELECT {$selectCols} FROM {$table} n WHERE n.status = %s {$orderBy}",
                        $statusVal
                    );
                    break;

                case 'events':
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sql = $wpdb->prepare(
                        "SELECT {$selectCols} FROM {$table} n
                         WHERE EXISTS (
                             SELECT 1 FROM {$evTable} ne
                             INNER JOIN {$eventsT} e ON e.id = ne.event_id
                             WHERE ne.newsletter_id = n.id AND LOWER(e.name) LIKE %s
                         ) {$orderBy}",
                        $like
                    );
                    break;

                case 'categories':
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sql = $wpdb->prepare(
                        "SELECT {$selectCols} FROM {$table} n
                         WHERE EXISTS (
                             SELECT 1 FROM {$catMapT} ncm
                             INNER JOIN {$catsT} c ON c.id = ncm.category_id
                             WHERE ncm.newsletter_id = n.id AND LOWER(c.name) LIKE %s
                         ) {$orderBy}",
                        $like
                    );
                    break;

                case 'tags':
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sql = $wpdb->prepare(
                        "SELECT {$selectCols} FROM {$table} n
                         WHERE EXISTS (
                             SELECT 1 FROM {$tagMapT} ntm
                             INNER JOIN {$tagsT} t ON t.id = ntm.tag_id
                             WHERE ntm.newsletter_id = n.id AND LOWER(t.name) LIKE %s
                         ) {$orderBy}",
                        $like
                    );
                    break;

                default:
                    // Any: search title OR event/category/tag names.
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sql = $wpdb->prepare(
                        "SELECT {$selectCols} FROM {$table} n
                         WHERE LOWER(n.title) LIKE %s
                            OR EXISTS (
                                SELECT 1 FROM {$evTable} ne
                                INNER JOIN {$eventsT} e ON e.id = ne.event_id
                                WHERE ne.newsletter_id = n.id AND LOWER(e.name) LIKE %s
                            )
                            OR EXISTS (
                                SELECT 1 FROM {$catMapT} ncm
                                INNER JOIN {$catsT} c ON c.id = ncm.category_id
                                WHERE ncm.newsletter_id = n.id AND LOWER(c.name) LIKE %s
                            )
                            OR EXISTS (
                                SELECT 1 FROM {$tagMapT} ntm
                                INNER JOIN {$tagsT} t ON t.id = ntm.tag_id
                                WHERE ntm.newsletter_id = n.id AND LOWER(t.name) LIKE %s
                            )
                         {$orderBy}",
                        $like, $like, $like, $like
                    );
            }

            $rows = $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $newsletters = array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);

        if (in_array($sort, $phpSortKeys, true)) {
            $newsletters = self::phpSort($newsletters, $sort, $order);
        }

        return $newsletters;
    }

    /**
     * Returns all published newsletters for a given event, ordered by publish_date descending.
     *
     * Only newsletters with status = 'published' and publish_date <= NOW (or no publish_date)
     * are returned. Intended for the public-facing newsletter page.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function publishedForEvent(int $eventId): array
    {
        global $wpdb;

        $table   = DatabaseManager::newslettersTable();
        $evTable = DatabaseManager::newsletterEventsTable();
        $now     = current_time('mysql');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.* FROM {$table} n
                 INNER JOIN {$evTable} ne ON ne.newsletter_id = n.id
                 WHERE ne.event_id = %d
                   AND n.status = 'published'
                   AND (n.publish_date IS NULL OR n.publish_date <= %s)
                 ORDER BY n.publish_date DESC, n.created_at DESC",
                $eventId,
                $now
            )
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns one published newsletter linked to an event, or null when it is not public.
     *
     * @param int $eventId
     * @param int $newsletterId
     * @return self|null
     */
    public static function findPublishedForEvent(int $eventId, int $newsletterId): ?self
    {
        global $wpdb;

        $table   = DatabaseManager::newslettersTable();
        $evTable = DatabaseManager::newsletterEventsTable();
        $now     = current_time('mysql');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT n.* FROM {$table} n
                 INNER JOIN {$evTable} ne ON ne.newsletter_id = n.id
                 WHERE ne.event_id = %d
                   AND n.id = %d
                   AND n.status = 'published'
                   AND (n.publish_date IS NULL OR n.publish_date <= %s)
                 LIMIT 1",
                $eventId,
                $newsletterId,
                $now
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds a single newsletter by primary key.
     * Events, categories, and tags are NOT populated; call the static helpers
     * separately when building the edit form.
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::newslettersTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns all events linked to a newsletter as [{id, name}] pairs.
     *
     * @param int $newsletterId
     * @return array<int, array{id:int,name:string}>
     */
    public static function eventsForNewsletter(int $newsletterId): array
    {
        global $wpdb;

        $evTable  = DatabaseManager::newsletterEventsTable();
        $eventsT  = DatabaseManager::eventsTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.name FROM {$evTable} ne
                 INNER JOIN {$eventsT} e ON e.id = ne.event_id
                 WHERE ne.newsletter_id = %d ORDER BY e.name ASC",
                $newsletterId
            )
        );

        return array_map(static fn(object $r): array => ['id' => (int) $r->id, 'name' => (string) $r->name], $rows ?? []);
    }

    /**
     * Returns all categories linked to a newsletter as [{id, name}] pairs.
     *
     * @param int $newsletterId
     * @return array<int, array{id:int,name:string}>
     */
    public static function categoriesForNewsletter(int $newsletterId): array
    {
        global $wpdb;

        $catMapT = DatabaseManager::newsletterCategoryMapTable();
        $catsT   = DatabaseManager::newsletterCategoriesTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.name FROM {$catMapT} ncm
                 INNER JOIN {$catsT} c ON c.id = ncm.category_id
                 WHERE ncm.newsletter_id = %d ORDER BY c.name ASC",
                $newsletterId
            )
        );

        return array_map(static fn(object $r): array => ['id' => (int) $r->id, 'name' => (string) $r->name], $rows ?? []);
    }

    /**
     * Returns all tags linked to a newsletter as [{id, name}] pairs.
     *
     * @param int $newsletterId
     * @return array<int, array{id:int,name:string}>
     */
    public static function tagsForNewsletter(int $newsletterId): array
    {
        global $wpdb;

        $tagMapT = DatabaseManager::newsletterTagMapTable();
        $tagsT   = DatabaseManager::newsletterTagsTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name FROM {$tagMapT} ntm
                 INNER JOIN {$tagsT} t ON t.id = ntm.tag_id
                 WHERE ntm.newsletter_id = %d ORDER BY t.name ASC",
                $newsletterId
            )
        );

        return array_map(static fn(object $r): array => ['id' => (int) $r->id, 'name' => (string) $r->name], $rows ?? []);
    }

    // ─── Mutations ───────────────────────────────────────────────────────────

    /**
     * Inserts a new newsletter and syncs its event/category/tag pivots.
     *
     * @param array<string, mixed> $data
     * @return int|false New newsletter ID, or false on failure.
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::newslettersTable(), [
            'title'        => $data['title']        ?? '',
            'content'      => $data['content']      ?? '',
            'status'       => $data['status']       === 'published' ? 'published' : 'draft',
            'publish_date' => ($data['publish_date'] ?? '') ?: null,
        ]);

        if (!$result) {
            return false;
        }

        $id = (int) $wpdb->insert_id;
        self::syncEvents($id, $data['event_ids']     ?? []);
        self::syncCategories($id, $data['category_ids'] ?? []);
        self::syncTags($id, $data['tag_ids']         ?? []);

        return $id;
    }

    /**
     * Updates an existing newsletter and re-syncs its pivots.
     *
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::newslettersTable(),
            [
                'title'        => $data['title']        ?? '',
                'content'      => $data['content']      ?? '',
                'status'       => $data['status']       === 'published' ? 'published' : 'draft',
                'publish_date' => ($data['publish_date'] ?? '') ?: null,
            ],
            ['id' => $id]
        );

        if ($result === false) {
            return false;
        }

        self::syncEvents($id, $data['event_ids']     ?? []);
        self::syncCategories($id, $data['category_ids'] ?? []);
        self::syncTags($id, $data['tag_ids']         ?? []);

        return true;
    }

    /**
     * Deletes a newsletter and all its pivot rows.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $wpdb->delete(DatabaseManager::newsletterEventsTable(),      ['newsletter_id' => $id]);
        $wpdb->delete(DatabaseManager::newsletterCategoryMapTable(), ['newsletter_id' => $id]);
        $wpdb->delete(DatabaseManager::newsletterTagMapTable(),      ['newsletter_id' => $id]);

        return $wpdb->delete(DatabaseManager::newslettersTable(), ['id' => $id]) !== false;
    }

    // ─── Pivot sync helpers ──────────────────────────────────────────────────

    /**
     * Replaces the event associations for a newsletter.
     *
     * @param int   $newsletterId
     * @param int[] $eventIds
     */
    private static function syncEvents(int $newsletterId, array $eventIds): void
    {
        global $wpdb;

        $table    = DatabaseManager::newsletterEventsTable();
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

        $wpdb->delete($table, ['newsletter_id' => $newsletterId]);

        foreach ($eventIds as $eventId) {
            $wpdb->insert($table, ['newsletter_id' => $newsletterId, 'event_id' => $eventId]);
        }
    }

    /**
     * Replaces the category associations for a newsletter.
     *
     * @param int   $newsletterId
     * @param int[] $categoryIds
     */
    private static function syncCategories(int $newsletterId, array $categoryIds): void
    {
        global $wpdb;

        $table       = DatabaseManager::newsletterCategoryMapTable();
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

        $wpdb->delete($table, ['newsletter_id' => $newsletterId]);

        foreach ($categoryIds as $catId) {
            $wpdb->insert($table, ['newsletter_id' => $newsletterId, 'category_id' => $catId]);
        }
    }

    /**
     * Replaces the tag associations for a newsletter.
     *
     * @param int   $newsletterId
     * @param int[] $tagIds
     */
    private static function syncTags(int $newsletterId, array $tagIds): void
    {
        global $wpdb;

        $table  = DatabaseManager::newsletterTagMapTable();
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));

        $wpdb->delete($table, ['newsletter_id' => $newsletterId]);

        foreach ($tagIds as $tagId) {
            $wpdb->insert($table, ['newsletter_id' => $newsletterId, 'tag_id' => $tagId]);
        }
    }

    // ─── PHP sort ───────────────────────────────────────────────────────────

    /**
     * Sorts an array of newsletters in PHP for relational columns (events,
     * categories, tags) that cannot be sorted at the DB level.
     *
     * Sorts by the first name in the collection ascending/descending, putting
     * newsletters with no items last in either direction.
     *
     * @param self[]  $newsletters
     * @param string  $sort
     * @param string  $order
     * @return self[]
     */
    private static function phpSort(array $newsletters, string $sort, string $order): array
    {
        usort($newsletters, static function (self $a, self $b) use ($sort, $order): int {
            $aItems = match ($sort) {
                'categories' => $a->categories,
                'tags'       => $a->tags,
                default      => $a->events,
            };
            $bItems = match ($sort) {
                'categories' => $b->categories,
                'tags'       => $b->tags,
                default      => $b->events,
            };

            $aName = $aItems[0]['name'] ?? '';
            $bName = $bItems[0]['name'] ?? '';

            // Items with no entries sort to the bottom regardless of direction.
            if ($aName === '' && $bName !== '') return 1;
            if ($aName !== '' && $bName === '') return -1;

            $cmp = strnatcasecmp($aName, $bName);
            return $order === 'desc' ? -$cmp : $cmp;
        });

        return $newsletters;
    }

    // ─── Row hydration ───────────────────────────────────────────────────────

    /**
     * Hydrates a Newsletter from a raw DB row, parsing GROUP_CONCAT columns
     * when present (listForAdmin) or leaving them empty (find).
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:          (int)    $row->id,
            title:                $row->title       ?? '',
            content:              $row->content     ?? '',
            status:               $row->status      ?? 'draft',
            publishDate:          ($row->publish_date ?? '') ?: null,
            createdAt:            $row->created_at  ?? '',
            updatedAt:            $row->updated_at  ?? '',
            events:      self::parseConcat((string) ($row->event_list    ?? '')),
            categories:  self::parseConcat((string) ($row->category_list ?? '')),
            tags:        self::parseConcat((string) ($row->tag_list      ?? '')),
        );
    }

    /**
     * Parses a "id:name|id:name" GROUP_CONCAT string into [{id, name}] pairs.
     *
     * @param string $raw
     * @return array<int, array{id:int,name:string}>
     */
    private static function parseConcat(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $items = [];
        foreach (explode('|', $raw) as $chunk) {
            $pos = strpos($chunk, ':');
            if ($pos === false) continue;
            $items[] = [
                'id'   => (int)    substr($chunk, 0, $pos),
                'name' => (string) substr($chunk, $pos + 1),
            ];
        }

        return $items;
    }
}
