<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\Pages\AboutPage;
use EventsInviteManager\Admin\Pages\EventsPage;
use EventsInviteManager\Admin\Pages\InviteesPage;
use EventsInviteManager\Admin\Pages\LocationsPage;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Email\TemplateRenderer;

/**
 * Coordinates the plugin's admin menu pages, shared assets, and action dispatch.
 *
 * Page-specific rendering and form handling live in dedicated page classes:
 * EventsPage, InviteesPage, and LocationsPage.
 */
final class AdminMenu
{
    /** @var string Main events admin page slug. */
    public const PAGE_EVENTS = 'eim-events';

    /** @var string Invitees admin page slug. */
    public const PAGE_INVITEES = 'eim-invitees';

    /** @var string Global location library admin page slug. */
    public const PAGE_LOCATIONS = 'eim-locations';

    /** @var string About / documentation admin page slug. */
    public const PAGE_ABOUT = 'eim-about';

    /** @var AboutPage */
    private AboutPage $aboutPage;

    /** @var EventsPage */
    private EventsPage $eventsPage;

    /** @var InviteesPage */
    private InviteesPage $inviteesPage;

    /** @var LocationsPage */
    private LocationsPage $locationsPage;

    /**
     * Instantiates the page handlers and their shared dependencies.
     */
    public function __construct()
    {
        $emailService = new EmailService(new TemplateRenderer());

        $this->aboutPage     = new AboutPage();
        $this->eventsPage    = new EventsPage($emailService);
        $this->inviteesPage  = new InviteesPage();
        $this->locationsPage = new LocationsPage();
    }

    /**
     * Registers WordPress hooks for the admin area.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu',            [$this, 'addMenuPages']);
        add_action('admin_init',            [$this, 'processFormSubmissions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_ajax_eim_search_locations', [$this->locationsPage, 'handleAjaxSearchLocations']);
        add_action('wp_ajax_eim_search_invitees',  [$this->inviteesPage, 'handleAjaxSearchInvitees']);
        add_action('wp_ajax_eim_suggest_invitees', [$this->inviteesPage, 'handleAjaxSuggestInvitees']);
        add_filter('script_loader_tag',     [$this, 'addModuleTypeToScript'], 10, 2);
    }

    /**
     * Adds type="module" to the location autocomplete script tag so the JS file
     * runs with native ES module scope (strict mode, deferred, no IIFE needed).
     *
     * @param string $tag    Full <script> HTML tag.
     * @param string $handle Script handle.
     * @return string
     */
    public function addModuleTypeToScript(string $tag, string $handle): string
    {
        if ($handle !== 'eim-location-autocomplete') {
            return $tag;
        }

        // Strip any existing type attribute first, then inject type="module".
        $tag = (string) preg_replace('/\s+type=[\'"]text\/javascript[\'"]/', '', $tag);

        return str_replace('<script ', '<script type="module" ', $tag);
    }

