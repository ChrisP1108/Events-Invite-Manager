<?php

declare(strict_types=1);

namespace EventsInviteManager\Database;

if (!defined('ABSPATH')) exit;

final class DatabaseManager
{
    private const SCHEMA_VERSION = '14';

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
    private const NEWSLETTER_CATEGORIES_TABLE            = 'eim_newsletter_categories';
    private const NEWSLETTER_TAGS_TABLE                  = 'eim_newsletter_tags';
    private const NEWSLETTER_CATEGORY_MAP_TABLE          = 'eim_newsletter_category_map';
    private const NEWSLETTER_TAG_MAP_TABLE               = 'eim_newsletter_tag_map';

    public static function createTables(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

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
        $menuItemsTable      = $wpdb->prefix . self::MENU_ITEMS_TABLE;
        $eventMenuItemsTable = $wpdb->prefix . self::EVENT_MENU_ITEMS_TABLE;
        $budgetPlansTable    = $wpdb->prefix . self::BUDGET_PLANS_TABLE;
        $budgetPlanEventsTable = $wpdb->prefix . self::BUDGET_PLAN_EVENTS_TABLE;
        $budgetLineItemsTable  = $wpdb->prefix . self::BUDGET_LINE_ITEMS_TABLE;
        $newslettersTable         = $wpdb->prefix . self::NEWSLETTERS_TABLE;
        $newsletterEventsTable    = $wpdb->prefix . self::NEWSLETTER_EVENTS_TABLE;
        $newsletterCategoriesTable = $wpdb->prefix . self::NEWSLETTER_CATEGORIES_TABLE;
        $newsletterTagsTable      = $wpdb->prefix . self::NEWSLETTER_TAGS_TABLE;
        $newsletterCategoryMapTable = $wpdb->prefix . self::NEWSLETTER_CATEGORY_MAP_TABLE;
        $newsletterTagMapTable    = $wpdb->prefix . self::NEWSLETTER_TAG_MAP_TABLE;

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
                max_invitees              SMALLINT UNSIGNED   NULL DEFAULT NULL,
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
                id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id           BIGINT(20) UNSIGNED NOT NULL,
                invitee_id         BIGINT(20) UNSIGNED NOT NULL,
                rsvp_status        VARCHAR(10)         NOT NULL DEFAULT 'pending',
                registered_at      DATETIME,
                food_option_id     BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                beverage_option_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                dietary_notes      VARCHAR(500)        NOT NULL DEFAULT '',
                created_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
                sort_order  INT                 NOT NULL DEFAULT 0,
                is_active   TINYINT(1)          NOT NULL DEFAULT 1,
                created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY type (type)
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
                category             VARCHAR(30)         NOT NULL DEFAULT 'other',
                label                VARCHAR(255)        NOT NULL DEFAULT '',
                source_type          VARCHAR(20)         NULL DEFAULT NULL,
                source_id            BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                quantity             DECIMAL(10,2)       NOT NULL DEFAULT 1,
                quantity_mode        VARCHAR(15)         NOT NULL DEFAULT 'fixed',
                unit_cost_cents      INT UNSIGNED        NOT NULL DEFAULT 0,
                total_override_cents INT UNSIGNED        NULL DEFAULT NULL,
                paid_amount_cents    INT UNSIGNED        NOT NULL DEFAULT 0,
                vendor_name          VARCHAR(255)        NOT NULL DEFAULT '',
                notes                TEXT,
                sort_order           INT                 NOT NULL DEFAULT 0,
                created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY plan_id (plan_id),
                KEY event_id (event_id),
                KEY category (category)
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
            CREATE TABLE {$newsletterCategoriesTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                slug       VARCHAR(255)        NOT NULL DEFAULT '',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$newsletterTagsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                slug       VARCHAR(255)        NOT NULL DEFAULT '',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) ENGINE=InnoDB {$charset};
            CREATE TABLE {$newsletterCategoryMapTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT(20) UNSIGNED NOT NULL,
                category_id   BIGINT(20) UNSIGNED NOT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY newsletter_category (newsletter_id, category_id),
                KEY newsletter_id (newsletter_id),
                KEY category_id (category_id)
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
            ) ENGINE=InnoDB {$charset};";

        dbDelta($sql);

        update_option('eim_db_version', self::SCHEMA_VERSION, false);
    }

    public static function maybeUpgrade(): void
    {
        if ((string) get_option('eim_db_version', '0') === self::SCHEMA_VERSION) {
            return;
        }

        self::createTables();
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

    /** @return string Fully-qualified newsletter categories table name. */
    public static function newsletterCategoriesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_CATEGORIES_TABLE;
    }

