<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a managed newsletter tag (eim_newsletter_tags).
 */
final class NewsletterTag
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $createdAt,
    ) {}

    /**
     * Returns all tags ordered alphabetically.
     *
     * @return self[]
     */
    public static function all(): array
    {
        global $wpdb;

        $table = DatabaseManager::newsletterTagsTable();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Finds a single tag by primary key.
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::newsletterTagsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Inserts a new tag. Returns the new ID, or false if the name is blank
     * or a duplicate slug already exists.
     *
     * @param string $name
     * @return int|false
     */
    public static function create(string $name): int|false
    {
        global $wpdb;

        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $slug   = sanitize_title($name);
        $result = $wpdb->insert(
            DatabaseManager::newsletterTagsTable(),
            ['name' => $name, 'slug' => $slug]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Deletes a tag and removes it from all newsletter associations.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $wpdb->delete(DatabaseManager::newsletterTagMapTable(), ['tag_id' => $id]);

        return $wpdb->delete(DatabaseManager::newsletterTagsTable(), ['id' => $id]) !== false;
    }

    private static function fromRow(object $row): self
    {
        return new self(
            id:        (int) $row->id,
            name:           $row->name       ?? '',
            slug:           $row->slug       ?? '',
            createdAt:      $row->created_at ?? '',
        );
    }
}
