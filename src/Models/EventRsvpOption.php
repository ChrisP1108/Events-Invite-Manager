<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a single food or beverage option attached to an event.
 *
 * Options are event-level. Invitee selections are stored on
 * eim_event_invitation_group_members (food_option_id, beverage_option_id).
 */
final class EventRsvpOption
{
    public const TYPE_FOOD     = 'food';
    public const TYPE_BEVERAGE = 'beverage';

    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly string  $type,
        public readonly string  $label,
        public readonly string  $description,
        public readonly int     $sortOrder,
        public readonly bool    $isActive,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    public static function find(int $id): ?self
    {
        global $wpdb;
        $table = DatabaseManager::eventRsvpOptionsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns all options for an event ordered by type then sort_order (admin use).
     *
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;
        $table = DatabaseManager::eventRsvpOptionsTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d ORDER BY type ASC, sort_order ASC, id ASC",
                $eventId
            )
        );
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns active options for a specific type (used by the public RSVP API).
     *
     * @return self[]
     */
    public static function forEventByType(int $eventId, string $type): array
    {
        global $wpdb;
        $table = DatabaseManager::eventRsvpOptionsTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND type = %s AND is_active = 1 ORDER BY sort_order ASC, id ASC",
                $eventId,
                $type
            )
        );
        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    public static function create(array $data): int|false
    {
        global $wpdb;

        $type = in_array($data['type'] ?? '', [self::TYPE_FOOD, self::TYPE_BEVERAGE], true)
            ? $data['type']
            : self::TYPE_FOOD;

        $result = $wpdb->insert(DatabaseManager::eventRsvpOptionsTable(), [
            'event_id'    => (int)    ($data['event_id']    ?? 0),
            'type'        =>           $type,
            'label'       => (string) ($data['label']       ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'sort_order'  => (int)    ($data['sort_order']  ?? 0),
            'is_active'   => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = [];
        if (isset($data['label']))       $fields['label']       = (string) $data['label'];
        if (isset($data['description'])) $fields['description'] = (string) $data['description'];
        if (isset($data['sort_order']))  $fields['sort_order']  = (int)    $data['sort_order'];
        if (isset($data['is_active']))   $fields['is_active']   = (int)    $data['is_active'];

        if (empty($fields)) {
            return true;
        }

        return $wpdb->update(DatabaseManager::eventRsvpOptionsTable(), $fields, ['id' => $id]) !== false;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete(DatabaseManager::eventRsvpOptionsTable(), ['id' => $id]) !== false;
    }

    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::eventRsvpOptionsTable(), ['event_id' => $eventId]);
    }

    private static function fromRow(object $row): self
    {
        return new self(
            id:          (int)  $row->id,
            eventId:     (int)  $row->event_id,
            type:               $row->type        ?? self::TYPE_FOOD,
            label:              $row->label       ?? '',
            description:        $row->description ?? '',
            sortOrder:   (int)  ($row->sort_order ?? 0),
            isActive:    (bool) ($row->is_active  ?? true),
            createdAt:          $row->created_at  ?? '',
            updatedAt:          $row->updated_at  ?? '',
        );
    }
}
