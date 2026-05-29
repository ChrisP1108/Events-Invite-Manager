<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a message or admin reply in a conversation thread between an
 * invitation group's connection group and the admin for a specific event.
 *
 * Invitee messages are submitted via the frontend REST API; admin replies are
 * created via the admin dashboard. Both live in the same table so they can be
 * rendered as a chronological thread.
 */
final class EventMessage
{
    /**
     * @param int     $id                  Primary key.
     * @param int     $eventId             FK to eim_events.
     * @param int     $connectionGroupId   FK to eim_invitee_connection_groups.
     * @param string  $message             Message body text.
     * @param bool    $isRead              Whether an admin has marked this message as read.
     * @param bool    $isAdminReply        True for admin-sent replies; false for invitee messages.
     * @param string  $createdAt           MySQL DATETIME when the message was submitted.
     * @param ?string $connectionGroupName Connection group name (populated by listForAdmin JOIN).
     * @param ?string $eventName           Event name (populated by listForAdmin JOIN).
     */
    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly int     $connectionGroupId,
        public readonly string  $message,
        public readonly bool    $isRead,
        public readonly bool    $isAdminReply,
        public readonly string  $createdAt,
        public readonly ?string $connectionGroupName = null,
        public readonly ?string $eventName           = null,
    ) {}

    // ─── Queries ─────────────────────────────────────────────────────────────

    /**
     * Returns invitee messages across all events for the global admin list.
     *
     * Admin replies are excluded — they appear only in the thread panel.
     * JOINs events and connection_groups to include their names for display and sorting.
     *
     * @param string $search Search string (empty = no filter).
     * @param string $sort   Column key: event_name | connection_group_name | message | is_read | created_at.
     * @param string $order  'asc' or 'desc'.
     * @param string $field  Search scope: 'event' | 'connection_group' | 'message' | '' (any).
     * @return self[]
     */
    public static function listForAdmin(string $search, string $sort, string $order, string $field): array
    {
        global $wpdb;

        $msgTable = DatabaseManager::eventMessagesTable();
        $evtTable = DatabaseManager::eventsTable();
        $cgTable  = DatabaseManager::inviteeConnectionGroupsTable();

        $allowedSorts = ['event_name', 'connection_group_name', 'message', 'is_read', 'created_at'];
        $sort  = in_array($sort, $allowedSorts, true) ? $sort : 'created_at';
        $order = $order === 'asc' ? 'ASC' : 'DESC';

        $sortExpr = match ($sort) {
            'event_name'            => "e.name {$order}, m.created_at DESC",
            'connection_group_name' => "cg.name {$order}, m.created_at DESC",
            default                 => "m.{$sort} {$order}, m.id DESC",
        };

        // Show only invitee messages in the global list; admin replies appear in threads.
        $where = 'm.is_admin_reply = 0';
        $args  = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            match ($field) {
                'event'            => [$where .= ' AND e.name LIKE %s',    $args = [$like]],
                'connection_group' => [$where .= ' AND cg.name LIKE %s',   $args = [$like]],
                'message'          => [$where .= ' AND m.message LIKE %s', $args = [$like]],
                default            => [$where .= ' AND (e.name LIKE %s OR cg.name LIKE %s OR m.message LIKE %s)', $args = [$like, $like, $like]],
            };
        }

        $sql = "SELECT m.*, e.name AS event_name, cg.name AS connection_group_name
                FROM {$msgTable} m
                LEFT JOIN {$evtTable} e  ON e.id  = m.event_id
                LEFT JOIN {$cgTable}  cg ON cg.id = m.connection_group_id
                WHERE {$where}
                ORDER BY {$sortExpr}";

        $rows = empty($args) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, ...$args));

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns the full conversation thread for an event and connection group,
     * oldest first so it renders naturally top-to-bottom.
     *
     * Includes both invitee messages and admin replies.
     *
     * @return self[]
     */
    public static function forEventGroup(int $eventId, int $groupId): array
    {
        global $wpdb;

        $table = DatabaseManager::eventMessagesTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE event_id = %d AND connection_group_id = %d
                 ORDER BY created_at ASC, id ASC",
                $eventId,
                $groupId
            )
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns per-group invitee-message counts for an event.
     *
     * Admin replies are excluded from both the total and unread counts since
     * they are the admin's own messages and need no follow-up read action.
     *
     * @param int $eventId
     * @return array<int, array{total:int, unread:int}> Keyed by connection_group_id.
     */
    public static function summaryForEvent(int $eventId): array
    {
        global $wpdb;

        $table = DatabaseManager::eventMessagesTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT connection_group_id,
                        COUNT(*)                                     AS total,
                        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
                 FROM {$table}
                 WHERE event_id = %d AND is_admin_reply = 0
                 GROUP BY connection_group_id",
                $eventId
            )
        );

        $result = [];
        foreach ($rows ?? [] as $row) {
            $result[(int) $row->connection_group_id] = [
                'total'  => (int) $row->total,
                'unread' => (int) $row->unread,
            ];
        }

        return $result;
    }

    // ─── Mutations ───────────────────────────────────────────────────────────

    /**
     * Inserts a new invitee message and returns its ID, or false on failure.
     */
    public static function create(int $eventId, int $groupId, string $message): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(
            DatabaseManager::eventMessagesTable(),
            [
                'event_id'            => $eventId,
                'connection_group_id' => $groupId,
                'message'             => $message,
                'is_read'             => 0,
                'is_admin_reply'      => 0,
            ],
            ['%d', '%d', '%s', '%d', '%d']
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Inserts an admin reply into the thread and returns its ID, or false on failure.
     *
     * Admin replies are always inserted with is_read=1 since the admin wrote them.
     */
    public static function createAdminReply(int $eventId, int $groupId, string $message): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(
            DatabaseManager::eventMessagesTable(),
            [
                'event_id'            => $eventId,
                'connection_group_id' => $groupId,
                'message'             => $message,
                'is_read'             => 1,
                'is_admin_reply'      => 1,
            ],
            ['%d', '%d', '%s', '%d', '%d']
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Marks all invitee messages in an event+group thread as read.
     *
     * Called automatically when an admin posts a reply, signalling they have
     * read all outstanding messages before responding.
     */
    public static function markThreadRead(int $eventId, int $groupId): void
    {
        global $wpdb;

        $wpdb->update(
            DatabaseManager::eventMessagesTable(),
            ['is_read' => 1],
            ['event_id' => $eventId, 'connection_group_id' => $groupId, 'is_admin_reply' => 0],
            ['%d'],
            ['%d', '%d', '%d']
        );
    }

    /**
     * Sets the is_read flag on a message.
     */
    public static function setRead(int $id, bool $isRead): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::eventMessagesTable(),
            ['is_read' => $isRead ? 1 : 0],
            ['id'      => $id],
            ['%d'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Deletes a single message by ID.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        return (bool) $wpdb->delete(
            DatabaseManager::eventMessagesTable(),
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Deletes all messages for a given event (used when deleting an event).
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;

        $wpdb->delete(
            DatabaseManager::eventMessagesTable(),
            ['event_id' => $eventId],
            ['%d']
        );
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private static function fromRow(object $row): self
    {
        return new self(
            id:                  (int)    $row->id,
            eventId:             (int)    $row->event_id,
            connectionGroupId:   (int)    $row->connection_group_id,
            message:             (string) ($row->message       ?? ''),
            isRead:              (bool)   ($row->is_read       ?? false),
            isAdminReply:        (bool)   ($row->is_admin_reply ?? false),
            createdAt:           (string) ($row->created_at    ?? ''),
            connectionGroupName: isset($row->connection_group_name) ? (string) $row->connection_group_name : null,
            eventName:           isset($row->event_name)            ? (string) $row->event_name            : null,
        );
    }
}
