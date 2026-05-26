<?php

declare(strict_types=1);

namespace EventsInviteManager\Database;

if (!defined('ABSPATH')) exit;

/**
 * Handles database interactions for the plugin.
 * All tables are prefixed with `eim_`.
 *
 * @package EventsInviteManager
 */

final class DatabaseManager
{
    /**
     * The current database schema version.
     */
    private const SCHEMA_VERSION = '36';

    /**
     * The database table names.
     */
    private const EVENTS_TABLE                           = 'eim_events';
    private const INVITEES_TABLE                         = 'eim_invitees';
    private const EVENT_INVITEES_TABLE                   = 'eim_event_invitees';
    private const LOCATIONS_TABLE                        = 'eim_locations';
    private const EVENT_LODGING_TABLE                    = 'eim_event_lodging';
    private const QR_CODES_TABLE                         = 'eim_qr_codes';
    private const INVITEE_CONNECTION_GROUPS_TABLE        = 'eim_invitee_connection_groups';
    private const INVITEE_CONNECTION_GROUP_MEMBERS_TABLE = 'eim_invitee_connection_group_members';
    private const INVITATION_GROUPS_TABLE                = 'eim_event_invitation_groups';
    private const INVITATION_GROUP_MEMBERS_TABLE         = 'eim_event_invitation_group_members';
    private const MENU_ITEMS_TABLE                       = 'eim_menu_items';
    private const EVENT_MENU_ITEMS_TABLE                 = 'eim_event_menu_items';
    private const BUDGET_PLANS_TABLE                     = 'eim_budget_plans';
    private const BUDGET_PLAN_EVENTS_TABLE               = 'eim_budget_plan_events';
    private const BUDGET_LINE_ITEMS_TABLE                = 'eim_budget_line_items';
    private const NEWSLETTERS_TABLE                      = 'eim_newsletters';
    private const NEWSLETTER_EVENTS_TABLE                = 'eim_newsletter_events';
    private const NEWSLETTER_TAGS_TABLE                  = 'eim_newsletter_tags';
    private const NEWSLETTER_TAG_MAP_TABLE               = 'eim_newsletter_tag_map';
    private const VENDORS_TABLE                          = 'eim_vendors';
    private const CATEGORIES_TABLE                       = 'eim_categories';
    private const CATEGORY_MAP_TABLE                     = 'eim_category_map';
    private const GIFTS_TABLE                            = 'eim_gifts';
    private const GIFT_EVENTS_TABLE                      = 'eim_gift_events';
    private const GIFT_PURCHASES_TABLE                   = 'eim_gift_purchases';
    private const EVENT_MESSAGES_TABLE                   = 'eim_event_messages';
    private const REQUESTED_INVITEE_ADD_ONS_TABLE        = 'eim_requested_invitee_add_ons';

