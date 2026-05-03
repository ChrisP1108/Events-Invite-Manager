<?php

declare(strict_types=1);

namespace EventsInviteManager\Updates;

if (!defined('ABSPATH')) exit;

/**
 * Connects this plugin to its GitHub releases for automatic update notifications.
 *
 * Hooks into WordPress's built-in update check pipeline so that any published
 * GitHub release triggers the standard "update available" banner in the Plugins
 * screen.  Also adds a "Check for updates" row action so admins can force a
 * fresh check without waiting for the next scheduled transient refresh.
 *
 * Release detection flow:
 *  1. WordPress fires pre_set_site_transient_update_plugins on a scheduled check.
 *  2. checkForUpdate() fetches (or returns a 12-hour cached copy of) the latest
 *     GitHub release via the REST API.
 *  3. If the remote version is newer than EIM_VERSION the plugin is injected
 *     into WordPress's $transient->response array, which triggers the standard update UI.
 *
 * Folder integrity:
 *  GitHub archives are extracted into a version-stamped folder by default
 *  (e.g. Events-Invite-Manager-2.0.0/).  The upgrader_post_install filter
 *  (priority 10) moves the installed directory to the canonical name after a
 *  successful install, before WordPress's own reactivate_plugin_after_upgrade
 *  runs at priority 15 — so the plugin file is at the correct path by the time
 *  WordPress tries to reactivate it.
 */
final class GitHubUpdater
{
    /** @var string GitHub owner/repo path. */
    private const REPO = 'ChrisP1108/Events-Invite-Manager';

    /** @var string GitHub REST API endpoint for the latest release. */
    private const API_URL = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

    /** @var string Download URL template; %s is replaced with the URL-encoded tag name. */
    private const ZIP_URL = 'https://github.com/' . self::REPO . '/archive/refs/tags/%s.zip';

    /** @var string Site transient key used to cache the latest release data. */
    private const CACHE_KEY = 'eim_github_release';

    /** @var int Cache lifetime in seconds (12 hours). */
    private const CACHE_TTL = 43200;

    /** @var string Slug used in the WordPress updates API and row actions. */
    private const PLUGIN_SLUG = 'events-invite-manager';

    /** @var string Main plugin file name (relative to the plugin folder). */
    private const PLUGIN_FILE = 'events-invite-manager.php';

    /** @var string The folder name this plugin must always occupy under wp-content/plugins/. */
    private const DESIRED_FOLDER = 'events-invite-manager';

    /**
     * Registers all hooks needed for GitHub-based update checking.
     *
     * Called once from {@see \EventsInviteManager\Plugin::init()}.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'checkForUpdate']);
        add_filter('plugins_api',                           [self::class, 'pluginsApi'], 10, 3);

        // Runs after a successful install (priority 10), before WordPress's own
        // reactivate_plugin_after_upgrade (priority 15), so the canonical folder
        // is in place before reactivation is attempted.
        add_filter('upgrader_post_install', [self::class, 'normalizeFolderAfterInstall'], 10, 3);

        if (is_admin()) {
            add_filter('plugin_action_links_' . self::pluginBasename(), [self::class, 'addActionLinks']);
            add_action('admin_init',    [self::class, 'handleCheckForUpdates']);
            add_action('admin_notices', [self::class, 'maybeShowCheckedNotice']);
        }
    }

    /**
     * Injects an update entry into the WordPress update transient when a newer
     * GitHub release is available.
     *
     * @param false|object|array $transient The current value of the update_plugins transient.
     * @return false|object|array
     */
    public static function checkForUpdate(mixed $transient): mixed
    {
        if (empty($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = self::getLatestRelease();
        if (!$release) {
            return $transient;
        }

        $current = defined('EIM_VERSION') ? EIM_VERSION : '0.0.0';

        if (version_compare($release['version'], $current, '>')) {
            $pluginBasename = self::pluginBasename();

            $transient->response[$pluginBasename] = (object) [
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => $pluginBasename,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['zip_url'],
            ];
        }

        return $transient;
    }

    /**
     * Supplies plugin metadata for the "View version X details" thickbox popup
     * that appears on the Plugins and Updates screens.
     *
     * @param false|object|array $result The result so far (passed through when not handling).
     * @param string             $action The type of information being requested.
     * @param mixed              $args   Additional arguments, including the requested slug.
     * @return false|object|array
     */
    public static function pluginsApi(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $release = self::getLatestRelease();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name'          => 'Events Invite Manager',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => 'Chris Paschall',
            'homepage'      => $release['html_url'],
            'download_link' => $release['zip_url'],
            'sections'      => [
                'description' => 'Manages the inviting and registration of attendees for events, with a centralized location library, customizable email templates, and a front-end RSVP confirmation flow powered by the REST API.',
            ],
            'banners' => [],
        ];
    }

    /**
     * Adds a "Check for updates" link to the plugin's row on the Plugins screen.
     *
     * @param array<int|string, string> $links Existing action links for this plugin row.
     * @return array<int|string, string>
     */
    public static function addActionLinks(array $links): array
    {
        $check_url = wp_nonce_url(
            add_query_arg('action', 'eim_check_for_updates', self_admin_url('plugins.php')),
            'eim_check_for_updates',
            'eim_nonce'
        );

        $links[] = '<a href="' . esc_url($check_url) . '">Check for updates</a>';

        return $links;
    }

    /**
     * Handles the "Check for updates" admin action triggered from the Plugins screen.
     *
     * Clears the cached release data and the WordPress update_plugins transient so
     * the next page load performs a fresh check, then redirects back to the Plugins
     * screen with a query flag so maybeShowCheckedNotice() can display a result.
     *
     * @return void
     */
    public static function handleCheckForUpdates(): void
    {
        if (
            empty($_GET['action']) ||
            $_GET['action'] !== 'eim_check_for_updates' ||
            empty($_GET['eim_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eim_nonce'])), 'eim_check_for_updates') ||
            !current_user_can('update_plugins')
        ) {
            return;
        }

