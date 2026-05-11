<?php
/**
 * Plugin Name: Events Invite Manager
 * Description: Manages the inviting and registration of attendees for events, with custom email templates, QR code generating functionality, and a front-end registration API.
 * Version:     1.4.0
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

    define('EIM_VERSION', '1.4.0');
    define('EIM_PLUGIN_FILE', __FILE__);
    define('EIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('EIM_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once EIM_PLUGIN_DIR . 'vendor/autoload.php';

    /**
     * Plugin activation: create the required database tables and schedule the daily
     * QR code cleanup job.
     *
     * register_activation_hook must be called in the main plugin file to fire reliably.
     * DatabaseManager uses dbDelta for idempotent table creation, so re-activating is safe.
     * wp_schedule_event is guarded by wp_next_scheduled() so re-activating never
     * creates duplicate cron entries.
     */
    register_activation_hook(__FILE__, static function (): void {
        EventsInviteManager\Database\DatabaseManager::createTables();

        if (!wp_next_scheduled('eim_daily_qr_cleanup')) {
            wp_schedule_event(time(), 'daily', 'eim_daily_qr_cleanup');
        }
    });

    /**
     * Plugin deactivation: tables and data are intentionally preserved so they survive
     * a deactivate/reactivate cycle. The daily cron job is unscheduled here and will be
     * re-registered the next time the plugin is activated.
     */
    register_deactivation_hook(__FILE__, static function (): void {
        wp_clear_scheduled_hook('eim_daily_qr_cleanup');
    });

    add_action('plugins_loaded', static function (): void {
        EventsInviteManager\Plugin::getInstance()->init();
    });
}
