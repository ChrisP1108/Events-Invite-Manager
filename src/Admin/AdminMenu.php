<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\Pages\AboutPage;
use EventsInviteManager\Admin\Pages\EventsManager\EventsManagerPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\ConnectionGroupsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\EventsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\InviteesPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\LocationsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\BudgetPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\MenuItemsPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\CategoriesPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\NewslettersPage;
use EventsInviteManager\Admin\Pages\EventsManager\SubPages\VendorsPage;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Email\TemplateRenderer;
use EventsInviteManager\Services\QrCodeService;

/**
 * Coordinates the plugin's admin menu pages, shared assets, and action dispatch.
 */
final class AdminMenu
{
    /** @var string Admin page slug for the main Events Manager hub. */
    public const PAGE_EVENTS_MANAGER    = 'eim-events-manager';

    /** @var string Admin page slug for the About page. */
    public const PAGE_ABOUT             = 'eim-about';

    /** @var string Tab slug for the Events sub-page. */
    public const TAB_EVENTS             = 'events';

    /** @var string Tab slug for the Invitees sub-page. */
    public const TAB_INVITEES           = 'invitees';

    /** @var string Tab slug for the Connection Groups sub-page. */
    public const TAB_CONNECTION_GROUPS  = 'connection-groups';

    /** @var string Tab slug for the Locations sub-page. */
    public const TAB_LOCATIONS          = 'locations';

    /** @var string Tab slug for the Food & Beverages sub-page. */
    public const TAB_MENU_ITEMS         = 'food-beverages';

    /** @var string Tab slug for the Budget sub-page. */
    public const TAB_BUDGET             = 'budget';

    /** @var string Tab slug for the Newsletters sub-page. */
    public const TAB_NEWSLETTERS        = 'newsletters';

    /** @var string Tab slug for the Vendors sub-page. */
    public const TAB_VENDORS            = 'vendors';

    /** @var string Tab slug for the Categories sub-page. */
    public const TAB_CATEGORIES         = 'categories';

    /** @var AboutPage About / plugin-info page. */
    private AboutPage            $aboutPage;

    /** @var EventsManagerPage Tab-dispatcher for all Events Manager sub-pages. */
    private EventsManagerPage    $eventsManagerPage;

    /** @var EventsPage Events sub-page handler. */
    private EventsPage           $eventsPage;

    /** @var InviteesPage Invitees sub-page handler. */
    private InviteesPage         $inviteesPage;

    /** @var ConnectionGroupsPage Connection Groups sub-page handler. */
    private ConnectionGroupsPage $connectionGroupsPage;

    /** @var LocationsPage Locations sub-page handler. */
    private LocationsPage        $locationsPage;

    /** @var MenuItemsPage Food & Beverages sub-page handler. */
    private MenuItemsPage        $menuItemsPage;

    /** @var BudgetPage Budget sub-page handler. */
    private BudgetPage           $budgetPage;

    /** @var NewslettersPage Newsletters sub-page handler. */
    private NewslettersPage      $newslettersPage;

    /** @var VendorsPage Vendors sub-page handler. */
    private VendorsPage          $vendorsPage;

    /** @var CategoriesPage Categories sub-page handler. */
    private CategoriesPage       $categoriesPage;

    /**
     * Instantiates all sub-page handlers and shared services.
     */
    public function __construct()
    {
        $emailService  = new EmailService(new TemplateRenderer());
        $qrCodeService = new QrCodeService();

        $this->eventsPage           = new EventsPage($emailService, $qrCodeService);
        $this->inviteesPage         = new InviteesPage();
        $this->connectionGroupsPage = new ConnectionGroupsPage();
        $this->locationsPage        = new LocationsPage();
        $this->menuItemsPage        = new MenuItemsPage();
        $this->budgetPage           = new BudgetPage();
        $this->newslettersPage      = new NewslettersPage($emailService);
        $this->vendorsPage          = new VendorsPage();
        $this->categoriesPage       = new CategoriesPage();

        $this->aboutPage          = new AboutPage();
        $this->eventsManagerPage  = new EventsManagerPage(
            $this->eventsPage,
            $this->inviteesPage,
            $this->connectionGroupsPage,
            $this->locationsPage,
            $this->menuItemsPage,
            $this->budgetPage,
            $this->newslettersPage,
            $this->vendorsPage,
            $this->categoriesPage
        );
    }

