<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a message sent by an invitation group's connection group about a specific event.
 *
 * Messages are submitted by invitees via the Invitee Dashboard and visible to admins
 * on the event edit page under the Messages tab.
 */
final class EventMessage
{
    /**
     * @param int     $id                  Primary key.
     * @param int     $eventId             FK to eim_events.
     * @param int     $connectionGroupId   FK to eim_invitee_connection_groups.
     * @param string  $message             Message body text.
     * @param bool    $isRead              Whether an admin has marked this message as read.
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
        public readonly string  $createdAt,
        public readonly ?string $connectionGroupName = null,
        public readonly ?string $eventName           = null,
    ) {}

    // ─── Queries ─────────────────────────────────────────────────────────────

    /**
     * Returns all messages across all events for the global admin list.
     *
     * JOINs events and connection_groups to include their names for display and sorting.
     * Supports searching by event name, connection group name, or message body.
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

        $where = '1=1';
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
     * Returns all messages for an event and connection group, newest first.
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
                 ORDER BY created_at DESC",
                $eventId,
                $groupId
            )
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns per-group message counts for an event.
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
                        COUNT(*)                        AS total,
                        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
                 FROM {$table}
                 WHERE event_id = %d
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
     * Inserts a new message and returns its ID, or false on failure.
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
            ],
            ['%d', '%d', '%s', '%d']
        );

        return $result ? (int) $wpdb->insert_id : false;
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
            message:             (string) ($row->message    ?? ''),
            isRead:              (bool)   ($row->is_read    ?? false),
            createdAt:           (string) ($row->created_at ?? ''),
            connectionGroupName: isset($row->connection_group_name) ? (string) $row->connection_group_name : null,
            eventName:           isset($row->event_name)            ? (string) $row->event_name            : null,
        );
    }
}
