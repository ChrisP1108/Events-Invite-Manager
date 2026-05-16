<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\Pages\AboutPage;
use EventsInviteManager\Admin\Pages\ConnectionGroupsPage;
use EventsInviteManager\Admin\Pages\EventsPage;
use EventsInviteManager\Admin\Pages\InviteesPage;
use EventsInviteManager\Admin\Pages\LocationsPage;
use EventsInviteManager\Admin\Pages\MenuItemsPage;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Email\TemplateRenderer;
use EventsInviteManager\Services\QrCodeService;

/**
 * Coordinates the plugin's admin menu pages, shared assets, and action dispatch.
 */
final class AdminMenu
{
    public const PAGE_EVENTS            = 'eim-events';
    public const PAGE_INVITEES          = 'eim-invitees';
    public const PAGE_CONNECTION_GROUPS = 'eim-connection-groups';
    public const PAGE_LOCATIONS         = 'eim-locations';
    public const PAGE_MENU_ITEMS        = 'eim-menu-items';
    public const PAGE_ABOUT             = 'eim-about';

    private AboutPage            $aboutPage;
    private EventsPage           $eventsPage;
    private InviteesPage         $inviteesPage;
    private ConnectionGroupsPage $connectionGroupsPage;
    private LocationsPage        $locationsPage;
    private MenuItemsPage        $menuItemsPage;

    public function __construct()
    {
        $emailService  = new EmailService(new TemplateRenderer());
        $qrCodeService = new QrCodeService();

        $this->aboutPage            = new AboutPage();
        $this->eventsPage           = new EventsPage($emailService, $qrCodeService);
        $this->inviteesPage         = new InviteesPage();
        $this->connectionGroupsPage = new ConnectionGroupsPage();
        $this->locationsPage        = new LocationsPage();
        $this->menuItemsPage        = new MenuItemsPage();
    }

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
        add_action('wp_ajax_eim_sort_event_groups',       [$this->eventsPage,            'handleAjaxSortGroups']);
        add_action('wp_ajax_eim_suggest_cg_members', [$this->connectionGroupsPage, 'handleAjaxSuggestMembers']);