    /**
     * Enqueues admin assets for the plugin pages.
     *
     * The shared admin stylesheet loads on all plugin pages. Location autocomplete
     * loads on event add/edit screens. Invitee search loads on the Invitees page
     * and event edit screens where existing invitees can be assigned to events.
     *
     * @param string $_hookSuffix Current admin page hook suffix (unused; we check $_GET directly).
     * @return void
     */
    public function enqueueScripts(string $_hookSuffix): void
    {
        $page   = $_GET['page'] ?? '';
        $action = $_GET['action'] ?? 'list';

        if (!in_array($page, [self::PAGE_EVENTS, self::PAGE_INVITEES, self::PAGE_LOCATIONS, self::PAGE_ABOUT], true)) {
            return;
        }

        wp_enqueue_style(
            'eim-admin',
            EIM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            EIM_VERSION
        );

        if ($page === self::PAGE_INVITEES || ($page === self::PAGE_EVENTS && $action === 'edit')) {
            wp_enqueue_script(
                'eim-admin-invitees',
                EIM_PLUGIN_URL . 'assets/js/admin-invitees.js',
                [],
                EIM_VERSION,
                true
            );

            wp_localize_script('eim-admin-invitees', 'eimInviteesAdmin', [
                'searchNonce'  => wp_create_nonce('eim_search_invitees_nonce'),
                'suggestNonce' => wp_create_nonce('eim_suggest_invitees_nonce'),
                'table'        => [
                    'enabled' => $page === self::PAGE_INVITEES,
                    'sort'    => sanitize_key($_GET['sort'] ?? 'last_name'),
                    'order'   => strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                ],
                'event'        => [
                    'enabled' => $page === self::PAGE_EVENTS && $action === 'edit',
                    'id'      => (int) ($_GET['id'] ?? 0),
                ],
            ]);
        }

        if ($page !== self::PAGE_EVENTS || !in_array($action, ['add', 'edit'], true)) {
            return;
        }

        wp_enqueue_script(
            'eim-location-autocomplete',
            EIM_PLUGIN_URL . 'assets/js/admin-location-autocomplete.js',
            [],
            EIM_VERSION,
            true
        );

        // Fixed-ID inputs (venue + inline lodging-add on edit form).
        // The lodging-init rows on the new-event form are handled by the JS
        // row manager via the #eim-lodging-init-rows container.
        // The JS silently skips any inputId not present in the DOM.
        wp_localize_script('eim-location-autocomplete', 'eimLocationAC', [
            'nonce'   => wp_create_nonce('eim_search_locations_nonce'),
            'baseUrl' => rtrim(EIM_PLUGIN_URL, '/') . '/assets/js',
            'inputs'  => [
                [
                    'inputId'     => 'eim_venue_name',
                    'libraryIdId' => 'eim_venue_library_id',
                    'displayId'   => 'eim_venue_address_display',
                    'fields'      => [
                        'street'  => 'eim_venue_street',
                        'city'    => 'eim_venue_city',
                        'state'   => 'eim_venue_state',
                        'zip'     => 'eim_venue_zip',
                        'isOther' => null,
                    ],
                ],
                [
                    'inputId'     => 'eim_lodging_add_name',
                    'libraryIdId' => 'eim_lodging_add_library_id',
                    'displayId'   => 'eim_lodging_add_display',
                    'fields'      => [
                        'street'  => 'eim_lodging_add_street',
                        'city'    => 'eim_lodging_add_city',
                        'state'   => 'eim_lodging_add_state',
                        'zip'     => 'eim_lodging_add_zip',
                        'isOther' => 'eim_lodging_add_is_other',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Registers the top-level menu page and submenu entries.
     *
     * @return void
     */
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

        add_submenu_page(
            self::PAGE_EVENTS,
            'Events',
            'Events',
            'manage_options',
            self::PAGE_EVENTS,
            [$this, 'renderEventsPage']
        );

        add_submenu_page(
            self::PAGE_EVENTS,
            'Invitees',
            'Invitees',
            'manage_options',
            self::PAGE_INVITEES,
            [$this, 'renderInviteesPage']
        );

        add_submenu_page(
            self::PAGE_EVENTS,
            'Locations',
            'Locations',
            'manage_options',
            self::PAGE_LOCATIONS,
            [$this, 'renderLocationsPage']
        );

        add_submenu_page(
            self::PAGE_EVENTS,
            'About',
            'About',
            'manage_options',
            self::PAGE_ABOUT,
            [$this, 'renderAboutPage']
        );
    }

    /**
     * Dispatches and processes form submissions (POST and GET actions) on plugin pages.
     *
     * Called on admin_init so redirects fire before any HTML is output.
     * Only runs for users with manage_options capability.
     *
     * @return void
     */
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

        if ($page === self::PAGE_LOCATIONS) {
            $this->locationsPage->handleAction($action);
        }
    }

    /**
     * Renders the about admin page.
     *
     * @return void
     */
    public function renderAboutPage(): void
    {
        $this->aboutPage->renderPage();
    }

    /**
     * Renders the events admin page.
     *
     * @return void
     */
    public function renderEventsPage(): void
    {
        $this->eventsPage->renderPage();
    }

    /**
     * Renders the invitees admin page.
     *
     * @return void
     */
    public function renderInviteesPage(): void
    {
        $this->inviteesPage->renderPage();
    }

    /**
     * Renders the locations library admin page.
     *
     * @return void
     */
    public function renderLocationsPage(): void
    {
        $this->locationsPage->renderPage();
    }

}
