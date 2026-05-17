<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a managed newsletter category (eim_newsletter_categories).
 */
final class NewsletterCategory
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $createdAt,
    ) {}

    /**
     * Returns all categories ordered alphabetically.
     *
     * @return self[]
     */
    public static function all(): array
    {
        global $wpdb;

        $table = DatabaseManager::newsletterCategoriesTable();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Finds a single category by primary key.
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::newsletterCategoriesTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Inserts a new category. Returns the new ID, or false if the name is blank
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
            DatabaseManager::newsletterCategoriesTable(),
            ['name' => $name, 'slug' => $slug]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Deletes a category and removes it from all newsletter associations.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $wpdb->delete(DatabaseManager::newsletterCategoryMapTable(), ['category_id' => $id]);

        return $wpdb->delete(DatabaseManager::newsletterCategoriesTable(), ['id' => $id]) !== false;
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
