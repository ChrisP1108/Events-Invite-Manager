<?php

declare(strict_types=1);

namespace EventsInviteManager;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Api\RestController;
use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Services\RsvpFlowResolver;
use EventsInviteManager\Services\RsvpFlowResult;
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
     * Intercepts page requests carrying ?eim_confirmation={code} and routes them
     * to the correct front-end page for the current RSVP state.
     *
     * Flow:
     *   1. Invitee scans QR code and lands on {site_url}/?eim_confirmation={code}.
     *   2. This hook looks up the QR code, event, and current RSVP flow state.
     *   3. If RSVP has not opened yet and a before-start page is configured,
     *      redirects to {before_start_page_url}?eim_confirmation={code}.
     *   4. If the RSVP flow is complete, redirects to the dashboard page unless
     *      the request explicitly asks to edit the RSVP.
     *   5. Otherwise redirects to the configured RSVP page, preserving the
     *      confirmation code and edit flag when present.
     *
     * Deadline behavior is intentionally enforced by the RSVP REST API. This QR
     * redirect still sends visitors to the RSVP/dashboard experience after the
     * deadline so the front end can show the existing RSVP state and API-provided
     * deadline flags, while write attempts are rejected server-side.
     *
     * If the code is unknown, the event is missing, no target page is configured,
     * or the selected page no longer exists, the request continues normally.
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

        if ($event === null) {
            return;
        }

        $currentPageId = (int) get_queried_object_id();
        $isEditRequest = $this->isEditRequest();

        // The newsletter and dashboard pages both receive the confirmation code so
        // their shortcodes can gate access via the public REST endpoints.
        if ($event->newsletterPageId !== null && $currentPageId === $event->newsletterPageId) {
            return;
        }

        if ($event->dashboardPageId !== null && $currentPageId === $event->dashboardPageId) {
            return;
        }

        if ($event->rsvpBeforeStartPageId !== null && $currentPageId === $event->rsvpBeforeStartPageId) {
            return;
        }

        $flowResult = (new RsvpFlowResolver())->resolve($code);

        // Redirect to the holding page before RSVPs open.
        if ($flowResult->success && $flowResult->rsvpStartPending && $flowResult->rsvpBeforeStartUrl !== null) {
            wp_safe_redirect($flowResult->rsvpBeforeStartUrl, 302);
            exit;
        }

        // Redirect to the dashboard if the flow is complete
        if (
            !$isEditRequest
            &&
            $flowResult->success
            && $flowResult->isComplete()
            && $flowResult->dashboardUrl !== null
        ) {
            wp_safe_redirect($flowResult->dashboardUrl, 302);
            exit;
        }

        // Redirect to the RSVP page
        if ($event->rsvpPageId === null) {
            return;
        }

        // If the visitor is already on the configured RSVP page and still has
        // required steps, let the page load normally.
        if ($currentPageId === $event->rsvpPageId) {
            return;
        }

        $pageUrl = get_permalink($event->rsvpPageId);

        // If the RSVP page no longer exists, let the page load normally
        if (!$pageUrl) {
            return;
        }

        $redirectUrl = add_query_arg('eim_confirmation', rawurlencode($code), $pageUrl);
        if ($isEditRequest) {
            $redirectUrl = add_query_arg('eim_edit', '1', $redirectUrl);
        }

        wp_safe_redirect($redirectUrl, 302);
        exit;
    }

    /**
     * Returns true when the QR URL intentionally requests the RSVP edit form.
     *
     * @return bool
     */
    private function isEditRequest(): bool
    {
        $raw = isset($_GET['eim_edit']) ? sanitize_text_field(wp_unslash($_GET['eim_edit'])) : '';

        return in_array(strtolower($raw), ['1', 'true', 'yes'], true);
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