    /** @return string Fully-qualified newsletter tags table name. */
    public static function newsletterTagsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_TAGS_TABLE;
    }

    /** @return string Fully-qualified newsletter-category pivot table name. */
    public static function newsletterCategoryMapTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_CATEGORY_MAP_TABLE;
    }

    /** @return string Fully-qualified newsletter-tag pivot table name. */
    public static function newsletterTagMapTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::NEWSLETTER_TAG_MAP_TABLE;
    }

    /**
     * Ensures all six newsletter tables exist, creating them if missing.
     */
    public static function maybeCreateNewsletterTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset                    = $wpdb->get_charset_collate();
        $newslettersTable           = $wpdb->prefix . self::NEWSLETTERS_TABLE;
        $newsletterEventsTable      = $wpdb->prefix . self::NEWSLETTER_EVENTS_TABLE;
        $newsletterCategoriesTable  = $wpdb->prefix . self::NEWSLETTER_CATEGORIES_TABLE;
        $newsletterTagsTable        = $wpdb->prefix . self::NEWSLETTER_TAGS_TABLE;
        $newsletterCategoryMapTable = $wpdb->prefix . self::NEWSLETTER_CATEGORY_MAP_TABLE;
        $newsletterTagMapTable      = $wpdb->prefix . self::NEWSLETTER_TAG_MAP_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$newslettersTable}'") !== $newslettersTable) {
            dbDelta("CREATE TABLE {$newslettersTable} (
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
            ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$newsletterEventsTable}'") !== $newsletterEventsTable) {
            dbDelta("CREATE TABLE {$newsletterEventsTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT(20) UNSIGNED NOT NULL,
                event_id      BIGINT(20) UNSIGNED NOT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY newsletter_event (newsletter_id, event_id),
                KEY newsletter_id (newsletter_id),
                KEY event_id (event_id)
            ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$newsletterCategoriesTable}'") !== $newsletterCategoriesTable) {
            dbDelta("CREATE TABLE {$newsletterCategoriesTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                slug       VARCHAR(255)        NOT NULL DEFAULT '',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$newsletterTagsTable}'") !== $newsletterTagsTable) {
            dbDelta("CREATE TABLE {$newsletterTagsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)        NOT NULL DEFAULT '',
                slug       VARCHAR(255)        NOT NULL DEFAULT '',
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug)
            ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$newsletterCategoryMapTable}'") !== $newsletterCategoryMapTable) {
            dbDelta("CREATE TABLE {$newsletterCategoryMapTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT(20) UNSIGNED NOT NULL,
                category_id   BIGINT(20) UNSIGNED NOT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY newsletter_category (newsletter_id, category_id),
                KEY newsletter_id (newsletter_id),
                KEY category_id (category_id)
            ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$newsletterTagMapTable}'") !== $newsletterTagMapTable) {
            dbDelta("CREATE TABLE {$newsletterTagMapTable} (
                id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id BIGINT(20) UNSIGNED NOT NULL,
                tag_id        BIGINT(20) UNSIGNED NOT NULL,
                created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY newsletter_tag (newsletter_id, tag_id),
                KEY newsletter_id (newsletter_id),
                KEY tag_id (tag_id)
            ) ENGINE=InnoDB {$charset};");
        }
    }

    /**
     * Ensures all three budget tables exist, creating them if missing.
     *
     * Each table is checked independently so a partial schema failure (e.g. the
     * plans table exists but the pivot or line-items table was never created)
     * still recovers correctly.
     */
    public static function maybeCreateBudgetTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset               = $wpdb->get_charset_collate();
        $plansTable            = $wpdb->prefix . self::BUDGET_PLANS_TABLE;
        $budgetPlanEventsTable = $wpdb->prefix . self::BUDGET_PLAN_EVENTS_TABLE;
        $budgetLineItemsTable  = $wpdb->prefix . self::BUDGET_LINE_ITEMS_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$plansTable}'") !== $plansTable) {
            dbDelta("CREATE TABLE {$plansTable} (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name                VARCHAR(255)        NOT NULL DEFAULT '',
            description         TEXT,
            target_amount_cents INT UNSIGNED        NOT NULL DEFAULT 0,
            currency            VARCHAR(3)          NOT NULL DEFAULT 'USD',
            created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$budgetPlanEventsTable}'") !== $budgetPlanEventsTable) {
            dbDelta("CREATE TABLE {$budgetPlanEventsTable} (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id    BIGINT(20) UNSIGNED NOT NULL,
                event_id   BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY plan_event (plan_id, event_id),
                KEY plan_id (plan_id),
                KEY event_id (event_id)
            ) ENGINE=InnoDB {$charset};");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '{$budgetLineItemsTable}'") !== $budgetLineItemsTable) {
            dbDelta("CREATE TABLE {$budgetLineItemsTable} (
                id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id              BIGINT(20) UNSIGNED NOT NULL,
                event_id             BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                category             VARCHAR(30)         NOT NULL DEFAULT 'other',
                label                VARCHAR(255)        NOT NULL DEFAULT '',
                source_type          VARCHAR(20)         NULL DEFAULT NULL,
                source_id            BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                quantity             DECIMAL(10,2)       NOT NULL DEFAULT 1.00,
                quantity_mode        VARCHAR(15)         NOT NULL DEFAULT 'fixed',
                unit_cost_cents      INT UNSIGNED        NOT NULL DEFAULT 0,
                total_override_cents INT UNSIGNED        NULL DEFAULT NULL,
                paid_amount_cents    INT UNSIGNED        NOT NULL DEFAULT 0,
                vendor_name          VARCHAR(255)        NOT NULL DEFAULT '',
                notes                TEXT,
                sort_order           INT                 NOT NULL DEFAULT 0,
                created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY plan_id (plan_id),
                KEY event_id (event_id),
                KEY category (category)
            ) ENGINE=InnoDB {$charset};");
        }
    }
}