        // Clear cached release so the API is hit fresh.
        delete_site_transient(self::CACHE_KEY);

        // Force WordPress to re-evaluate all plugin updates immediately.
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(self_admin_url('plugins.php?eim_update_checked=1'));
        exit;
    }

    /**
     * Displays an admin notice after a manual "Check for updates" action completes.
     *
     * Shows whether a newer version is available, confirms the plugin is up to date,
     * or warns when the GitHub release check could not be completed.
     *
     * @return void
     */
    public static function maybeShowCheckedNotice(): void
    {
        if (empty($_GET['eim_update_checked']) || $_GET['eim_update_checked'] !== '1') {
            return;
        }

        $release = self::getLatestRelease();
        $current = defined('EIM_VERSION') ? EIM_VERSION : '0.0.0';

        if ($release && version_compare($release['version'], $current, '>')) {
            $msg = sprintf(
                'Events Invite Manager: Version <strong>%s</strong> is available. <a href="%s">Go to Updates</a>.',
                esc_html($release['version']),
                esc_url(self_admin_url('update-core.php'))
            );
        } elseif ($release) {
            $msg = 'Events Invite Manager: You are running the latest version.';
        } else {
            $msg = 'Events Invite Manager: Could not check for updates. The GitHub repository or release API may be unavailable.';
        }

        $noticeClass = $release ? 'notice-success' : 'notice-warning';

        echo '<div class="notice ' . esc_attr($noticeClass) . ' is-dismissible"><p>' . wp_kses_post($msg) . '</p></div>';
    }

    /**
     * Fetches the latest release from GitHub, caching the result for 12 hours to
     * avoid hitting the API rate limit on every page load.
     *
     * @return array{tag: string, version: string, zip_url: string, html_url: string}|null
     *         Null when the API request fails or the response is malformed.
     */
    private static function getLatestRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/Events-Invite-Manager',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $tag     = (string) $data['tag_name'];
        $version = ltrim($tag, 'v');

        $release = [
            'tag'      => $tag,
            'version'  => $version,
            'zip_url'  => sprintf(self::ZIP_URL, rawurlencode($tag)),
            'html_url' => isset($data['html_url']) ? (string) $data['html_url'] : '',
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Returns the plugin basename WordPress uses for update matching.
     *
     * Example: events-invite-manager/events-invite-manager.php
     *
     * @return string
     */
    private static function pluginBasename(): string
    {
        return self::DESIRED_FOLDER . '/' . self::PLUGIN_FILE;
    }

    /**
     * Moves the installed plugin directory to the canonical folder name after a
     * successful update.
     *
     * Runs at priority 10 on upgrader_post_install.  WordPress's own
     * Plugin_Upgrader::reactivate_plugin_after_upgrade() runs at priority 15, so
     * by the time reactivation is attempted the plugin file is already at the
     * correct path and activation succeeds without any active-plugins manipulation.
     *
     * @param mixed                $response   Pass-through value; a WP_Error here means the
     *                                         install already failed — we leave it unchanged.
     * @param mixed                $hook_extra Hook extra data (type, action, plugin basename).
     * @param array<string, mixed> $result     Result array from install_package(), including
     *                                         'destination' — the path WordPress chose.
     * @return mixed
     */
    public static function normalizeFolderAfterInstall(mixed $response, mixed $hook_extra, mixed $result): mixed
    {
        // Only handle our plugin.
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::pluginBasename()) {
            return $response;
        }

        // Don't touch a failed installation.
        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($result['destination'])) {
            return $response;
        }

        $desiredDir   = trailingslashit(WP_PLUGIN_DIR) . self::DESIRED_FOLDER;
        $installedDir = rtrim(wp_normalize_path((string) $result['destination']), '/');

        // Already in the right place — nothing to do.
        if ($installedDir === rtrim(wp_normalize_path($desiredDir), '/')) {
            return $response;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return $response;
        }

        // Confirm our plugin file is actually present in the installed directory.
        if (!$wp_filesystem->exists(trailingslashit($installedDir) . self::PLUGIN_FILE)) {
            return $response;
        }

        // If a stale correctly-named directory exists, back it up so we can
        // restore it if the rename below fails.
        $backupDir = null;
        if ($wp_filesystem->is_dir($desiredDir)) {
            $backupDir = $desiredDir . '_eim_backup';
            if (!$wp_filesystem->move($desiredDir, $backupDir)) {
                // Can't move the stale dir out of the way — fall back to deleting it.
                $wp_filesystem->delete($desiredDir, true);
                $backupDir = null;
            }
        }

        // Move to the canonical folder name.
        if (!$wp_filesystem->move($installedDir, $desiredDir)) {
            // Rename failed; restore the backup so the plugin is not left missing.
            if ($backupDir && $wp_filesystem->is_dir($backupDir)) {
                $wp_filesystem->move($backupDir, $desiredDir);
            }
            return $response;
        }

        // Rename succeeded — remove the backup.
        if ($backupDir && $wp_filesystem->is_dir($backupDir)) {
            $wp_filesystem->delete($backupDir, true);
        }

        wp_clean_plugins_cache(true);

        return $response;
    }
}
