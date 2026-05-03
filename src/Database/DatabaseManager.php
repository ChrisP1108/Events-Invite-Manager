<?php

declare(strict_types=1);

namespace EventsInviteManager\Database;

if (!defined('ABSPATH')) exit;

/**
 * Manages database table creation and schema for the plugin.
 *
 * All methods are static so they can be called from the activation hook
 * in the root plugin file without needing an instantiated Plugin object.
 * Uses dbDelta for idempotent table creation — safe to call on re-activation.
 */
final class DatabaseManager
{
    /** @var string Events table name suffix (without WP prefix). */
    private const EVENTS_TABLE = 'eim_events';

    /** @var string Invitees table name suffix (without WP prefix). */
    private const INVITEES_TABLE = 'eim_invitees';

    /** @var string Locations table name suffix (without WP prefix). */
    private const LOCATIONS_TABLE = 'eim_locations';

    /** @var string Location library table name suffix (without WP prefix). */
    private const LOCATION_LIBRARY_TABLE = 'eim_location_library';

    /**
     * Creates or updates the plugin's database tables via dbDelta.
     *
     * Safe to call multiple times — dbDelta only applies changes that differ
     * from the current schema, so re-activation will not destroy existing data.
     *
     * @return void
     */
    public static function createTables(): void
    {
        global $wpdb;

        $charset        = $wpdb->get_charset_collate();
        $eventsTable    = $wpdb->prefix . self::EVENTS_TABLE;
        $inviteesTable  = $wpdb->prefix . self::INVITEES_TABLE;
        $locationsTable = $wpdb->prefix . self::LOCATIONS_TABLE;
        $libraryTable   = $wpdb->prefix . self::LOCATION_LIBRARY_TABLE;

        // Migration: rename legacy eim_lodging_locations table if it exists and the new one doesn't.
        $oldTable = $wpdb->prefix . 'eim_lodging_locations';
        if (
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $oldTable))
            && !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $locationsTable))
        ) {
            $wpdb->query("RENAME TABLE `{$oldTable}` TO `{$locationsTable}`");
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$eventsTable} (
                id                          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name                        VARCHAR(255)        NOT NULL,
                description                 TEXT,
                rsvp_page_url               VARCHAR(500)        NOT NULL DEFAULT '',
                invite_email_subject        VARCHAR(255)        NOT NULL DEFAULT '',
                invite_email_template       LONGTEXT,
                confirmation_email_subject  VARCHAR(255)        NOT NULL DEFAULT '',
                confirmation_email_template LONGTEXT,
                from_name                   VARCHAR(255)        NOT NULL DEFAULT '',
                from_email                  VARCHAR(255)        NOT NULL DEFAULT '',
                event_date                  DATE,
                start_time                  TIME,
                end_time                    TIME,
                lodging_enabled             TINYINT(1)          NOT NULL DEFAULT 0,
                created_at                  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$inviteesTable} (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id        BIGINT(20) UNSIGNED NOT NULL,
                first_name      VARCHAR(100)        NOT NULL,
                last_name       VARCHAR(100)        NOT NULL,
                email           VARCHAR(255)        NOT NULL,
                street_address  VARCHAR(255)        NOT NULL DEFAULT '',
                city            VARCHAR(100)        NOT NULL DEFAULT '',
                state           VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code        VARCHAR(20)         NOT NULL DEFAULT '',
                invite_code     VARCHAR(64)         NOT NULL,
                is_registered   TINYINT(1)          NOT NULL DEFAULT 0,
                registered_at   DATETIME,
                invite_sent_at  DATETIME,
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY invite_code (invite_code),
                KEY event_id (event_id),
                KEY email_event (email, event_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$locationsTable} (
                id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id       BIGINT(20) UNSIGNED NOT NULL,
                type           VARCHAR(20)         NOT NULL DEFAULT 'lodging',
                name           VARCHAR(255)        NOT NULL,
                street_address VARCHAR(255)        NOT NULL DEFAULT '',
                city           VARCHAR(100)        NOT NULL DEFAULT '',
                state          VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code       VARCHAR(20)         NOT NULL DEFAULT '',
                is_other       TINYINT(1)          NOT NULL DEFAULT 0,
                sort_order     INT                 NOT NULL DEFAULT 0,
                created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY event_type (event_id, type)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$libraryTable} (
                id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name           VARCHAR(255)        NOT NULL,
                street_address VARCHAR(255)        NOT NULL DEFAULT '',
                city           VARCHAR(100)        NOT NULL DEFAULT '',
                state          VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code       VARCHAR(20)         NOT NULL DEFAULT '',
                is_other       TINYINT(1)          NOT NULL DEFAULT 0,
                created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB {$charset};";

        dbDelta($sql);
    }

    /**
     * Returns the fully-qualified events table name including the WordPress prefix.
     *
     * @return string
     */
    public static function eventsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENTS_TABLE;
    }

    /**
     * Returns the fully-qualified invitees table name including the WordPress prefix.
     *
     * @return string
     */
    public static function inviteesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::INVITEES_TABLE;
    }

    /**
     * Returns the fully-qualified locations table name including the WordPress prefix.
     *
     * @return string
     */
    public static function locationsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::LOCATIONS_TABLE;
    }

    /**
     * Returns the fully-qualified location library table name including the WordPress prefix.
     *
     * @return string
     */
    public static function locationLibraryTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::LOCATION_LIBRARY_TABLE;
    }
}
