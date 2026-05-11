<?php

declare(strict_types=1);

namespace EventsInviteManager\Database;

if (!defined('ABSPATH')) exit;

final class DatabaseManager
{
    private const SCHEMA_VERSION = '10';

    private const EVENTS_TABLE                          = 'eim_events';
    private const INVITEES_TABLE                        = 'eim_invitees';
    private const EVENT_INVITEES_TABLE                  = 'eim_event_invitees';
    private const LOCATIONS_TABLE                       = 'eim_locations';
    private const EVENT_LODGING_TABLE                   = 'eim_event_lodging';
    private const QR_CODES_TABLE                        = 'eim_qr_codes';
    private const INVITEE_CONNECTION_GROUPS_TABLE       = 'eim_invitee_connection_groups';
    private const INVITEE_CONNECTION_GROUP_MEMBERS_TABLE = 'eim_invitee_connection_group_members';
    private const INVITATION_GROUPS_TABLE               = 'eim_event_invitation_groups';
    private const INVITATION_GROUP_MEMBERS_TABLE        = 'eim_event_invitation_group_members';

    public static function createTables(): void
    {
        global $wpdb;

        $storedVersion = (string) get_option('eim_db_version', '0');
        $charset       = $wpdb->get_charset_collate();

        $eventsTable        = $wpdb->prefix . self::EVENTS_TABLE;
        $inviteesTable      = $wpdb->prefix . self::INVITEES_TABLE;
        $eventInviteesTable = $wpdb->prefix . self::EVENT_INVITEES_TABLE;
        $locationsTable     = $wpdb->prefix . self::LOCATIONS_TABLE;
        $eventLodgingTable  = $wpdb->prefix . self::EVENT_LODGING_TABLE;
        $qrCodesTable       = $wpdb->prefix . self::QR_CODES_TABLE;
        $cgroupsTable       = $wpdb->prefix . self::INVITEE_CONNECTION_GROUPS_TABLE;
        $cgMembersTable     = $wpdb->prefix . self::INVITEE_CONNECTION_GROUP_MEMBERS_TABLE;
        $invGroupsTable     = $wpdb->prefix . self::INVITATION_GROUPS_TABLE;
        $invMembersTable    = $wpdb->prefix . self::INVITATION_GROUP_MEMBERS_TABLE;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
                first_name      VARCHAR(100)        NOT NULL,
                last_name       VARCHAR(100)        NOT NULL,
                email           VARCHAR(255)        NOT NULL,
                phone           VARCHAR(40)         NOT NULL DEFAULT '',
                street_address  VARCHAR(255)        NOT NULL DEFAULT '',
                city            VARCHAR(100)        NOT NULL DEFAULT '',
                state           VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code        VARCHAR(20)         NOT NULL DEFAULT '',
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY email (email)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$eventInviteesTable} (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id        BIGINT(20) UNSIGNED NOT NULL,
                invitee_id      BIGINT(20) UNSIGNED NOT NULL,
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_invitee (event_id, invitee_id),
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
                group_id          BIGINT(20) UNSIGNED NOT NULL,
                confirmation_code VARCHAR(16)         NOT NULL,
                qr_code_path      VARCHAR(500)        NOT NULL DEFAULT '',
                created_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY confirmation_code (confirmation_code),
                UNIQUE KEY group_id (group_id),
                KEY event_id (event_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$cgroupsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                type       VARCHAR(20)         NOT NULL DEFAULT 'custom',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY type (type)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$cgMembersTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id   BIGINT(20) UNSIGNED NOT NULL,
                invitee_id BIGINT(20) UNSIGNED NOT NULL,
                role       VARCHAR(100)        NOT NULL DEFAULT '',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY group_member (group_id, invitee_id),
                KEY group_id (group_id),
                KEY invitee_id (invitee_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$invGroupsTable} (
                id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id            BIGINT(20) UNSIGNED NOT NULL,
                primary_invitee_id  BIGINT(20) UNSIGNED NOT NULL,
                invite_sent_at      DATETIME,
                created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY primary_invitee_id (primary_invitee_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$invMembersTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id      BIGINT(20) UNSIGNED NOT NULL,
                invitee_id    BIGINT(20) UNSIGNED NOT NULL,
                rsvp_status   VARCHAR(10)         NOT NULL DEFAULT 'pending',
                registered_at DATETIME,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY group_member (group_id, invitee_id),
                KEY group_id (group_id),
                KEY invitee_id (invitee_id),
                KEY rsvp_status (rsvp_status)
            ) ENGINE=InnoDB {$charset};";

        dbDelta($sql);

        self::migrateToV10($storedVersion);
        update_option('eim_db_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string) get_option('eim_db_version', '0') === self::SCHEMA_VERSION) {
            return;
        }

        self::createTables();
    }

    /**
     * Migrates v9 → v10:
     *  1. Adds rsvp_status to eim_event_invitation_group_members from is_registered, then drops it.
     *  2. Migrates pairwise eim_invitee_connections rows into named connection groups.
     */
    private static function migrateToV10(string $storedVersion): void
    {
        if (version_compare($storedVersion, '10', '>=')) {
            return;
        }

        global $wpdb;

        $invMembersTable = self::invitationGroupMembersTable();
        $inviteesTable   = self::inviteesTable();
        $cgroupsTable    = self::inviteeConnectionGroupsTable();
        $cgMembersTable  = self::inviteeConnectionGroupMembersTable();
        $oldPairsTable   = $wpdb->prefix . 'eim_invitee_connections';

        // 1. Migrate is_registered → rsvp_status on event invitation group members.
        $hasIsRegistered = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM `{$invMembersTable}` LIKE %s", 'is_registered')
        );

        if ($hasIsRegistered) {
            // Add rsvp_status if not present yet (dbDelta may have already done this).
            $hasRsvp = $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM `{$invMembersTable}` LIKE %s", 'rsvp_status')
            );
            if (!$hasRsvp) {
                $wpdb->query(
                    "ALTER TABLE `{$invMembersTable}`
                     ADD COLUMN rsvp_status VARCHAR(10) NOT NULL DEFAULT 'pending' AFTER invitee_id"
                );
            }

            // Copy data: is_registered=1 → attending, 0 → pending.
            $wpdb->query(
                "UPDATE `{$invMembersTable}`
                 SET rsvp_status = CASE WHEN is_registered = 1 THEN 'attending' ELSE 'pending' END
                 WHERE rsvp_status = 'pending'"
            );

            // Drop the old column.
            $wpdb->query("ALTER TABLE `{$invMembersTable}` DROP COLUMN `is_registered`");
        }

        // 2. Migrate pairwise eim_invitee_connections → named connection groups.
        $pairsTableExists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $oldPairsTable)
        );

        if ($pairsTableExists) {
            $pairs = $wpdb->get_results("SELECT * FROM `{$oldPairsTable}` ORDER BY id ASC");

            foreach ($pairs ?? [] as $pair) {
                $id1 = (int) $pair->invitee_id_1;
                $id2 = (int) $pair->invitee_id_2;

                // Already migrated as a group containing both? Skip.
                $alreadyMigrated = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$cgMembersTable}` cgm1
                         INNER JOIN `{$cgMembersTable}` cgm2 ON cgm2.group_id = cgm1.group_id
                         WHERE cgm1.invitee_id = %d AND cgm2.invitee_id = %d",
                        $id1,
                        $id2
                    )
                );

                if ($alreadyMigrated > 0) {
                    continue;
                }

                // Build a "First Last & First Last" name.
                $i1 = $wpdb->get_row($wpdb->prepare(
                    "SELECT first_name, last_name FROM `{$inviteesTable}` WHERE id = %d LIMIT 1",
                    $id1
                ));
                $i2 = $wpdb->get_row($wpdb->prepare(
                    "SELECT first_name, last_name FROM `{$inviteesTable}` WHERE id = %d LIMIT 1",
                    $id2
                ));

                $name = trim(($i1->first_name ?? '') . ' ' . ($i1->last_name ?? ''))
                    . ' & '
                    . trim(($i2->first_name ?? '') . ' ' . ($i2->last_name ?? ''));

                $wpdb->insert($cgroupsTable, ['name' => $name, 'type' => 'couple']);
                $groupId = (int) $wpdb->insert_id;

                if ($groupId > 0) {
                    $wpdb->insert($cgMembersTable, ['group_id' => $groupId, 'invitee_id' => $id1]);
                    $wpdb->insert($cgMembersTable, ['group_id' => $groupId, 'invitee_id' => $id2]);
                }
            }

            $wpdb->query("DROP TABLE IF EXISTS `{$oldPairsTable}`");
        }
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

    /** @return string Fully-qualified event-invitee membership table name. */
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

    /** @return string Fully-qualified global connection groups table name. */
    public static function inviteeConnectionGroupsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::INVITEE_CONNECTION_GROUPS_TABLE;
    }

    /** @return string Fully-qualified global connection group members table name. */
    public static function inviteeConnectionGroupMembersTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::INVITEE_CONNECTION_GROUP_MEMBERS_TABLE;
    }

    /** @return string Fully-qualified event invitation groups table name. */
    public static function invitationGroupsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::INVITATION_GROUPS_TABLE;
    }

    /** @return string Fully-qualified event invitation group members table name. */
    public static function invitationGroupMembersTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::INVITATION_GROUP_MEMBERS_TABLE;
    }
}
