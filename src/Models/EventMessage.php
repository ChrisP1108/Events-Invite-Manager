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
     * @param int    $id                Primary key.
     * @param int    $eventId           FK to eim_events.
     * @param int    $connectionGroupId FK to eim_invitee_connection_groups.
     * @param string $message           Message body text.
     * @param bool   $isRead            Whether an admin has marked this message as read.
     * @param string $createdAt         MySQL DATETIME when the message was submitted.
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $eventId,
        public readonly int    $connectionGroupId,
        public readonly string $message,
        public readonly bool   $isRead,
        public readonly string $createdAt,
    ) {}

    // ─── Queries ─────────────────────────────────────────────────────────────

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
            id:                (int)  $row->id,
            eventId:           (int)  $row->event_id,
            connectionGroupId: (int)  $row->connection_group_id,
            message:           (string) ($row->message ?? ''),
            isRead:            (bool) ($row->is_read ?? false),
            createdAt:         (string) ($row->created_at ?? ''),
        );
    }
}
