<?php

declare(strict_types=1);

namespace EventsInviteManager;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Api\RestController;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Updates\GitHubUpdater;

/**
 * Main plugin bootstrap class.
 *
 * Acts as the composition root, wiring together all subsystems and registering
 * WordPress hooks. Implemented as a singleton to allow other code to reference
 * its subsystems without passing instances around.
 */
final class Plugin
{
    /** @var self|null Singleton instance. */
    private static ?self $instance = null;

    /** @var AdminMenu Admin menu and page handler. */
    private AdminMenu $adminMenu;

    /** @var RestController Public-facing REST API controller. */
    private RestController $restController;

    /** Private constructor enforces singleton usage via getInstance(). */
    private function __construct() {}

    /**
     * Returns the singleton plugin instance, creating it on first call.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialises the plugin by instantiating subsystems and registering all hooks.
     *
     * Called once from the plugins_loaded action in the root plugin file.
     *
     * @return void
     */
    public function init(): void
    {
        DatabaseManager::maybeUpgrade();

        $this->adminMenu      = new AdminMenu();
        $this->restController = new RestController();

        $this->adminMenu->register();
        $this->restController->register();
        GitHubUpdater::init();

        add_action('template_redirect',    [$this, 'handleQrConfirmationRedirect']);
        add_action('eim_daily_qr_cleanup', [$this, 'runDailyQrCleanup']);
    }

    /**
     * Intercepts any page request that carries ?eim_confirmation={code} and redirects
     * to the event's configured RSVP page, preserving the confirmation code in the URL.
     *
     * Flow:
     *   1. Invitee scans QR code → lands on {site_url}/?eim_confirmation={code}
     *   2. This hook fires, looks up the code in eim_qr_codes
     *   3. Fetches the event and its rsvp_page_id
     *   4. Redirects to {rsvp_page_url}?eim_confirmation={code}
     *
     * If the code is unknown, the event has no RSVP page configured, or the page no
     * longer exists, the request is allowed to continue loading normally.
     *
     * @return void
     */
    public function handleQrConfirmationRedirect(): void
    {
        $code = isset($_GET['eim_confirmation']) ? sanitize_text_field(wp_unslash($_GET['eim_confirmation'])) : '';

        if ($code === '') {
            return;
        }

        $qrCode = QrCode::findByCode($code);

        if ($qrCode === null) {
            return;
        }

        $event = Event::find($qrCode->eventId);

        if ($event === null || $event->rsvpPageId === null) {
            return;
        }

        // If the visitor is already on the configured RSVP page, let the page load
        // normally — redirecting again would cause an immediate redirect loop.
        if ((int) get_queried_object_id() === $event->rsvpPageId) {
            return;
        }

        $pageUrl = get_permalink($event->rsvpPageId);

        if (!$pageUrl) {
            return;
        }

        $redirectUrl = add_query_arg('eim_confirmation', rawurlencode($code), $pageUrl);

        wp_safe_redirect($redirectUrl, 302);
        exit;
    }

    /**
     * Removes QR code records and PNG files for events that have already concluded.
     *
     * Called daily by WP-Cron via the eim_daily_qr_cleanup hook, scheduled on plugin
     * activation and cleared on deactivation.
     *
     * @return void
     */
    public function runDailyQrCleanup(): void
    {
        QrCode::cleanupForPastEvents();
    }

    /**
     * Returns the REST controller instance.
     *
     * @return RestController
     */
    public function getRestController(): RestController
    {
        return $this->restController;
    }
}