    /**
     * Builds a tab URL for the Events Manager page.
     *
     * @param string $tab    One of the TAB_* constants.
     * @param array  $params Additional query parameters.
     */
    public static function tabUrl(string $tab, array $params = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_EVENTS_MANAGER, 'tab' => $tab], $params),
            admin_url('admin.php')
        );
    }

    /**
     * Registers admin menu hooks and all wp_ajax_* AJAX action hooks.
     *
     * Called once from the plugin bootstrap after instantiation.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu',            [$this, 'addMenuPages']);
        add_action('admin_init',            [$this, 'processFormSubmissions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);

        // Menu items library + event-edit autocomplete.
        add_action('wp_ajax_eim_search_menu_items',         [$this->menuItemsPage, 'handleAjaxSearchMenuItems']);
        add_action('wp_ajax_eim_suggest_menu_items',        [$this->menuItemsPage, 'handleAjaxSuggestMenuItems']);

        // Invitee search / suggest / connections for event flow.
        add_action('wp_ajax_eim_search_locations',          [$this->locationsPage, 'handleAjaxSearchLocations']);
        add_action('wp_ajax_eim_search_locations_list',     [$this->locationsPage, 'handleAjaxSearchLocationsList']);
        add_action('wp_ajax_eim_search_invitees',           [$this->inviteesPage, 'handleAjaxSearchInvitees']);
        add_action('wp_ajax_eim_suggest_invitees',          [$this->inviteesPage, 'handleAjaxSuggestInvitees']);
        add_action('wp_ajax_eim_get_connections_for_event', [$this->inviteesPage, 'handleAjaxGetConnectionsForEvent']);

        // Connection group member picker (on ConnectionGroupsPage).
        add_action('wp_ajax_eim_search_connection_groups', [$this->connectionGroupsPage, 'handleAjaxSearchGroups']);
        add_action('wp_ajax_eim_sort_event_groups',        [$this->eventsPage,            'handleAjaxSortGroups']);
        add_action('wp_ajax_eim_sort_event_lodging',       [$this->eventsPage,            'handleAjaxSortLodging']);
        add_action('wp_ajax_eim_sort_event_menu_items',    [$this->eventsPage,            'handleAjaxSortMenuItems']);
        add_action('wp_ajax_eim_suggest_cg_members',    [$this->connectionGroupsPage, 'handleAjaxSuggestMembers']);
        add_action('wp_ajax_eim_suggest_events',           [$this->eventsPage, 'handleAjaxSuggestEvents']);
        add_action('wp_ajax_eim_search_events',            [$this->eventsPage, 'handleAjaxSearchEvents']);
        add_action('wp_ajax_eim_search_budget_plans',      [$this->budgetPage, 'handleAjaxSearchPlans']);
        add_action('wp_ajax_eim_search_budget_line_items', [$this->budgetPage, 'handleAjaxSearchLineItems']);
        add_action('wp_ajax_eim_search_newsletters',       [$this->newslettersPage, 'handleAjaxSearchNewsletters']);
        add_action('wp_ajax_eim_send_newsletter',          [$this->newslettersPage, 'handleAjaxSendNewsletter']);
        add_action('wp_ajax_eim_send_newsletter_test',     [$this->newslettersPage, 'handleAjaxSendNewsletterTest']);

        // Vendors list + suggest autocomplete.
        add_action('wp_ajax_eim_search_vendors_list', [$this->vendorsPage,     'handleAjaxSearchVendors']);
        add_action('wp_ajax_eim_suggest_vendors',     [$this->vendorsPage,     'handleAjaxSuggestVendors']);

        // Categories list + suggest autocomplete (shared picker for all entity types).
        add_action('wp_ajax_eim_search_categories',   [$this->categoriesPage,  'handleAjaxSearchCategories']);
        add_action('wp_ajax_eim_suggest_categories',  [$this->categoriesPage,  'handleAjaxSuggestCategories']);

        add_filter('script_loader_tag', [$this, 'addModuleTypeToScript'], 10, 2);
    }

    /**
     * Adds type="module" to the eim-location-autocomplete script tag.
     *
     * Hooked onto script_loader_tag at priority 10 so dynamic import() works correctly.
     *
     * @param string $tag    The full HTML <script> tag being output.
     * @param string $handle The script handle registered with wp_enqueue_script.
     * @return string
     */
    public function addModuleTypeToScript(string $tag, string $handle): string
    {
        if ($handle !== 'eim-location-autocomplete') {
            return $tag;
        }

        $tag = (string) preg_replace('/\s+type=[\'"]text\/javascript[\'"]/', '', $tag);
        return str_replace('<script ', '<script type="module" ', $tag);
    }

    /**
     * Enqueues page-specific CSS and JS assets and localises configuration objects.
     *
     * Only fires on plugin admin pages; returns early for all other screens.
     *
     * @param string $_hookSuffix The current admin page hook suffix (unused but required by WP).
     * @return void
     */
    public function enqueueScripts(string $_hookSuffix): void
    {
        $page   = $_GET['page'] ?? '';
        $tab    = sanitize_key($_GET['tab'] ?? self::TAB_EVENTS);
        $action = $_GET['action'] ?? 'list';

        $pluginPages = [self::PAGE_EVENTS_MANAGER, self::PAGE_ABOUT];

        if (!in_array($page, $pluginPages, true)) {
            return;
        }

        wp_enqueue_style('eim-admin', EIM_PLUGIN_URL . 'assets/css/admin.css', [], EIM_VERSION);
        wp_enqueue_script('eim-admin-pagination', EIM_PLUGIN_URL . 'assets/js/admin-pagination.js', [], EIM_VERSION, true);

        if ($page !== self::PAGE_EVENTS_MANAGER) {
            return;
        }

        // admin-categories.js provides CategoryPicker (used on all entity forms) and CategoriesTable.
        $categoryTableEnabled = $tab === self::TAB_CATEGORIES && !in_array($action, ['add', 'edit'], true);
        wp_enqueue_script('eim-admin-categories', EIM_PLUGIN_URL . 'assets/js/admin-categories.js', [], EIM_VERSION, true);
        wp_localize_script('eim-admin-categories', 'eimCategoriesAdmin', [
            'suggestNonce'       => wp_create_nonce('eim_suggest_categories_nonce'),
            'searchNonce'        => wp_create_nonce('eim_search_categories_nonce'),
            'categoryEditBaseUrl' => self::tabUrl(self::TAB_CATEGORIES),
            'table'              => [
                'enabled' => $categoryTableEnabled,
                'sort'    => sanitize_key($_GET['sort']  ?? 'name'),
                'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            ],
        ]);

        if ($tab === self::TAB_BUDGET) {
            wp_enqueue_script('eim-admin-budget', EIM_PLUGIN_URL . 'assets/js/admin-budget.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-budget', 'eimBudgetAdmin', [
                'searchNonce'        => wp_create_nonce('eim_search_budget_plans_nonce'),
                'lineItemNonce'      => wp_create_nonce('eim_search_budget_line_items_nonce'),
                'suggestEventsNonce' => wp_create_nonce('eim_suggest_events_nonce'),
                'planId'             => $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0,
                'table'              => [
                    'enabled' => $action !== 'edit',
                    'sort'    => sanitize_key($_GET['sort']  ?? 'name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
                'lineItems'          => [
                    'enabled' => $action === 'edit',
                    'sort'    => sanitize_key($_GET['li_sort']  ?? 'sort_order'),
                    'order'   => strtolower((string) ($_GET['li_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
            ]);
            if ($action === 'edit') {
                wp_enqueue_script('eim-admin-vendors', EIM_PLUGIN_URL . 'assets/js/admin-vendors.js', [], EIM_VERSION, true);
                wp_localize_script('eim-admin-vendors', 'eimVendorsAdmin', [
                    'suggestNonce' => wp_create_nonce('eim_suggest_vendors_nonce'),
                    'table'        => ['enabled' => false],
                    'autocomplete' => ['enabled' => true],
                ]);
            }
        }

        if ($tab === self::TAB_LOCATIONS && !in_array($action, ['add', 'edit'], true)) {
            wp_enqueue_script('eim-admin-locations', EIM_PLUGIN_URL . 'assets/js/admin-locations.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-locations', 'eimLocationsAdmin', [
                'searchNonce' => wp_create_nonce('eim_search_locations_list_nonce'),
                'table'       => [
                    'enabled' => true,
                    'sort'    => sanitize_key($_GET['sort'] ?? 'name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
            ]);
        }

        if ($tab === self::TAB_VENDORS) {
            wp_enqueue_script('eim-admin-vendors', EIM_PLUGIN_URL . 'assets/js/admin-vendors.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-vendors', 'eimVendorsAdmin', [
                'searchNonce'  => wp_create_nonce('eim_search_vendors_list_nonce'),
                'suggestNonce' => wp_create_nonce('eim_suggest_vendors_nonce'),
                'table'        => [
                    'enabled' => !in_array($action, ['add', 'edit'], true),
                    'sort'    => sanitize_key($_GET['sort']  ?? 'company_name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
                'autocomplete' => ['enabled' => false],
            ]);
        }

        if ($tab === self::TAB_MENU_ITEMS) {
            wp_enqueue_script('eim-admin-menu-items', EIM_PLUGIN_URL . 'assets/js/admin-menu-items.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-menu-items', 'eimMenuItemsAdmin', [
                'searchNonce' => wp_create_nonce('eim_search_menu_items_nonce'),
            ]);
            wp_enqueue_script('eim-admin-vendors', EIM_PLUGIN_URL . 'assets/js/admin-vendors.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-vendors', 'eimVendorsAdmin', [
                'suggestNonce' => wp_create_nonce('eim_suggest_vendors_nonce'),
                'table'        => ['enabled' => false],
                'autocomplete' => ['enabled' => true],
            ]);
        }

        $needsInviteeJs = $tab === self::TAB_INVITEES
            || ($tab === self::TAB_EVENTS && $action === 'edit')
            || $tab === self::TAB_CONNECTION_GROUPS;

        if ($needsInviteeJs) {
            wp_enqueue_script('eim-admin-invitees', EIM_PLUGIN_URL . 'assets/js/admin-invitees.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-invitees', 'eimInviteesAdmin', [
                'searchNonce'             => wp_create_nonce('eim_search_invitees_nonce'),
                'suggestNonce'            => wp_create_nonce('eim_suggest_invitees_nonce'),
                'suggestMenuItemsNonce'   => wp_create_nonce('eim_suggest_menu_items_nonce'),
                'connectionGroupSearchNonce' => wp_create_nonce('eim_search_connection_groups_nonce'),
                'table'        => [
                    'enabled' => $tab === self::TAB_INVITEES,
                    'sort'    => sanitize_key($_GET['sort'] ?? 'last_name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
                'event'        => [
                    'enabled'             => $tab === self::TAB_EVENTS && $action === 'edit',
                    'id'                  => (int) ($_GET['id'] ?? 0),
                    'groupsSortNonce'     => wp_create_nonce('eim_event_groups_sort_nonce'),
                    'assignmentSortNonce' => wp_create_nonce('eim_event_assignment_sort_nonce'),
                ],
                'connectionGroup' => [
                    'enabled' => $tab === self::TAB_CONNECTION_GROUPS && in_array($action, ['add', 'edit'], true),
                    'id'      => (int) ($_GET['id'] ?? 0),
                ],
                'connectionGroupTable' => [
                    'enabled' => $tab === self::TAB_CONNECTION_GROUPS && !in_array($action, ['add', 'edit'], true),
                    'sort'    => sanitize_key($_GET['sort']  ?? 'name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
            ]);
        }

        if ($tab === self::TAB_NEWSLETTERS) {
            wp_enqueue_script('eim-admin-newsletters', EIM_PLUGIN_URL . 'assets/js/admin-newsletters.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-newsletters', 'eimNewslettersAdmin', [
                'searchNonce'        => wp_create_nonce('eim_search_newsletters_nonce'),
                'suggestEventsNonce' => wp_create_nonce('eim_suggest_events_nonce'),
                'sendNonce'          => wp_create_nonce('eim_send_newsletter_nonce'),
                'sendTestNonce'      => wp_create_nonce('eim_send_newsletter_test_nonce'),
                'table'              => [
                    'enabled' => !in_array($action, ['add', 'edit'], true),
                    'sort'    => sanitize_key($_GET['sort'] ?? 'title'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
            ]);
        }

        if ($tab === self::TAB_EVENTS && $action === 'list') {
            wp_enqueue_script('eim-admin-events', EIM_PLUGIN_URL . 'assets/js/admin-events.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-events', 'eimEventsAdmin', [
                'searchNonce' => wp_create_nonce('eim_search_events_nonce'),
                'table'       => [
                    'enabled' => true,
                    'sort'    => sanitize_key($_GET['sort']  ?? 'start_datetime'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
                ],
            ]);
        }

        if ($tab !== self::TAB_EVENTS || !in_array($action, ['add', 'edit'], true)) {
            return;
        }

        wp_enqueue_script('eim-location-autocomplete', EIM_PLUGIN_URL . 'assets/js/admin-location-autocomplete.js', [], EIM_VERSION, true);
        wp_localize_script('eim-location-autocomplete', 'eimLocationAC', [
            'nonce'   => wp_create_nonce('eim_search_locations_nonce'),
            'baseUrl' => rtrim(EIM_PLUGIN_URL, '/') . '/assets/js',
            'inputs'  => [
                [
                    'inputId'     => 'eim_venue_name',
                    'libraryIdId' => 'eim_venue_library_id',
                    'displayId'   => 'eim_venue_address_display',
                    'fields'      => ['street' => 'eim_venue_street', 'city' => 'eim_venue_city', 'state' => 'eim_venue_state', 'zip' => 'eim_venue_zip', 'isOther' => null],
                ],
                [
                    'inputId'     => 'eim_lodging_add_name',
                    'libraryIdId' => 'eim_lodging_add_library_id',
                    'displayId'   => 'eim_lodging_add_display',
                    'lodgingOnly' => true,
                    'fields'      => ['street' => 'eim_lodging_add_street', 'city' => 'eim_lodging_add_city', 'state' => 'eim_lodging_add_state', 'zip' => 'eim_lodging_add_zip', 'isOther' => 'eim_lodging_add_is_other'],
                ],
            ],
        ]);
    }

    /**
     * Registers the top-level menu page and its sub-menu pages with WordPress.
     *
     * Hooked onto admin_menu.
     *
     * @return void
     */
    public function addMenuPages(): void
    {
        add_menu_page(
            'Events Manager',
            'Events Manager',
            'manage_options',
            self::PAGE_EVENTS_MANAGER,
            [$this, 'renderEventsManagerPage'],
            'dashicons-calendar-alt',
            30
        );

        add_submenu_page(self::PAGE_EVENTS_MANAGER, 'Events Manager', 'Events Manager', 'manage_options', self::PAGE_EVENTS_MANAGER, [$this, 'renderEventsManagerPage']);
        add_submenu_page(self::PAGE_EVENTS_MANAGER, 'About',          'About',          'manage_options', self::PAGE_ABOUT,           [$this, 'renderAboutPage']);
    }

    /**
     * Dispatches POST/GET form actions to the appropriate sub-page handler.
     *
     * Hooked onto admin_init. Returns early when the current user lacks manage_options
     * or when the request targets a non-plugin page.
     *
     * @return void
     */
    public function processFormSubmissions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page   = $_GET['page'] ?? '';
        $tab    = sanitize_key($_GET['tab'] ?? self::TAB_EVENTS);
        $action = $_POST['eim_action'] ?? $_GET['action'] ?? '';

        if ($page === self::PAGE_EVENTS_MANAGER) {
            match ($tab) {
                self::TAB_EVENTS            => $this->eventsPage->handleAction($action),
                self::TAB_INVITEES          => $this->inviteesPage->handleAction($action),
                self::TAB_CONNECTION_GROUPS => $this->connectionGroupsPage->handleAction($action),
                self::TAB_LOCATIONS         => $this->locationsPage->handleAction($action),
                self::TAB_MENU_ITEMS        => $this->menuItemsPage->handleAction($action),
                self::TAB_BUDGET            => $this->budgetPage->handleAction($action),
                self::TAB_VENDORS           => $this->vendorsPage->handleAction($action),
                self::TAB_NEWSLETTERS       => $this->newslettersPage->handleAction($action),
                self::TAB_CATEGORIES        => $this->categoriesPage->handleAction($action),
                default                     => null,
            };
        }
    }

    /**
     * Renders the About / plugin-info page.
     *
     * @return void
     */
    public function renderAboutPage(): void        { $this->aboutPage->renderPage(); }

    /**
     * Renders the Events Manager hub page (delegates to EventsManagerPage).
     *
     * @return void
     */
    public function renderEventsManagerPage(): void { $this->eventsManagerPage->renderPage(); }
}
