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
    private const SCHEMA_VERSION = '8';

    /** @var string Events table name suffix (without WP prefix). */
    private const EVENTS_TABLE = 'eim_events';

    /** @var string Invitees table name suffix (without WP prefix). */
    private const INVITEES_TABLE = 'eim_invitees';

    /** @var string Event-invitee pivot table name suffix (without WP prefix). */
    private const EVENT_INVITEES_TABLE = 'eim_event_invitees';

    /**
     * Global location catalogue table (replaces legacy eim_location_library).
     * No event_id — events reference this table via venue_id and the
     * eim_event_lodging pivot table.
     *
     * @var string
     */
    private const LOCATIONS_TABLE = 'eim_locations';

    /** @var string Per-event lodging pivot table name suffix (without WP prefix). */
    private const EVENT_LODGING_TABLE = 'eim_event_lodging';

    /** @var string QR codes table name suffix (without WP prefix). */
    private const QR_CODES_TABLE = 'eim_qr_codes';

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

        $storedSchemaVersion = (string) get_option('eim_db_version', '0');
        $charset             = $wpdb->get_charset_collate();
        $eventsTable         = $wpdb->prefix . self::EVENTS_TABLE;
        $inviteesTable       = $wpdb->prefix . self::INVITEES_TABLE;
        $eventInviteesTable  = $wpdb->prefix . self::EVENT_INVITEES_TABLE;
        $locationsTable      = $wpdb->prefix . self::LOCATIONS_TABLE;
        $eventLodgingTable   = $wpdb->prefix . self::EVENT_LODGING_TABLE;
        $qrCodesTable        = $wpdb->prefix . self::QR_CODES_TABLE;

        // Migration: rename legacy eim_lodging_locations table if it exists and the new one doesn't.
        $oldLodgingTable = $wpdb->prefix . 'eim_lodging_locations';
        if (
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $oldLodgingTable))
            && !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $locationsTable))
        ) {
            $wpdb->query("RENAME TABLE `{$oldLodgingTable}` TO `{$locationsTable}`");
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // eim_locations is the global location catalogue (no event_id).
        // For existing installs the migration below will rename the old per-event
        // eim_locations away and replace it with eim_location_library.
        // For fresh installs dbDelta creates this table with the correct schema.
        $sql = "CREATE TABLE {$eventsTable} (
                id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name                   VARCHAR(255)        NOT NULL,
                description            TEXT,
                invite_email_subject   VARCHAR(255)        NOT NULL DEFAULT '',
                invite_email_template  LONGTEXT,
                from_name              VARCHAR(255)        NOT NULL DEFAULT '',
                from_email             VARCHAR(255)        NOT NULL DEFAULT '',
                rsvp_page_id           BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                venue_id               BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                start_datetime         DATETIME,
                end_datetime           DATETIME,
                timezone               VARCHAR(64)         NOT NULL DEFAULT '',
                lodging_enabled        TINYINT(1)          NOT NULL DEFAULT 0,
                max_invitees           SMALLINT UNSIGNED   NULL DEFAULT NULL,
                created_at             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
                name           VARCHAR(255)        NOT NULL,
                street_address VARCHAR(255)        NOT NULL DEFAULT '',
                city           VARCHAR(100)        NOT NULL DEFAULT '',
                state          VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code       VARCHAR(20)         NOT NULL DEFAULT '',
                is_other       TINYINT(1)          NOT NULL DEFAULT 0,
                has_lodging    TINYINT(1)          NOT NULL DEFAULT 0,
                booking_url    VARCHAR(500)        NOT NULL DEFAULT '',
                created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$eventLodgingTable} (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id    BIGINT(20) UNSIGNED NOT NULL,
                location_id BIGINT(20) UNSIGNED NOT NULL,
                sort_order  INT                 NOT NULL DEFAULT 0,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_location (event_id, location_id),
                KEY event_id (event_id),
                KEY location_id (location_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$qrCodesTable} (
                id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id          BIGINT(20) UNSIGNED NOT NULL,
                invitee_id        BIGINT(20) UNSIGNED NOT NULL,
                confirmation_code VARCHAR(16)         NOT NULL,
                qr_code_path      VARCHAR(500)        NOT NULL DEFAULT '',
                created_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY confirmation_code (confirmation_code),
                UNIQUE KEY event_invitee (event_id, invitee_id),
                KEY event_id (event_id),
                KEY invitee_id (invitee_id)
            ) ENGINE=InnoDB {$charset};";

        dbDelta($sql);

        self::migrateLegacyInviteeInvitations();
        self::migrateLocationsToUnifiedSchema();
        self::migrateEventDatetimesToUtc($storedSchemaVersion);
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
     * Consolidates the legacy two-table location system into a single global catalogue.
     *
     * Before this migration:
     *   eim_location_library  — global catalogue (name, address, has_lodging, booking_url)
     *   eim_locations         — per-event assignments (event_id, type, name, address)
     *
     * After this migration:
     *   eim_locations         — global catalogue (library schema, no event_id)
     *   eim_event_lodging     — per-event lodging pivot (event_id, location_id)
     *   eim_events.venue_id   — FK to eim_locations for the event venue
     *
     * Migration is skipped when eim_location_library no longer exists (already migrated
     * or a fresh install that never had the legacy schema).
     *
     * @return void
     */
    private static function migrateLocationsToUnifiedSchema(): void
    {
        global $wpdb;

        $library          = $wpdb->prefix . 'eim_location_library';
        $locationsTable   = self::locationsTable();
        $legacyTable      = $wpdb->prefix . 'eim_locations_old';
        $eventLodging     = self::eventLodgingTable();
        $eventsTable      = self::eventsTable();

        // Nothing to do when library doesn't exist (fresh install or already migrated).
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $library))) {
            // Clean up any leftover legacy table from a partially-completed migration.
            $wpdb->query("DROP TABLE IF EXISTS `{$legacyTable}`");
            return;
        }

        // Step 1 — move the old per-event eim_locations out of the way.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $locationsTable))) {
            $wpdb->query("RENAME TABLE `{$locationsTable}` TO `{$legacyTable}`");
        }

        // Step 2 — the library becomes the new global catalogue.
        $wpdb->query("RENAME TABLE `{$library}` TO `{$locationsTable}`");

        // Step 3 — migrate venue and lodging rows from the legacy per-event table.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacyTable))) {

            // Venue rows → set venue_id on the parent event.
            $venues = $wpdb->get_results("SELECT * FROM `{$legacyTable}` WHERE type = 'venue'");
            foreach ($venues ?? [] as $row) {
                $locationId = self::findOrCreateLocation($locationsTable, $row, false);
                if ($locationId > 0 && (int) $row->event_id > 0) {
                    $wpdb->update($eventsTable, ['venue_id' => $locationId], ['id' => (int) $row->event_id]);
                }
            }

            // Lodging rows → insert into the event_lodging pivot.
            $lodgings = $wpdb->get_results("SELECT * FROM `{$legacyTable}` WHERE type = 'lodging'");
            foreach ($lodgings ?? [] as $row) {
                $locationId = self::findOrCreateLocation($locationsTable, $row, true);
                if ($locationId > 0 && (int) $row->event_id > 0) {
                    $existing = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$eventLodging}` WHERE event_id = %d AND location_id = %d",
                            (int) $row->event_id,
                            $locationId
                        )
                    );
                    if ($existing === 0) {
                        $wpdb->insert($eventLodging, [
                            'event_id'    => (int) $row->event_id,
                            'location_id' => $locationId,
                            'sort_order'  => (int) ($row->sort_order ?? 0),
                        ]);
                    }
                }
            }

            $wpdb->query("DROP TABLE IF EXISTS `{$legacyTable}`");
        }
    }

    /**
     * Finds a location in the catalogue by name or inserts one from legacy row data.
     *
     * @param string   $table      Fully-qualified eim_locations table name.
     * @param object   $legacyRow  Row from the old eim_locations table.
     * @param bool     $hasLodging Whether to mark a newly-created row as having lodging.
     * @return int Location ID, or 0 on failure.
     */
    private static function findOrCreateLocation(string $table, object $legacyRow, bool $hasLodging): int
    {
        global $wpdb;

        $id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE name = %s LIMIT 1", $legacyRow->name)
        );

        if ($id > 0) {
            return $id;
        }

        $isOther = (bool) ($legacyRow->is_other ?? false);

        $wpdb->insert($table, [
            'name'           => $legacyRow->name,
            'street_address' => $isOther ? '' : ($legacyRow->street_address ?? ''),
            'city'           => $isOther ? '' : ($legacyRow->city           ?? ''),
            'state'          => $isOther ? '' : ($legacyRow->state          ?? ''),
            'zip_code'       => $isOther ? '' : ($legacyRow->zip_code       ?? ''),
            'is_other'       => $isOther ? 1 : 0,
            'has_lodging'    => $hasLodging ? 1 : 0,
            'booking_url'    => '',
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Converts start_datetime and end_datetime from local time to UTC for events that have
     * a timezone set but were saved before the UTC-storage requirement was introduced.
     *
     * Gated on the stored schema version being below 8 so that re-activation, manual
     * createTables() calls, or future schema bumps never double-convert event times.
     * Events with no timezone set cannot be converted and are left unchanged.
     *
     * @param string $storedSchemaVersion Schema version recorded before createTables() began.
     * @return void
     */
    private static function migrateEventDatetimesToUtc(string $storedSchemaVersion): void
    {
        // Only run when upgrading from a schema that pre-dates UTC storage (< v8).
        if (version_compare($storedSchemaVersion, '8', '>=')) {
            return;
        }

        global $wpdb;

        $table = self::eventsTable();
        $rows  = $wpdb->get_results(
            "SELECT id, start_datetime, end_datetime, timezone FROM {$table} WHERE timezone != '' AND timezone IS NOT NULL"
        );

        foreach ($rows ?? [] as $row) {
            $updates = [];

            foreach (['start_datetime' => $row->start_datetime, 'end_datetime' => $row->end_datetime] as $col => $value) {
                if (empty($value) || $value === '0000-00-00 00:00:00') {
                    continue;
                }

                // Values from schemas before v8 were stored in the event's local timezone.
                try {
                    $dt = new \DateTime($value, new \DateTimeZone($row->timezone));
                    $dt->setTimezone(new \DateTimeZone('UTC'));
                    $updates[$col] = $dt->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    // Invalid timezone or datetime — leave unchanged.
                }
            }

            if (!empty($updates)) {
                $wpdb->update($table, $updates, ['id' => (int) $row->id]);
            }
        }
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

    /** @return string Fully-qualified events table name. */
    public static function eventsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENTS_TABLE;
    }

    /** @return string Fully-qualified invitees table name. */
    public static function inviteesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::INVITEES_TABLE;
    }

    /** @return string Fully-qualified event-invitee pivot table name. */
    public static function eventInviteesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENT_INVITEES_TABLE;
    }

    /** @return string Fully-qualified locations catalogue table name. */
    public static function locationsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::LOCATIONS_TABLE;
    }

    /** @return string Fully-qualified event-lodging pivot table name. */
    public static function eventLodgingTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENT_LODGING_TABLE;
    }

    /** @return string Fully-qualified QR codes table name. */
    public static function qrCodesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::QR_CODES_TABLE;
    }
}