        add_filter('script_loader_tag', [$this, 'addModuleTypeToScript'], 10, 2);
    }

    public function addModuleTypeToScript(string $tag, string $handle): string
    {
        if ($handle !== 'eim-location-autocomplete') {
            return $tag;
        }

        $tag = (string) preg_replace('/\s+type=[\'"]text\/javascript[\'"]/', '', $tag);
        return str_replace('<script ', '<script type="module" ', $tag);
    }

    public function enqueueScripts(string $_hookSuffix): void
    {
        $page   = $_GET['page'] ?? '';
        $action = $_GET['action'] ?? 'list';

        $pluginPages = [
            self::PAGE_EVENTS,
            self::PAGE_INVITEES,
            self::PAGE_CONNECTION_GROUPS,
            self::PAGE_LOCATIONS,
            self::PAGE_MENU_ITEMS,
            self::PAGE_ABOUT,
        ];

        if (!in_array($page, $pluginPages, true)) {
            return;
        }

        wp_enqueue_style('eim-admin', EIM_PLUGIN_URL . 'assets/css/admin.css', [], EIM_VERSION);

        if ($page === self::PAGE_LOCATIONS && !in_array($action, ['add', 'edit'], true)) {
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

        // Menu items library page.
        if ($page === self::PAGE_MENU_ITEMS) {
            wp_enqueue_script('eim-admin-menu-items', EIM_PLUGIN_URL . 'assets/js/admin-menu-items.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-menu-items', 'eimMenuItemsAdmin', [
                'searchNonce' => wp_create_nonce('eim_search_menu_items_nonce'),
            ]);
        }

        // admin-invitees.js handles: invitee table search, event invitee picker,
        // connection group list search, connection group member picker,
        // and menu item pickers on the event edit screen.
        $needsInviteeJs = $page === self::PAGE_INVITEES
            || ($page === self::PAGE_EVENTS && $action === 'edit')
            || $page === self::PAGE_CONNECTION_GROUPS;

        if ($needsInviteeJs) {
            wp_enqueue_script('eim-admin-invitees', EIM_PLUGIN_URL . 'assets/js/admin-invitees.js', [], EIM_VERSION, true);
            wp_localize_script('eim-admin-invitees', 'eimInviteesAdmin', [
                'searchNonce'             => wp_create_nonce('eim_search_invitees_nonce'),
                'suggestNonce'            => wp_create_nonce('eim_suggest_invitees_nonce'),
                'suggestMenuItemsNonce'   => wp_create_nonce('eim_suggest_menu_items_nonce'),
                'connectionGroupSearchNonce' => wp_create_nonce('eim_search_connection_groups_nonce'),
                'table'        => [
                    'enabled' => $page === self::PAGE_INVITEES,
                    'sort'    => sanitize_key($_GET['sort'] ?? 'last_name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
                'event'        => [
                    'enabled'         => $page === self::PAGE_EVENTS && $action === 'edit',
                    'id'              => (int) ($_GET['id'] ?? 0),
                    'groupsSortNonce' => wp_create_nonce('eim_event_groups_sort_nonce'),
                ],
                'connectionGroup' => [
                    'enabled' => $page === self::PAGE_CONNECTION_GROUPS && in_array($action, ['add', 'edit'], true),
                    'id'      => (int) ($_GET['id'] ?? 0),
                ],
                'connectionGroupTable' => [
                    'enabled' => $page === self::PAGE_CONNECTION_GROUPS && !in_array($action, ['add', 'edit'], true),
                    'sort'    => sanitize_key($_GET['sort']  ?? 'name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
            ]);
        }

        if ($page !== self::PAGE_EVENTS || !in_array($action, ['add', 'edit'], true)) {
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

    public function addMenuPages(): void
    {
        add_menu_page(
            'Events Invite Manager',
            'Events Invite Manager',
            'manage_options',
            self::PAGE_EVENTS,
            [$this, 'renderEventsPage'],
            'dashicons-calendar-alt',
            30
        );

        add_submenu_page(self::PAGE_EVENTS, 'Events',              'Events',              'manage_options', self::PAGE_EVENTS,            [$this, 'renderEventsPage']);
        add_submenu_page(self::PAGE_EVENTS, 'Invitees',            'Invitees',            'manage_options', self::PAGE_INVITEES,          [$this, 'renderInviteesPage']);
        add_submenu_page(self::PAGE_EVENTS, 'Connection Groups',   'Connection Groups',   'manage_options', self::PAGE_CONNECTION_GROUPS, [$this, 'renderConnectionGroupsPage']);
        add_submenu_page(self::PAGE_EVENTS, 'Locations',           'Locations',           'manage_options', self::PAGE_LOCATIONS,         [$this, 'renderLocationsPage']);
        add_submenu_page(self::PAGE_EVENTS, 'Food &amp; Beverages', 'Food &amp; Beverages', 'manage_options', self::PAGE_MENU_ITEMS,       [$this, 'renderMenuItemsPage']);
        add_submenu_page(self::PAGE_EVENTS, 'About',               'About',               'manage_options', self::PAGE_ABOUT,             [$this, 'renderAboutPage']);
    }

    public function processFormSubmissions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page   = $_GET['page'] ?? '';
        $action = $_POST['eim_action'] ?? $_GET['action'] ?? '';

        if ($page === self::PAGE_EVENTS) {
            $this->eventsPage->handleAction($action);
        }

        if ($page === self::PAGE_INVITEES) {
            $this->inviteesPage->handleAction($action);
        }

        if ($page === self::PAGE_CONNECTION_GROUPS) {
            $this->connectionGroupsPage->handleAction($action);
        }

        if ($page === self::PAGE_LOCATIONS) {
            $this->locationsPage->handleAction($action);
        }

        if ($page === self::PAGE_MENU_ITEMS) {
            $this->menuItemsPage->handleAction($action);
        }
    }

    public function renderAboutPage(): void            { $this->aboutPage->renderPage(); }
    public function renderEventsPage(): void           { $this->eventsPage->renderPage(); }
    public function renderInviteesPage(): void         { $this->inviteesPage->renderPage(); }
    public function renderConnectionGroupsPage(): void { $this->connectionGroupsPage->renderPage(); }
    public function renderLocationsPage(): void        { $this->locationsPage->renderPage(); }
    public function renderMenuItemsPage(): void        { $this->menuItemsPage->renderPage(); }
}
