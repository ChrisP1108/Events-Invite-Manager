<?php
/**
 * Plugin Name: Events Invite Manager
 * Description: Manages the inviting and registration of attendees for events, with custom email templates and a front-end registration API.
 * Version:     1.2.1
 * Requires PHP: 8.1
 * Author:      Chris Paschall
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.1+ syntax directly. PHP parses the entire file
 * before executing any branch, so 8.1+ syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.1+ code is safely
 * isolated in the vendor autoloader and the src/ files loaded inside the else block.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Events Invite Manager</strong> requires PHP 8.1 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });
} else {

    define('EIM_VERSION', '1.2.1');
    define('EIM_PLUGIN_FILE', __FILE__);
    define('EIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('EIM_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once EIM_PLUGIN_DIR . 'vendor/autoload.php';

    /**
     * Plugin activation: create the required database tables.
     *
     * register_activation_hook must be called in the main plugin file to fire reliably.
     * DatabaseManager uses dbDelta for idempotent table creation, so re-activating is safe.
     */
    register_activation_hook(__FILE__, static function (): void {
        EventsInviteManager\Database\DatabaseManager::createTables();
    });

    /**
     * Plugin deactivation: tables and data are intentionally preserved on deactivation
     * so they survive a deactivate/reactivate cycle.
     */
    register_deactivation_hook(__FILE__, static function (): void {});

    add_action('plugins_loaded', static function (): void {
        EventsInviteManager\Plugin::getInstance()->init();
    });
}
