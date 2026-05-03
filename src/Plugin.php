<?php

declare(strict_types=1);

namespace EventsInviteManager;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Api\RestController;

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
        $this->adminMenu      = new AdminMenu();
        $this->restController = new RestController();

        $this->adminMenu->register();
        $this->restController->register();
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
