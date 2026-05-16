<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;

/**
 * Renders the plugin's About page in the WordPress admin.
 *
 * The page provides a full reference for administrators: an overview of the
 * plugin's purpose, a step-by-step getting started guide, a feature catalogue,
 * the complete list of email template tags, and the REST API endpoint reference.
 * It is intentionally read-only — no form submissions are processed here.
 */
final class AboutPage extends AbstractAdminPage
{
    public function handleAction(string $_action): void {}

    public function renderPage(): void
    {
        ?>
        <div class="wrap">

            <?php $this->renderHeader(); ?>
            <?php $this->renderGettingStarted(); ?>
            <?php $this->renderFeatures(); ?>
            <?php $this->renderTemplateTags(); ?>
            <?php $this->renderRestApi(); ?>

        </div>

        <style>
        .eim-about-header{background:#2271b1;color:#fff;border-radius:4px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:20px;}
        .eim-about-header h1{color:#fff;margin:0;font-size:1.6em;}
        .eim-about-header p{color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px;}
        .eim-about-header .dashicons{font-size:48px;width:48px;height:48px;opacity:.9;}
        .eim-about-version{background:rgba(255,255,255,.2);border-radius:3px;padding:2px 10px;font-size:12px;font-weight:600;margin-left:12px;vertical-align:middle;}

        .eim-about-section{margin-bottom:28px;}
        .eim-about-section h2{font-size:1.15em;border-bottom:2px solid #2271b1;padding-bottom:8px;margin-bottom:16px;color:#1d2327;}

        .eim-about-steps{counter-reset:eim-step;display:grid;gap:16px;}
        .eim-about-step{display:flex;gap:16px;align-items:flex-start;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px;}
        .eim-about-step-num{counter-increment:eim-step;background:#2271b1;color:#fff;border-radius:50%;width:28px;height:28px;min-width:28px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
        .eim-about-step h3{margin:0 0 4px;font-size:14px;}
        .eim-about-step p{margin:0;color:#50575e;font-size:13px;line-height:1.6;}

        .eim-about-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;}
        .eim-about-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px 20px;}
        .eim-about-card h3{margin:0 0 8px;font-size:13px;display:flex;align-items:center;gap:8px;color:#1d2327;}
        .eim-about-card h3 .dashicons{color:#2271b1;font-size:18px;width:18px;height:18px;}
        .eim-about-card p{margin:0;color:#50575e;font-size:13px;line-height:1.6;}

        .eim-about-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;}
        .eim-about-table th{background:#f6f7f7;padding:10px 14px;text-align:left;font-size:12px;font-weight:600;color:#1d2327;border-bottom:1px solid #dcdcde;text-transform:uppercase;letter-spacing:.04em;}
        .eim-about-table td{padding:9px 14px;font-size:13px;border-bottom:1px solid #f0f0f1;color:#3c434a;vertical-align:top;}
        .eim-about-table tr:last-child td{border-bottom:none;}
        .eim-about-table code{background:#f6f7f7;padding:2px 6px;border-radius:3px;font-size:12px;color:#d63638;}

        .eim-about-endpoint{background:#fff;border:1px solid #dcdcde;border-radius:4px;margin-bottom:16px;overflow:hidden;}
        .eim-about-endpoint-header{background:#f6f7f7;padding:12px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #dcdcde;}
        .eim-about-method{background:#2271b1;color:#fff;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:700;letter-spacing:.05em;}
        .eim-about-endpoint-header code{font-size:13px;color:#1d2327;background:none;padding:0;}
        .eim-about-endpoint-body{padding:14px 16px;}
        .eim-about-endpoint-body p{margin:0 0 10px;color:#50575e;font-size:13px;}
        .eim-about-endpoint-body p:last-child{margin-bottom:0;}
        </style>
        <?php
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function renderHeader(): void
    {
        ?>
        <div class="eim-about-header">
            <span class="dashicons dashicons-calendar-alt"></span>
            <div>
                <h1>
                    Events Invite Manager
                    <span class="eim-about-version">v<?= esc_html(EIM_VERSION); ?></span>
                </h1>
                <p>Manage private event invitations, grouped RSVPs, attendee registration, venue and lodging assignments, a global food &amp; beverage menu library, and automated email workflows — all from the WordPress admin.</p>
            </div>
        </div>
        <?php
    }

    private function renderGettingStarted(): void
    {
        $locationsUrl  = admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=add');
        $menuItemsUrl  = admin_url('admin.php?page=' . AdminMenu::PAGE_MENU_ITEMS);
        $eventsUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add');
        $inviteesUrl   = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES);
        $groupsUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_CONNECTION_GROUPS);
        ?>
        <div class="eim-about-section">
            <h2>Getting Started</h2>
            <div class="eim-about-steps">

                <div class="eim-about-step">
                    <div class="eim-about-step-num">1</div>
                    <div>
                        <h3>Build your location library</h3>
                        <p>
                            Go to <strong>Locations</strong> and add every venue, hotel, and lodging option you plan to use.
                            Venue and lodging fields on events only accept validated library entries — free-text is blocked.
                            <a href="<?= esc_url($locationsUrl); ?>">Add your first location →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">2</div>
                    <div>
                        <h3>Build your menu item library <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Food &amp; Beverages</strong> and add the food and drink options you want to offer at your events.
                            Items are global — create them once and assign them to as many events as needed via an autocomplete picker on each event's edit screen.
                            <a href="<?= esc_url($menuItemsUrl); ?>">Go to Food &amp; Beverages →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">3</div>
                    <div>
                        <h3>Create an event</h3>
                        <p>
                            Go to <strong>Events → Add New Event</strong>. Set the date, time zone, venue (search the library), and optionally enable lodging, food options, and/or beverage options.
                            Select a <strong>QR Code RSVP Page</strong> — the WordPress page recipients land on after scanning their QR code.
                            Customise the invite email template with your messaging before saving.
                            After saving, assign food/beverage items to the event from the global library.
                            <a href="<?= esc_url($eventsUrl); ?>">Create your first event →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">4</div>
                    <div>
                        <h3>Add your invitees</h3>
                        <p>
                            Go to <strong>Invitees</strong> and add each guest. The searchable invitee table supports a column-filter dropdown so you can search by First Name, Last Name, Email, Phone, Invited Events, or Connection Groups.
                            <a href="<?= esc_url($inviteesUrl); ?>">Go to Invitees →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">5</div>
                    <div>
                        <h3>Create connection groups</h3>
                        <p>
                            Go to <strong>Connection Groups</strong> to define reusable relationships like couples, families, or households.
                            These groups become checkbox suggestions when adding invitees to an event.
                            <a href="<?= esc_url($groupsUrl); ?>">Go to Connection Groups →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">6</div>
                    <div>
                        <h3>Send invites</h3>
                        <p>
                            Open an event, add existing invitees to its <strong>Invited Invitees</strong> list, and optionally check connected people to include them in the same invitation group.
                            The invited invitees list supports AJAX live search (including a column-filter dropdown) so you can quickly find groups as the event grows.
                            Click <strong>Send Invite</strong> for one group or <strong>Send All Unsent Invites</strong>.
                            A unique QR code is generated automatically for each group — add <code>{{ qr_code }}</code>
                            anywhere in your invite email to embed the scannable image, or
                            <code>{{ invite_url }}</code> to include the RSVP link as text.
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">7</div>
                    <div>
                        <h3>Build your RSVP page</h3>
                        <p>
                            Create a WordPress page and set it as the event's <strong>QR Code RSVP Page</strong>.
                            When an invitee scans their QR code the plugin redirects them to that page with
                            <code>?eim_confirmation={code}</code> in the URL. Call
                            <code>GET /wp-json/eim/v1/rsvp?confirmation_code={code}</code> to load the
                            primary invitee, all group members, event details, food/beverage options, and lodging.
                            Submit via <code>POST /wp-json/eim/v1/register</code> with per-person RSVP statuses,
                            food/beverage selections, and optional dietary notes.
                        </p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    private function renderFeatures(): void
    {
        $features = [
            [
                'icon'  => 'dashicons-location',
                'title' => 'Location Library',
                'body'  => 'A centralised library of reusable locations maintained independently of any event. Locations are created once and selected by name across events via live autocomplete. The Locations table has AJAX live search with a column-filter dropdown (Name, Type, Lodging, Address, Used In) and sortable columns.',
            ],
            [
                'icon'  => 'dashicons-food',
                'title' => 'Food & Beverages Library',
                'body'  => 'A global library of food and beverage menu items managed from the dedicated Food & Beverages page. Two independent scrollable tables (one per type) each have their own AJAX live search. Items are created once and assigned to individual events via an autocomplete picker — the same pattern as venue and lodging.',
            ],
            [
                'icon'  => 'dashicons-calendar-alt',
                'title' => 'Event Management',
                'body'  => 'Create events with name, description, date, start/end time, time zone, an optional invitee cap, and food/beverage option flags. A monthly calendar grid gives a visual overview of all dated events.',
            ],
            [
                'icon'  => 'dashicons-admin-home',
                'title' => 'Venue & Lodging Assignment',
                'body'  => 'Assign a venue and one or more lodging options to each event by searching the location library. The formatted address appears as read-only confirmation text. Lodging entries can be added and removed on the event edit screen.',
            ],
            [
                'icon'  => 'dashicons-carrot',
                'title' => 'Food & Beverage Options (per event)',
                'body'  => 'When food or beverage options are enabled on an event, the edit screen presents an autocomplete that searches the global library. Assigned items are returned by the RSVP API and stored as per-person selections (food_option_id / beverage_option_id) on each group member when they register.',
            ],
            [
                'icon'  => 'dashicons-groups',
                'title' => 'Invitee Management',
                'body'  => 'Add guests globally with name, email, phone, and optional address. The Invitees table supports AJAX live search with a column-filter dropdown (First Name, Last Name, Email, Phone, Invited Events, Connection Groups), sortable columns, event tags, and connection group tags.',
            ],
            [
                'icon'  => 'dashicons-networking',
                'title' => 'Connection Groups',
                'body'  => 'Create reusable relationships for couples, families, households, or custom groups. The Connection Groups list has its own live search bar with a column-filter dropdown (Name, Type, Members) and searches both group names and member details.',
            ],
            [
                'icon'  => 'dashicons-email-alt',
                'title' => 'Invitation Groups',
                'body'  => 'When adding invitees to an event, connected people can be checked into the same event-specific invitation group. One email and QR code are sent per group to the primary invitee. The Invited Invitees list supports AJAX live search and a column-filter dropdown (Group Members, Email, Invite Sent, Registered).',
            ],
            [
                'icon'  => 'dashicons-edit',
                'title' => 'Customisable Email Templates',
                'body'  => 'Write invite emails with full HTML support via the WordPress editor. Insert primary recipient details, group names/counts, a personalised QR code image, the matching RSVP URL, and event information using template tags.',
            ],
            [
                'icon'  => 'dashicons-qrcode',
                'title' => 'QR Code RSVP',
                'body'  => 'Each invitation group receives a unique QR code. Scanning it redirects to the configured RSVP page with the confirmation code and lets the recipient RSVP for every member in the group, choose food/beverage preferences, and enter dietary notes.',
            ],
            [
                'icon'  => 'dashicons-rest-api',
                'title' => 'REST API',
                'body'  => 'Two JSON endpoints power the RSVP page: GET /rsvp loads event details, food/beverage options, lodging, and all group members with their current RSVP status; POST /register updates per-person RSVP status, food/beverage selections, and dietary notes.',
            ],
            [
                'icon'  => 'dashicons-shield',
                'title' => 'Search & Validation',
                'body'  => 'All list tables share a contextual search bar with a column-filter dropdown. The search bar hides when the list is empty, and returns a distinct message when a search finds no matches. Venue/lodging/menu item fields enforce library validation — free-text entries are blocked server-side.',
            ],
        ];
        ?>
        <div class="eim-about-section">
            <h2>Features</h2>
            <div class="eim-about-grid">
                <?php foreach ($features as $feature): ?>
                    <div class="eim-about-card">
                        <h3>
                            <span class="dashicons <?= esc_attr($feature['icon']); ?>"></span>
                            <?= esc_html($feature['title']); ?>
                        </h3>
                        <p><?= esc_html($feature['body']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function renderTemplateTags(): void
    {
        ?>
        <div class="eim-about-section">
            <h2>Email Template Tags</h2>
            <p style="color:#50575e;font-size:13px;margin-bottom:16px;">
                Tags are replaced with live values at send time. They are case-insensitive and tolerant of spaces
                inside the braces — <code>{{ event_name }}</code> and <code>{{event_name}}</code> are equivalent.
            </p>

            <h3 style="font-size:13px;margin-bottom:8px;">Invite email body</h3>
            <table class="eim-about-table" style="margin-bottom:16px;">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Replaced with</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>{{ event_name }}</code></td><td>The event's name</td></tr>
                    <tr><td><code>{{ first_name }}</code></td><td>Primary invitee's first name</td></tr>
                    <tr><td><code>{{ last_name }}</code></td><td>Primary invitee's last name</td></tr>
                    <tr><td><code>{{ full_name }}</code></td><td>First and last name combined</td></tr>
                    <tr><td><code>{{ email }}</code></td><td>Primary invitee's email address</td></tr>
                    <tr><td><code>{{ qr_code }}</code></td><td>An <code>&lt;img&gt;</code> tag containing the invitation group's unique QR code image (300 × 300 px)</td></tr>
                    <tr><td><code>{{ invite_url }}</code></td><td>The personalised RSVP URL encoded in the QR code — useful as a text fallback when email clients block images</td></tr>
                    <tr><td><code>{{ group_names }}</code></td><td>Comma-separated names of every invitee in the invitation group</td></tr>
                    <tr><td><code>{{ invitee_names }}</code></td><td>Alias of <code>{{ group_names }}</code></td></tr>
                    <tr><td><code>{{ invitee_count }}</code></td><td>Number of people in the invitation group</td></tr>
                </tbody>
            </table>

            <h3 style="font-size:13px;margin-bottom:8px;">From Email field</h3>
            <table class="eim-about-table">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Replaced with</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>{{ current_domain }}</code></td>
                        <td>The site's domain at send time — useful for <code>noreply@{{current_domain}}</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderRestApi(): void
    {
        $baseUrl = rest_url('eim/v1');
        ?>
        <div class="eim-about-section">
            <h2>REST API Reference</h2>
            <p style="color:#50575e;font-size:13px;margin-bottom:16px;">
                Base URL: <code><?= esc_html($baseUrl); ?></code><br>
                Both endpoints are public — access is gated by the 16-character confirmation code embedded in each invitation's QR code URL.
            </p>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method" style="background:#2e7d32;">GET</span>
                    <code>/wp-json/eim/v1/rsvp?confirmation_code={code}</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Looks up the confirmation code and returns event details, the food and beverage options assigned to the event, lodging options, and all members in the invitation group. Call this on RSVP page load to personalise the page before the recipient confirms.</p>
                    <table class="eim-about-table" style="margin-bottom:10px;">
                        <thead><tr><th>Param</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>confirmation_code</code></td><td>string</td><td>Yes</td><td>16-character code from the QR code URL</td></tr>
                        </tbody>
                    </table>
                    <p>
                        A successful response contains:
                        <code>event</code> (name, description, date, venue),
                        <code>rsvp_options</code> (food and beverage arrays, each with id, label, description, sort_order — empty arrays when the option is not enabled on the event),
                        <code>invitee</code> (primary recipient's first_name, last_name, email),
                        <code>group_members</code> (invitee_id, name, email, rsvp_status, registered_at, food_option_id, beverage_option_id, dietary_notes),
                        and <code>lodging</code> (name, address, booking_url, is_other).
                    </p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/register</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Validates the confirmation code and updates RSVP status for the invitation group. If no <code>members</code> array is provided, all pending members are marked as attending (backward compatible). With a <code>members</code> array, each listed member can be set individually with RSVP status, food/beverage selection, and dietary notes.</p>
                    <table class="eim-about-table" style="margin-bottom:10px;">
                        <thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>confirmation_code</code></td><td>string</td><td>Yes</td><td>16-character code from the QR code URL</td></tr>
                            <tr><td><code>members</code></td><td>array</td><td>No</td><td>
                                List of objects, one per group member, each containing:
                                <code>invitee_id</code> (int, required),
                                <code>rsvp_status</code> (string: <code>attending</code>, <code>declined</code>, or <code>pending</code>),
                                <code>food_option_id</code> (int, optional — must be an active item assigned to this event),
                                <code>beverage_option_id</code> (int, optional — same validation),
                                <code>dietary_notes</code> (string, optional).
                            </td></tr>
                        </tbody>
                    </table>
                    <p>A successful response includes <code>success</code>, <code>already_registered</code> (true when all members were already attending), <code>message</code>, and an <code>invitee</code> object for the primary recipient.</p>
                </div>
            </div>
        </div>
        <?php
    }
}
