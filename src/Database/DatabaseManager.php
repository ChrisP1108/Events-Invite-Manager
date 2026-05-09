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
    /** @var string Current schema version stored in the WordPress options table. */
    private const SCHEMA_VERSION = '4';

    /** @var string Events table name suffix (without WP prefix). */
    private const EVENTS_TABLE = 'eim_events';

    /** @var string Invitees table name suffix (without WP prefix). */
    private const INVITEES_TABLE = 'eim_invitees';

    /** @var string Event-invitee pivot table name suffix (without WP prefix). */
    private const EVENT_INVITEES_TABLE = 'eim_event_invitees';

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
        $eventsTable         = $wpdb->prefix . self::EVENTS_TABLE;
        $inviteesTable       = $wpdb->prefix . self::INVITEES_TABLE;
        $eventInviteesTable  = $wpdb->prefix . self::EVENT_INVITEES_TABLE;
        $locationsTable      = $wpdb->prefix . self::LOCATIONS_TABLE;
        $libraryTable        = $wpdb->prefix . self::LOCATION_LIBRARY_TABLE;

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
                start_datetime              DATETIME,
                end_datetime                DATETIME,
                timezone                    VARCHAR(64)         NOT NULL DEFAULT '',
                lodging_enabled             TINYINT(1)          NOT NULL DEFAULT 0,
                max_invitees                SMALLINT UNSIGNED   NULL DEFAULT NULL,
                created_at                  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$inviteesTable} (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                first_name      VARCHAR(100)        NOT NULL,
                last_name       VARCHAR(100)        NOT NULL,
                email           VARCHAR(255)        NOT NULL,
                phone           VARCHAR(40)         NOT NULL DEFAULT '',
                street_address  VARCHAR(255)        NOT NULL DEFAULT '',
                city            VARCHAR(100)        NOT NULL DEFAULT '',
                state           VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code        VARCHAR(20)         NOT NULL DEFAULT '',
                invite_code     VARCHAR(64)         NOT NULL DEFAULT '',
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
            CREATE TABLE {$eventInviteesTable} (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id        BIGINT(20) UNSIGNED NOT NULL,
                invitee_id      BIGINT(20) UNSIGNED NOT NULL,
                invite_code     VARCHAR(64)         NOT NULL,
                is_registered   TINYINT(1)          NOT NULL DEFAULT 0,
                registered_at   DATETIME,
                invite_sent_at  DATETIME,
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_invitee (event_id, invitee_id),
                UNIQUE KEY invite_code (invite_code),
                KEY event_id (event_id),
                KEY invitee_id (invitee_id)
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

        self::migrateLegacyInviteeInvitations();
        self::migrateEventDateTimeColumns();
        update_option('eim_db_version', self::SCHEMA_VERSION, false);
    }

    /**
     * Runs schema creation when the stored schema version is behind the code version.
     *
     * Plugin updates do not trigger activation hooks, so this lightweight guard keeps
     * new tables and columns available after an ordinary plugin update.
     *
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        if ((string) get_option('eim_db_version', '0') === self::SCHEMA_VERSION) {
            return;
        }

        self::createTables();
    }

    /**
     * Copies legacy per-event invitee state into the new event-invitee table.
     *
     * Earlier versions stored event_id, invite_code, invite_sent_at, and registration
     * status directly on invitee rows. The refactored schema keeps invitee profile
     * details global and stores event-specific invitation state in a pivot table.
     *
     * @return void
     */
    private static function migrateLegacyInviteeInvitations(): void
    {
        global $wpdb;

        $eventsTable        = self::eventsTable();
        $inviteesTable      = self::inviteesTable();
        $eventInviteesTable = self::eventInviteesTable();

        $rows = $wpdb->get_results(
            "SELECT i.id AS invitee_id,
                    i.event_id,
                    i.invite_code,
                    i.is_registered,
                    i.registered_at,
                    i.invite_sent_at
             FROM {$inviteesTable} i
             INNER JOIN {$eventsTable} e ON e.id = i.event_id
             WHERE i.event_id > 0"
        );

        foreach ($rows ?? [] as $row) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$eventInviteesTable} WHERE event_id = %d AND invitee_id = %d",
                    (int) $row->event_id,
                    (int) $row->invitee_id
                )
            );

            if ($exists > 0) {
                continue;
            }

            $wpdb->insert($eventInviteesTable, [
                'event_id'       => (int) $row->event_id,
                'invitee_id'     => (int) $row->invitee_id,
                'invite_code'    => $row->invite_code ?: self::generateInviteCode(),
                'is_registered'  => (int) ($row->is_registered ?? 0),
                'registered_at'  => $row->registered_at ?: null,
                'invite_sent_at' => $row->invite_sent_at ?: null,
            ]);
        }
    }

    /**
     * Populates start_datetime and end_datetime from the legacy event_date / start_time / end_time
     * columns for any event rows that pre-date the new schema.
     *
     * Safe to call repeatedly — only touches rows where start_datetime is still NULL.
     *
     * @return void
     */
    private static function migrateEventDateTimeColumns(): void
    {
        global $wpdb;

        $table = self::eventsTable();

        // Combine event_date + start_time (or midnight) → start_datetime.
        $wpdb->query(
            "UPDATE {$table}
             SET start_datetime = CASE
                 WHEN start_time IS NOT NULL THEN CONCAT(event_date, ' ', start_time)
                 ELSE CONCAT(event_date, ' 00:00:00')
             END
             WHERE event_date IS NOT NULL AND start_datetime IS NULL"
        );

        // Combine event_date + end_time → end_datetime (only when end_time was set).
        $wpdb->query(
            "UPDATE {$table}
             SET end_datetime = CONCAT(event_date, ' ', end_time)
             WHERE event_date IS NOT NULL AND end_time IS NOT NULL AND end_datetime IS NULL"
        );
    }

    /**
     * Generates a cryptographically secure fallback invite code for migrations.
     *
     * @return string
     */
    private static function generateInviteCode(): string
    {
        return bin2hex(random_bytes(16));
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
     * Returns the fully-qualified event-invitee table name including the WordPress prefix.
     *
     * @return string
     */
    public static function eventInviteesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENT_INVITEES_TABLE;
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
