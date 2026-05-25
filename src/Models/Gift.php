<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a gift/registry item in the global gifts library (eim_gifts).
 *
 * Gifts can be linked to events and have per-event purchase tracking
 * via eim_gift_events and eim_gift_purchases respectively.
 * Categories are managed via the shared eim_category_map pivot.
 */
final class Gift
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $description,
        public readonly int    $priceCents,
        public readonly string $websiteUrl,
        public readonly int    $imageAttachmentId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::giftsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function listForAdmin(
        string $query = '',
        string $sort  = 'name',
        string $order = 'asc',
        string $field = ''
    ): array {
        global $wpdb;

        $table    = DatabaseManager::giftsTable();
        $allowed  = ['name', 'price_cents', 'website_url'];
        $sortCol  = in_array($sort, $allowed, true) ? $sort : 'name';
        $orderSql = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $orderBy  = "ORDER BY {$sortCol} {$orderSql}, name ASC"; // phpcs:ignore

        if ($query === '') {
            $rows = $wpdb->get_results("SELECT * FROM {$table} {$orderBy}"); // phpcs:ignore
            return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
        }

        $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';

        switch ($field) {
            case 'name':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(name) LIKE %s {$orderBy}", $like
                );
                break;
            case 'description':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(description) LIKE %s {$orderBy}", $like
                );
                break;
            case 'website_url':
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table} WHERE LOWER(website_url) LIKE %s {$orderBy}", $like
                );
                break;
            default:
                $sql = $wpdb->prepare( // phpcs:ignore
                    "SELECT * FROM {$table}
                     WHERE LOWER(name) LIKE %s
                        OR LOWER(description) LIKE %s
                        OR LOWER(website_url) LIKE %s
                     {$orderBy}",
                    $like, $like, $like
                );
        }

        $rows = $wpdb->get_results($sql); // phpcs:ignore
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Autocomplete search — returns up to $limit gifts whose name contains the query.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = 10, int $excludeEventId = 0): array
    {
        global $wpdb;

        $query = trim($query);
        if (mb_strlen($query) < 1) {
            return [];
        }

        $table = DatabaseManager::giftsTable();
        $like  = '%' . $wpdb->esc_like($query) . '%';
        $sql   = "SELECT * FROM {$table} WHERE name LIKE %s";
        $args  = [$like];

        if ($excludeEventId > 0) {
            $giftEventsTable = DatabaseManager::giftEventsTable();
            $sql .= " AND id NOT IN (SELECT gift_id FROM {$giftEventsTable} WHERE event_id = %d)";
            $args[] = $excludeEventId;
        }

        $sql .= " ORDER BY name ASC LIMIT %d";
        $args[] = $limit;

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...$args) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        return array_map(static fn(object $row): array => [
            'id'    => (int) $row->id,
            'name'  => $row->name,
            'label' => $row->name,
        ], $rows ?? []);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): ?self
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::giftsTable(), [
            'name'                => (string) ($data['name']                ?? ''),
            'description'         => (string) ($data['description']         ?? ''),
            'price_cents'         => (int)    ($data['price_cents']         ?? 0),
            'website_url'         => (string) ($data['website_url']         ?? ''),
            'image_attachment_id' => (int)    ($data['image_attachment_id'] ?? 0),
        ]);

        return $result ? self::find((int) $wpdb->insert_id) : null;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = [];
        if (array_key_exists('name',        $data)) $fields['name']        = (string) $data['name'];
        if (array_key_exists('description', $data)) $fields['description'] = (string) $data['description'];
        if (array_key_exists('price_cents', $data)) $fields['price_cents'] = (int)    $data['price_cents'];
        if (array_key_exists('website_url', $data)) $fields['website_url'] = (string) $data['website_url'];
        if (array_key_exists('image_attachment_id', $data)) $fields['image_attachment_id'] = (int) $data['image_attachment_id'];

        if (empty($fields)) return true;
        return $wpdb->update(DatabaseManager::giftsTable(), $fields, ['id' => $id]) !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;

        // Remove event links and purchase records.
        $wpdb->delete(DatabaseManager::giftEventsTable(),    ['gift_id' => $id]);
        $wpdb->delete(DatabaseManager::giftPurchasesTable(), ['gift_id' => $id]);

        return $wpdb->delete(DatabaseManager::giftsTable(), ['id' => $id]) !== false;
    }

    public static function count(): int
    {
        global $wpdb;
        $table = DatabaseManager::giftsTable();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore
    }

    // -------------------------------------------------------------------------
    // Event linking
    // -------------------------------------------------------------------------

    /**
     * Returns the event IDs currently linked to a gift.
     *
     * @return int[]
     */
    public static function eventIdsForGift(int $giftId): array
    {
        global $wpdb;
        $table = DatabaseManager::giftEventsTable();
        $rows  = $wpdb->get_col($wpdb->prepare("SELECT event_id FROM {$table} WHERE gift_id = %d ORDER BY event_id ASC", $giftId));
        return array_map('intval', $rows ?? []);
    }

    /**
     * Returns gifts linked to a specific event, optionally filtered for admin tables.
     *
     * @return self[]
     */
    public static function forEvent(
        int $eventId,
        string $query = '',
        string $sort  = 'name',
        string $order = 'asc',
        string $field = ''
    ): array {
        global $wpdb;

        if ($eventId <= 0) {
            return [];
        }

        $giftsTable     = DatabaseManager::giftsTable();
        $giftEventsTable = DatabaseManager::giftEventsTable();
        $purchasesTable = DatabaseManager::giftPurchasesTable();
        $orderSql       = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $sortMap        = [
            'name'        => 'g.name',
            'price_cents' => 'g.price_cents',
            'website_url' => 'g.website_url',
            'purchased'   => 'COALESCE(gp.is_purchased, 0)',
        ];
        $sortCol        = $sortMap[$sort] ?? $sortMap['name'];
        $orderBy        = "ORDER BY {$sortCol} {$orderSql}, g.name ASC";
        $where          = ["ge.event_id = %d"];
        $args           = [$eventId];

        if ($query !== '') {
            $like = '%' . $wpdb->esc_like(strtolower($query)) . '%';
            switch ($field) {
                case 'name':
                    $where[] = 'LOWER(g.name) LIKE %s';
                    $args[]  = $like;
                    break;
                case 'description':
                    $where[] = 'LOWER(g.description) LIKE %s';
                    $args[]  = $like;
                    break;
                case 'website_url':
                    $where[] = 'LOWER(g.website_url) LIKE %s';
                    $args[]  = $like;
                    break;
                case 'purchased':
                    $where[] = 'CASE WHEN COALESCE(gp.is_purchased, 0) = 1 THEN %s ELSE %s END LIKE %s';
                    $args[]  = 'purchased';
                    $args[]  = 'not purchased';
                    $args[]  = $like;
                    break;
                default:
                    $where[] = '(LOWER(g.name) LIKE %s OR LOWER(g.description) LIKE %s OR LOWER(g.website_url) LIKE %s OR CASE WHEN COALESCE(gp.is_purchased, 0) = 1 THEN %s ELSE %s END LIKE %s)';
                    $args[]  = $like;
                    $args[]  = $like;
                    $args[]  = $like;
                    $args[]  = 'purchased';
                    $args[]  = 'not purchased';
                    $args[]  = $like;
            }
        }

        $sql = "SELECT g.*
                FROM {$giftsTable} g
                INNER JOIN {$giftEventsTable} ge ON ge.gift_id = g.id
                LEFT JOIN {$purchasesTable} gp ON gp.gift_id = g.id AND gp.event_id = ge.event_id
                WHERE " . implode(' AND ', $where) . "
                {$orderBy}";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array_map(static fn(object $row): self => self::fromRow($row), $rows ?? []);
    }

    public static function isLinkedToEvent(int $giftId, int $eventId): bool
    {
        global $wpdb;

        if ($giftId <= 0 || $eventId <= 0) {
            return false;
        }

        $table = DatabaseManager::giftEventsTable();

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE gift_id = %d AND event_id = %d LIMIT 1",
                $giftId,
                $eventId
            )
        );
    }

    public static function addToEvent(int $giftId, int $eventId): bool
    {
        global $wpdb;

        if ($giftId <= 0 || $eventId <= 0) {
            return false;
        }

        $giftEventsTable = DatabaseManager::giftEventsTable();
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$giftEventsTable} (gift_id, event_id) VALUES (%d, %d)",
                $giftId,
                $eventId
            )
        );

        if ($result === false) {
            return false;
        }

        $purchasesTable = DatabaseManager::giftPurchasesTable();
        return $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$purchasesTable} (gift_id, event_id, is_purchased) VALUES (%d, %d, 0)",
                $giftId,
                $eventId
            )
        ) !== false;
    }

    public static function removeFromEvent(int $giftId, int $eventId): bool
    {
        global $wpdb;

        $wpdb->delete(DatabaseManager::giftPurchasesTable(), ['gift_id' => $giftId, 'event_id' => $eventId]);

        return $wpdb->delete(
            DatabaseManager::giftEventsTable(),
            ['gift_id' => $giftId, 'event_id' => $eventId]
        ) !== false;
    }

    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;

        $wpdb->delete(DatabaseManager::giftPurchasesTable(), ['event_id' => $eventId]);
        $wpdb->delete(DatabaseManager::giftEventsTable(), ['event_id' => $eventId]);
    }

    /**
     * Returns events data grouped by gift ID (for the list table column).
     *
     * @param  int[]  $giftIds
     * @return array<int, array<int, array{id:int, name:string}>>
     */
    public static function eventDataForGifts(array $giftIds): array
    {
        global $wpdb;

        $giftIds = array_values(array_unique(array_filter(array_map('intval', $giftIds))));
        if (empty($giftIds)) {
            return [];
        }

        $giftEventsTable = DatabaseManager::giftEventsTable();
        $eventsTable     = DatabaseManager::eventsTable();
        $placeholders    = implode(', ', array_fill(0, count($giftIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare( // phpcs:ignore
                "SELECT ge.gift_id, e.id AS event_id, e.name AS event_name
                 FROM {$giftEventsTable} ge
                 INNER JOIN {$eventsTable} e ON e.id = ge.event_id
                 WHERE ge.gift_id IN ({$placeholders})
                 ORDER BY e.name ASC",
                ...$giftIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $gid = (int) $row->gift_id;
            $grouped[$gid][] = ['id' => (int) $row->event_id, 'name' => (string) $row->event_name];
        }
        return $grouped;
    }

    /**
     * Replaces the event links for a gift with the given event IDs.
     *
     * @param int[] $eventIds
     */
    public static function syncEvents(int $giftId, array $eventIds): void
    {
        global $wpdb;

        $eventIds   = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        $table      = DatabaseManager::giftEventsTable();
        $existing   = self::eventIdsForGift($giftId);

        $toAdd    = array_diff($eventIds, $existing);
        $toRemove = array_diff($existing, $eventIds);

        foreach ($toAdd as $eid) {
            $wpdb->query(
                $wpdb->prepare("INSERT IGNORE INTO {$table} (gift_id, event_id) VALUES (%d, %d)", $giftId, $eid)
            );
            // Ensure a purchase record exists for this gift+event.
            $purchasesTable = DatabaseManager::giftPurchasesTable();
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$purchasesTable} (gift_id, event_id, is_purchased) VALUES (%d, %d, 0)",
                    $giftId, $eid
                )
            );
        }

        foreach ($toRemove as $eid) {
            $wpdb->delete($table, ['gift_id' => $giftId, 'event_id' => $eid]);
            $wpdb->delete(DatabaseManager::giftPurchasesTable(), ['gift_id' => $giftId, 'event_id' => $eid]);
        }
    }

    // -------------------------------------------------------------------------
    // Purchase tracking
    // -------------------------------------------------------------------------

    /**
     * Returns purchase status keyed by event_id for the given gift.
     *
     * @return array<int, bool>   event_id => is_purchased
     */
    public static function purchaseStatusForGift(int $giftId): array
    {
        global $wpdb;
        $table = DatabaseManager::giftPurchasesTable();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT event_id, is_purchased FROM {$table} WHERE gift_id = %d", $giftId));
        $map   = [];
        foreach ($rows ?? [] as $row) {
            $map[(int) $row->event_id] = (bool) $row->is_purchased;
        }
        return $map;
    }

    /**
     * Returns purchase status grouped by gift ID.
     *
     * @param  int[]  $giftIds
     * @return array<int, array<int, bool>>   gift_id => [ event_id => is_purchased ]
     */
    public static function purchaseStatusForGifts(array $giftIds): array
    {
        global $wpdb;

        $giftIds = array_values(array_unique(array_filter(array_map('intval', $giftIds))));
        if (empty($giftIds)) {
            return [];
        }

        $table        = DatabaseManager::giftPurchasesTable();
        $placeholders = implode(', ', array_fill(0, count($giftIds), '%d'));
        $rows         = $wpdb->get_results(
            $wpdb->prepare("SELECT gift_id, event_id, is_purchased FROM {$table} WHERE gift_id IN ({$placeholders})", ...$giftIds) // phpcs:ignore
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $grouped[(int) $row->gift_id][(int) $row->event_id] = (bool) $row->is_purchased;
        }
        return $grouped;
    }

    /**
     * Returns purchase records for a list of gifts grouped by gift and event.
     *
     * @param  int[]  $giftIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    public static function purchaseDetailsForGifts(array $giftIds): array
    {
        global $wpdb;

        $giftIds = array_values(array_unique(array_filter(array_map('intval', $giftIds))));
        if (empty($giftIds)) {
            return [];
        }

        $table        = DatabaseManager::giftPurchasesTable();
        $placeholders = implode(', ', array_fill(0, count($giftIds), '%d'));
        $rows         = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE gift_id IN ({$placeholders})", ...$giftIds) // phpcs:ignore
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $grouped[(int) $row->gift_id][(int) $row->event_id] = self::purchaseDetailsFromRow($row);
        }

        return $grouped;
    }

    /**
     * Returns purchase records for one event keyed by gift ID.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function purchaseDetailsForEvent(int $eventId): array
    {
        global $wpdb;

        if ($eventId <= 0) {
            return [];
        }

        $table = DatabaseManager::giftPurchasesTable();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %d", $eventId));
        $map   = [];

        foreach ($rows ?? [] as $row) {
            $map[(int) $row->gift_id] = self::purchaseDetailsFromRow($row);
        }

        return $map;
    }

    public static function purchaseDetailsForGiftEvent(int $giftId, int $eventId): ?array
    {
        global $wpdb;

        $table = DatabaseManager::giftPurchasesTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE gift_id = %d AND event_id = %d LIMIT 1",
                $giftId,
                $eventId
            )
        );

        return $row ? self::purchaseDetailsFromRow($row) : null;
    }

    /**
     * Updates the purchase status for a specific gift+event combination.
     */
    public static function setPurchaseStatus(
        int $giftId,
        int $eventId,
        bool $isPurchased,
        ?int $purchasedByGroupId = null,
        ?int $purchasedByInviteeId = null
    ): void
    {
        global $wpdb;
        $table = DatabaseManager::giftPurchasesTable();

        if (!$isPurchased) {
            $purchasedByGroupId   = null;
            $purchasedByInviteeId = null;
        }

        $purchasedAt = $isPurchased ? current_time('mysql') : null;
        $purchasedAtSql = $purchasedAt !== null ? '%s' : 'NULL';
        $groupIdSql     = $purchasedByGroupId !== null && $purchasedByGroupId > 0 ? '%d' : 'NULL';
        $inviteeIdSql   = $purchasedByInviteeId !== null && $purchasedByInviteeId > 0 ? '%d' : 'NULL';
        $args           = [$giftId, $eventId, $isPurchased ? 1 : 0];

        if ($purchasedAt !== null) {
            $args[] = $purchasedAt;
        }
        if ($groupIdSql === '%d') {
            $args[] = $purchasedByGroupId;
        }
        if ($inviteeIdSql === '%d') {
            $args[] = $purchasedByInviteeId;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (gift_id, event_id, is_purchased, purchased_at, purchased_by_group_id, purchased_by_invitee_id)
                 VALUES (%d, %d, %d, {$purchasedAtSql}, {$groupIdSql}, {$inviteeIdSql})
                 ON DUPLICATE KEY UPDATE
                     is_purchased = VALUES(is_purchased),
                     purchased_at = VALUES(purchased_at),
                     purchased_by_group_id = VALUES(purchased_by_group_id),
                     purchased_by_invitee_id = VALUES(purchased_by_invitee_id)",
                ...$args
            )
        );
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    public function formattedPrice(): string
    {
        if ($this->priceCents === 0) {
            return '';
        }
        return '$' . number_format($this->priceCents / 100, 2);
    }

    public function imageUrl(string $size = 'thumbnail'): string
    {
        if ($this->imageAttachmentId <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($this->imageAttachmentId, $size);
        return is_string($url) ? $url : '';
    }

    public function imageAltText(): string
    {
        if ($this->imageAttachmentId <= 0) {
            return '';
        }

        $alt = get_post_meta($this->imageAttachmentId, '_wp_attachment_image_alt', true);
        return is_string($alt) ? $alt : '';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:          (int)  $row->id,
            name:               $row->name        ?? '',
            description:        $row->description ?? '',
            priceCents:  (int)  ($row->price_cents ?? 0),
            websiteUrl:         $row->website_url  ?? '',
            imageAttachmentId: (int) ($row->image_attachment_id ?? 0),
            createdAt:          $row->created_at   ?? '',
            updatedAt:          $row->updated_at   ?? '',
        );
    }

    /** @return array<string,mixed> */
    private static function purchaseDetailsFromRow(object $row): array
    {
        return [
            'gift_id'                 => (int) $row->gift_id,
            'event_id'                => (int) $row->event_id,
            'is_purchased'            => (bool) $row->is_purchased,
            'purchased_at'            => $row->purchased_at ?? null,
            'purchased_by_group_id'   => isset($row->purchased_by_group_id) && (int) $row->purchased_by_group_id > 0 ? (int) $row->purchased_by_group_id : null,
            'purchased_by_invitee_id' => isset($row->purchased_by_invitee_id) && (int) $row->purchased_by_invitee_id > 0 ? (int) $row->purchased_by_invitee_id : null,
        ];
    }
}