    /**
     * Upgrades the database schema if necessary.
     *
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        if (get_option('eim_db_version') === self::SCHEMA_VERSION) {
            return;
        }
        self::createTables();
        update_option('eim_db_version', self::SCHEMA_VERSION, false);
    }

    /**
     * Creates all database tables.
     *
     * @return void
     */
    public static function createTables(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $eventsTable                = $wpdb->prefix . self::EVENTS_TABLE;
        $inviteesTable              = $wpdb->prefix . self::INVITEES_TABLE;
        $eventInviteesTable         = $wpdb->prefix . self::EVENT_INVITEES_TABLE;
        $locationsTable             = $wpdb->prefix . self::LOCATIONS_TABLE;
        $eventLodgingTable          = $wpdb->prefix . self::EVENT_LODGING_TABLE;
        $qrCodesTable               = $wpdb->prefix . self::QR_CODES_TABLE;
        $cgroupsTable               = $wpdb->prefix . self::INVITEE_CONNECTION_GROUPS_TABLE;
        $cgMembersTable             = $wpdb->prefix . self::INVITEE_CONNECTION_GROUP_MEMBERS_TABLE;
        $invGroupsTable             = $wpdb->prefix . self::INVITATION_GROUPS_TABLE;
        $invMembersTable            = $wpdb->prefix . self::INVITATION_GROUP_MEMBERS_TABLE;
        $menuItemsTable             = $wpdb->prefix . self::MENU_ITEMS_TABLE;
        $eventMenuItemsTable        = $wpdb->prefix . self::EVENT_MENU_ITEMS_TABLE;
        $budgetPlansTable           = $wpdb->prefix . self::BUDGET_PLANS_TABLE;
        $budgetPlanEventsTable      = $wpdb->prefix . self::BUDGET_PLAN_EVENTS_TABLE;
        $budgetLineItemsTable       = $wpdb->prefix . self::BUDGET_LINE_ITEMS_TABLE;
        $newslettersTable           = $wpdb->prefix . self::NEWSLETTERS_TABLE;
        $newsletterEventsTable      = $wpdb->prefix . self::NEWSLETTER_EVENTS_TABLE;
        $newsletterTagsTable        = $wpdb->prefix . self::NEWSLETTER_TAGS_TABLE;
        $newsletterTagMapTable      = $wpdb->prefix . self::NEWSLETTER_TAG_MAP_TABLE;
        $vendorsTable               = $wpdb->prefix . self::VENDORS_TABLE;
        $categoriesTable            = $wpdb->prefix . self::CATEGORIES_TABLE;
        $categoryMapTable           = $wpdb->prefix . self::CATEGORY_MAP_TABLE;
        $giftsTable                 = $wpdb->prefix . self::GIFTS_TABLE;
        $giftEventsTable            = $wpdb->prefix . self::GIFT_EVENTS_TABLE;
        $giftPurchasesTable         = $wpdb->prefix . self::GIFT_PURCHASES_TABLE;
        $eventMessagesTable              = $wpdb->prefix . self::EVENT_MESSAGES_TABLE;
        $requestedInviteeAddOnsTable     = $wpdb->prefix . self::REQUESTED_INVITEE_ADD_ONS_TABLE;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$eventsTable} (
                id                        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name                      VARCHAR(255)        NOT NULL,
                description               TEXT,
                invite_email_subject      VARCHAR(255)        NOT NULL DEFAULT '',
                invite_email_template     LONGTEXT,
                from_name                 VARCHAR(255)        NOT NULL DEFAULT '',
                from_email                VARCHAR(255)        NOT NULL DEFAULT '',
                rsvp_page_id              BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                venue_id                  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                start_datetime            DATETIME,
                end_datetime              DATETIME,
                timezone                  VARCHAR(64)         NOT NULL DEFAULT '',
                lodging_enabled           TINYINT(1)          NOT NULL DEFAULT 0,
                food_options_enabled      TINYINT(1)          NOT NULL DEFAULT 0,
                beverage_options_enabled  TINYINT(1)          NOT NULL DEFAULT 0,
                newsletter_page_id        BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                dashboard_page_id         BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                max_invitees              SMALLINT UNSIGNED   NULL DEFAULT NULL,
                rsvp_deadline             DATETIME            NULL DEFAULT NULL,
                created_at                DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
                image_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY email (email),
                KEY image_attachment_id (image_attachment_id)
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
                id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id              BIGINT(20) UNSIGNED NOT NULL,
                primary_invitee_id    BIGINT(20) UNSIGNED NOT NULL,
                invite_sent_at        DATETIME,
                rsvp_notes            TEXT,
                rsvp_notes_updated_at DATETIME            NULL DEFAULT NULL,
                lodging_booked        TINYINT(1)          NOT NULL DEFAULT 0,
                lodging_booked_at     DATETIME            NULL DEFAULT NULL,
                lodging_notes         TEXT,
                created_at            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY primary_invitee_id (primary_invitee_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$invMembersTable} (
                id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id           BIGINT(20) UNSIGNED NOT NULL,
                invitee_id         BIGINT(20) UNSIGNED NOT NULL,
                rsvp_status        VARCHAR(10)         NOT NULL DEFAULT 'pending',
                registered_at      DATETIME,
                food_option_id        BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                beverage_option_id    BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                dietary_notes         VARCHAR(500)        NOT NULL DEFAULT '',
                food_confirmed_at     DATETIME            NULL DEFAULT NULL,
                beverage_confirmed_at DATETIME            NULL DEFAULT NULL,
                lodging_id            BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                lodging_is_other      TINYINT(1)          NOT NULL DEFAULT 0,
                lodging_undisclosed   TINYINT(1)          NOT NULL DEFAULT 0,
                lodging_confirmed_at  DATETIME            NULL DEFAULT NULL,
                seat_assignment       VARCHAR(255)        NOT NULL DEFAULT '',
                created_at            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY group_member (group_id, invitee_id),
                KEY group_id (group_id),
                KEY invitee_id (invitee_id),
                KEY rsvp_status (rsvp_status)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$menuItemsTable} (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                type        VARCHAR(10)         NOT NULL DEFAULT 'food',
                label       VARCHAR(255)        NOT NULL DEFAULT '',
                description TEXT,
                price_cents INT UNSIGNED        NOT NULL DEFAULT 0,
                vendor_id   BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                sort_order  INT                 NOT NULL DEFAULT 0,
                is_active   TINYINT(1)          NOT NULL DEFAULT 1,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY type (type),
                KEY vendor_id (vendor_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$eventMenuItemsTable} (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id     BIGINT(20) UNSIGNED NOT NULL,
                menu_item_id BIGINT(20) UNSIGNED NOT NULL,
                sort_order   INT                 NOT NULL DEFAULT 0,
                created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_menu_item (event_id, menu_item_id),
                KEY event_id (event_id),
                KEY menu_item_id (menu_item_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$budgetPlansTable} (
                id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name                VARCHAR(255)        NOT NULL DEFAULT '',
                description         TEXT,
                target_amount_cents INT UNSIGNED        NOT NULL DEFAULT 0,
                currency            VARCHAR(3)          NOT NULL DEFAULT 'USD',
                created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$budgetPlanEventsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id    BIGINT(20) UNSIGNED NOT NULL,
                event_id   BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY plan_event (plan_id, event_id),
                KEY plan_id (plan_id),
                KEY event_id (event_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$budgetLineItemsTable} (
                id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id              BIGINT(20) UNSIGNED NOT NULL,
                event_id             BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                vendor_id            BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                label                VARCHAR(255)        NOT NULL DEFAULT '',
                source_type          VARCHAR(20)         NULL DEFAULT NULL,
                source_id            BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                quantity             DECIMAL(10,2)       NOT NULL DEFAULT 1,
                quantity_mode        VARCHAR(15)         NOT NULL DEFAULT 'fixed',
                unit_cost_cents      INT UNSIGNED        NOT NULL DEFAULT 0,
                total_override_cents INT UNSIGNED        NULL DEFAULT NULL,
                paid_amount_cents    INT UNSIGNED        NOT NULL DEFAULT 0,
                website_url          VARCHAR(2000)       NOT NULL DEFAULT '',
                payment_deadline     DATETIME            NULL DEFAULT NULL,
                notes                TEXT,
                image_attachment_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                sort_order           INT                 NOT NULL DEFAULT 0,
                created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY plan_id (plan_id),
                KEY event_id (event_id),
                KEY vendor_id (vendor_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$vendorsTable} (
                id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                company_name   VARCHAR(255)        NOT NULL DEFAULT '',
                contact_name   VARCHAR(255)        NOT NULL DEFAULT '',
                street_address VARCHAR(255)        NOT NULL DEFAULT '',
                city           VARCHAR(100)        NOT NULL DEFAULT '',
                state          VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code       VARCHAR(20)         NOT NULL DEFAULT '',
                email          VARCHAR(255)        NOT NULL DEFAULT '',
                phone          VARCHAR(40)         NOT NULL DEFAULT '',
                website_url    VARCHAR(255)        NOT NULL DEFAULT '',
                notes          TEXT,
                created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$newslettersTable} (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                title        VARCHAR(255)        NOT NULL DEFAULT '',
                content      LONGTEXT,
                status       VARCHAR(10)         NOT NULL DEFAULT 'draft',
                publish_date DATETIME            NULL DEFAULT NULL,
                created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY publish_date (publish_date)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$newsletterEventsTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT(20) UNSIGNED NOT NULL,
                event_id      BIGINT(20) UNSIGNED NOT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY newsletter_event (newsletter_id, event_id),
                KEY newsletter_id (newsletter_id),
                KEY event_id (event_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$newsletterTagsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                slug       VARCHAR(255)        NOT NULL DEFAULT '',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$newsletterTagMapTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT(20) UNSIGNED NOT NULL,
                tag_id        BIGINT(20) UNSIGNED NOT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY newsletter_tag (newsletter_id, tag_id),
                KEY newsletter_id (newsletter_id),
                KEY tag_id (tag_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$categoriesTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                slug       VARCHAR(255)        NOT NULL DEFAULT '',
                parent_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug),
                KEY parent_id (parent_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$categoryMapTable} (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                category_id BIGINT(20) UNSIGNED NOT NULL,
                entity_type VARCHAR(50)         NOT NULL,
                entity_id   BIGINT(20) UNSIGNED NOT NULL,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY category_entity (category_id, entity_type, entity_id),
                KEY entity_lookup (entity_type, entity_id),
                KEY category_id (category_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$giftsTable} (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(255)        NOT NULL DEFAULT '',
                description TEXT,
                price_cents INT UNSIGNED        NOT NULL DEFAULT 0,
                website_url VARCHAR(500)        NOT NULL DEFAULT '',
                image_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY image_attachment_id (image_attachment_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$giftEventsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                gift_id    BIGINT(20) UNSIGNED NOT NULL,
                event_id   BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY gift_event (gift_id, event_id),
                KEY gift_id (gift_id),
                KEY event_id (event_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$giftPurchasesTable} (
                id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                gift_id                BIGINT(20) UNSIGNED NOT NULL,
                event_id               BIGINT(20) UNSIGNED NOT NULL,
                is_purchased           TINYINT(1)          NOT NULL DEFAULT 0,
                purchased_at           DATETIME            NULL DEFAULT NULL,
                purchased_by_group_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                purchased_by_invitee_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                created_at             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY gift_event_purchase (gift_id, event_id),
                KEY gift_id (gift_id),
                KEY event_id (event_id),
                KEY purchased_by_group_id (purchased_by_group_id),
                KEY purchased_by_invitee_id (purchased_by_invitee_id)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$eventMessagesTable} (
                id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id            BIGINT(20) UNSIGNED NOT NULL,
                connection_group_id BIGINT(20) UNSIGNED NOT NULL,
                message             TEXT                NOT NULL,
                is_read             TINYINT(1)          NOT NULL DEFAULT 0,
                created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY connection_group_id (connection_group_id),
                KEY event_group (event_id, connection_group_id)
            ) ENGINE=InnoDB {$charset};";

        $sql .= "CREATE TABLE {$requestedInviteeAddOnsTable} (
                id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                connection_group_id  BIGINT(20) UNSIGNED NOT NULL,
                event_id             BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                invitation_group_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                first_name           VARCHAR(100)        NOT NULL,
                last_name            VARCHAR(100)        NOT NULL,
                email                VARCHAR(255)        NOT NULL,
                phone                VARCHAR(40)         NOT NULL DEFAULT '',
                street_address       VARCHAR(255)        NOT NULL DEFAULT '',
                city                 VARCHAR(100)        NOT NULL DEFAULT '',
                state                VARCHAR(50)         NOT NULL DEFAULT '',
                zip_code             VARCHAR(20)         NOT NULL DEFAULT '',
                image_attachment_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                notes                TEXT,
                status               VARCHAR(10)         NOT NULL DEFAULT 'pending',
                approved_invitee_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                reviewed_at          DATETIME            NULL DEFAULT NULL,
                created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY connection_group_id (connection_group_id),
                KEY event_id (event_id),
                KEY invitation_group_id (invitation_group_id),
                KEY status (status)
            ) ENGINE=InnoDB {$charset};";

        dbDelta($sql);
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

    /** @return string Fully-qualified global menu items table name. */
    public static function menuItemsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::MENU_ITEMS_TABLE;
    }

    /** @return string Fully-qualified event-menu-items pivot table name. */
    public static function eventMenuItemsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENT_MENU_ITEMS_TABLE;
    }

    /** @return string Fully-qualified budget plans table name. */
    public static function budgetPlansTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::BUDGET_PLANS_TABLE;
    }

    /** @return string Fully-qualified budget plan events pivot table name. */
    public static function budgetPlanEventsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::BUDGET_PLAN_EVENTS_TABLE;
    }

    /** @return string Fully-qualified budget line items table name. */
    public static function budgetLineItemsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::BUDGET_LINE_ITEMS_TABLE;
    }

    /** @return string Fully-qualified newsletters table name. */
    public static function newslettersTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTERS_TABLE;
    }

    /** @return string Fully-qualified newsletter-events pivot table name. */
    public static function newsletterEventsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_EVENTS_TABLE;
    }

    /** @return string Fully-qualified newsletter tags table name. */
    public static function newsletterTagsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_TAGS_TABLE;
    }

    /** @return string Fully-qualified newsletter-tag pivot table name. */
    public static function newsletterTagMapTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_TAG_MAP_TABLE;
    }

    /** @return string Fully-qualified vendors table name. */
    public static function vendorsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::VENDORS_TABLE;
    }

    /** @return string Fully-qualified unified categories table name. */
    public static function categoriesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::CATEGORIES_TABLE;
    }

    /** @return string Fully-qualified category–entity pivot table name. */
    public static function categoryMapTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::CATEGORY_MAP_TABLE;
    }

    /** @return string Fully-qualified gifts library table name. */
    public static function giftsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::GIFTS_TABLE;
    }

    /** @return string Fully-qualified gift–event pivot table name. */
    public static function giftEventsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::GIFT_EVENTS_TABLE;
    }

    /** @return string Fully-qualified gift purchase tracking table name. */
    public static function giftPurchasesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::GIFT_PURCHASES_TABLE;
    }

    /** @return string Fully-qualified event messages table name. */
    public static function eventMessagesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::EVENT_MESSAGES_TABLE;
    }

    /** @return string Fully-qualified requested invitee add-ons table name. */
    public static function requestedInviteeAddOnsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::REQUESTED_INVITEE_ADD_ONS_TABLE;
    }

}
