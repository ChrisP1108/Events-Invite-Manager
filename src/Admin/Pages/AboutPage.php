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
    /**
     * No-op: the About page processes no form actions.
     *
     * @param string $_action The action slug (unused).
     */
    public function handleAction(string $_action): void {}

    /** Renders the About admin page. */
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

    /** Renders the branded plugin header banner. */
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
                <p>Manage private event invitations, grouped RSVPs, attendee registration, venue and lodging assignments, a global food &amp; beverage menu library with vendor linkage, a gifts &amp; registry system, invitee messaging, a guest-request workflow, budget tracking, newsletter posts, a unified category taxonomy, and automated email workflows — all from the WordPress admin.</p>
            </div>
        </div>
        <?php
    }

    /** Renders the numbered getting-started steps section. */
    private function renderGettingStarted(): void
    {
        $locationsUrl    = AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'add']);
        $vendorsUrl      = AdminMenu::tabUrl(AdminMenu::TAB_VENDORS);
        $categoriesUrl   = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES);
        $menuItemsUrl    = AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS);
        $eventsUrl       = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'add']);
        $inviteesUrl     = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES);
        $groupsUrl       = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS);
        $newslettersUrl  = AdminMenu::tabUrl(AdminMenu::TAB_NEWSLETTERS);
        $budgetUrl       = AdminMenu::tabUrl(AdminMenu::TAB_BUDGET);
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
                            Each location supports an optional thumbnail image from the Media Library; it appears in the Locations list and on the event edit screen wherever that location is shown.
                            <a href="<?= esc_url($locationsUrl); ?>">Add your first location →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">2</div>
                    <div>
                        <h3>Build your vendor library <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Vendors</strong> and add every service provider involved in your event — caterers, photographers, florists, and more.
                            Vendors are linked to food &amp; beverage items and budget line items so costs can be tracked by supplier.
                            <a href="<?= esc_url($vendorsUrl); ?>">Go to Vendors →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">3</div>
                    <div>
                        <h3>Set up categories <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Categories</strong> to build your taxonomy. Categories support one level of parent → child hierarchy and can be applied to any entity in the plugin — events, invitees, connection groups, locations, menu items, budget plans, vendors, and newsletters.
                            Assigned categories appear as clickable chips in every list table.
                            <a href="<?= esc_url($categoriesUrl); ?>">Go to Categories →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">4</div>
                    <div>
                        <h3>Build your menu item library <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Food &amp; Beverages</strong> and add the food and drink options you want to offer at your events.
                            Each item can be linked to a vendor, given a per-person price for budget calculations, and assigned categories.
                            Items are global — create them once and assign them to as many events as needed via an autocomplete picker on each event's edit screen.
                            <a href="<?= esc_url($menuItemsUrl); ?>">Go to Food &amp; Beverages →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">5</div>
                    <div>
                        <h3>Build your gifts &amp; registry <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Gifts &amp; Registry</strong> and add gifts with a name, description, price, optional website URL, and an optional image.
                            After creating gifts globally, link them to specific events on the event's <strong>Gifts &amp; Registry</strong> tab.
                            Invitees can mark gifts as purchased from their RSVP dashboard.
                            <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_GIFTS)); ?>">Go to Gifts &amp; Registry →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">6</div>
                    <div>
                        <h3>Create an event</h3>
                        <p>
                            Go to <strong>Events → Add New Event</strong>. The edit screen is tabbed — fill in the <strong>Details</strong> (including an optional RSVP deadline), set a venue on the <strong>Venue/Location</strong> tab, configure your invite email on the <strong>Invite Email</strong> tab, choose an RSVP page and Dashboard page on <strong>QR Code &amp; RSVP</strong>, and enable lodging or food/beverage options on their respective tabs.
                            After saving, assign food/beverage items on the <strong>Food &amp; Beverage</strong> tab, link gifts on the <strong>Gifts &amp; Registry</strong> tab, and manage invitees on the <strong>Invited Invitees</strong> tab.
                            <a href="<?= esc_url($eventsUrl); ?>">Create your first event →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">7</div>
                    <div>
                        <h3>Add your invitees</h3>
                        <p>
                            Go to <strong>Invitees</strong> and add each guest. The searchable invitee table supports a column-filter dropdown so you can search by First Name, Last Name, Email, Phone, Invited Events, or Connection Groups. A Categories column shows any categories you've assigned to each invitee.
                            <a href="<?= esc_url($inviteesUrl); ?>">Go to Invitees →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">8</div>
                    <div>
                        <h3>Create connection groups</h3>
                        <p>
                            Go to <strong>Connection Groups</strong> to define reusable relationships like couples, families, or households.
                            These groups become checkbox suggestions when adding invitees to an event.
                            The <strong>Invited To</strong> column shows which events each group has already been invited to.
                            <a href="<?= esc_url($groupsUrl); ?>">Go to Connection Groups →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">9</div>
                    <div>
                        <h3>Send invites</h3>
                        <p>
                            Open an event and navigate to the <strong>Invited Invitees</strong> tab. Add existing invitees and optionally check connected people to include them in the same invitation group.
                            The list supports AJAX live search and a column-filter dropdown.
                            Click <strong>Send Invite</strong> for one group or <strong>Send All Unsent Invites</strong>.
                            A unique QR code is generated automatically for each group — add <code>{{ qr_code }}</code>
                            anywhere in your invite email to embed the scannable image, or
                            <code>{{ invite_url }}</code> to include the RSVP link as text.
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">10</div>
                    <div>
                        <h3>Build your RSVP &amp; dashboard pages</h3>
                        <p>
                            Create a WordPress page for the RSVP flow and set it as the event's <strong>QR Code RSVP Page</strong>.
                            When an invitee scans their QR code the plugin redirects them to that page with
                            <code>?eim_confirmation={code}</code> in the URL.
                            The <code>next_action</code> field from <code>GET /eim/v1/rsvp</code> drives the multi-step flow:
                            RSVP form → menu selections → lodging → dashboard redirect.
                            Submit each step via <code>POST /eim/v1/register</code> with per-member RSVP statuses,
                            food/beverage selections, dietary notes, and lodging choice.
                            Optionally set a <strong>Dashboard Page</strong> — invitees land there after completing the flow.
                            Call <code>GET /eim/v1/dashboard</code> to load upcoming events, RSVP summaries, newsletters, and registry.
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">11</div>
                    <div>
                        <h3>Write newsletters <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Newsletters</strong> to create newsletter posts for email blasts or website display. Associate each post with one or more events, assign managed categories and tags, and use the TinyMCE editor to author HTML content. Click <strong>Preview Content</strong> to open a side-by-side live preview that refreshes automatically as you type.
                            <a href="<?= esc_url($newslettersUrl); ?>">Go to Newsletters →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">12</div>
                    <div>
                        <h3>Track your budget <em style="font-weight:400;color:#646970;">(optional)</em></h3>
                        <p>
                            Go to <strong>Budget</strong> to create a budget plan. Add line items with vendor, quantity, unit cost, and optional total overrides. Plans can span multiple events; the totals row shows estimated, paid, and remaining amounts at a glance.
                            <a href="<?= esc_url($budgetUrl); ?>">Go to Budget →</a>
                        </p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    /** Renders the feature card grid. */
    private function renderFeatures(): void
    {
        $features = [
            [
                'icon'  => 'dashicons-store',
                'title' => 'Vendor Library',
                'body'  => 'A centralised global library of service providers — caterers, photographers, florists, and more. Vendors are created once and linked to food & beverage items and budget line items so costs can be tracked by supplier. Each record stores company name, address, email, phone, and notes. The Vendors table has AJAX live search, sortable columns, and a Categories column.',
            ],
            [
                'icon'  => 'dashicons-tag',
                'title' => 'Categories & Taxonomy',
                'body'  => 'A unified category taxonomy spanning every entity in the plugin. Categories support one level of parent → child hierarchy and can be assigned to events, invitees, connection groups, locations, menu items, budget plans, vendors, and newsletters. Every list table shows a Categories column with teal chip links — clicking a chip opens the category editor. The category picker in add/edit forms also makes chip labels clickable.',
            ],
            [
                'icon'  => 'dashicons-location',
                'title' => 'Location Library',
                'body'  => 'A centralised library of reusable locations maintained independently of any event. Locations are created once and selected by name across events via live autocomplete. Each location supports an optional thumbnail image from the WordPress Media Library — the image appears in the Locations list table and wherever the location is shown on the event edit screen (venue and lodging panels). The Locations table has AJAX live search with a column-filter dropdown (Name, Type, Lodging, Address, Used In) and sortable columns.',
            ],
            [
                'icon'  => 'dashicons-food',
                'title' => 'Food & Beverages Library',
                'body'  => 'A global library of food and beverage menu items managed from the dedicated Food & Beverages page. Two independent scrollable tables (one per type) each have their own AJAX live search. Each item can be linked to a vendor, given a per-person price for budget calculations, and assigned categories. Items are created once and assigned to individual events via an autocomplete picker.',
            ],
            [
                'icon'  => 'dashicons-calendar-alt',
                'title' => 'Event Management',
                'body'  => 'Create events with name, description, date, start/end time, time zone, an optional invitee cap, RSVP deadline, and food/beverage option flags. The edit screen is organised into eight tabs — Details, Venue/Location, Invite Email, QR Code & RSVP, Lodging, Food & Beverage, Gifts & Registry, and Invited Invitees — with tab state persisted via localStorage and URL hash. A monthly calendar grid gives a visual overview of all dated events. The events list below the calendar supports AJAX live search, sortable columns, and pagination.',
            ],
            [
                'icon'  => 'dashicons-admin-home',
                'title' => 'Venue & Lodging Assignment',
                'body'  => 'Assign a venue and one or more lodging options to each event by searching the location library. The formatted address appears as read-only confirmation text. If the selected location has a thumbnail image, it is displayed alongside the venue name and in the lodging table. Lodging entries can be added, removed, and reordered on the event edit screen.',
            ],
            [
                'icon'  => 'dashicons-carrot',
                'title' => 'Food & Beverage Options (per event)',
                'body'  => 'When food or beverage options are enabled on an event, the Food & Beverage tab presents an autocomplete that searches the global library. Assigned items are returned by the RSVP API and stored as per-person selections on each group member. When both types are enabled, the food and beverage assignment tables are displayed side-by-side.',
            ],
            [
                'icon'  => 'dashicons-groups',
                'title' => 'Invitee Management',
                'body'  => 'Add guests globally with name, email, phone, and optional address. The Invitees table supports AJAX live search with a column-filter dropdown (First Name, Last Name, Email, Phone, Invited Events, Connection Groups), sortable columns, event tags, and connection group tags.',
            ],
            [
                'icon'  => 'dashicons-networking',
                'title' => 'Connection Groups',
                'body'  => 'Create reusable relationships for couples, families, households, or custom groups. The Connection Groups list has its own live search bar with a column-filter dropdown (Name, Type, Members, Invited To) and searches group names, member details, and event names. The Invited To column shows which events each group has been invited to as clickable event tags.',
            ],
            [
                'icon'  => 'dashicons-email-alt',
                'title' => 'Invitation Groups',
                'body'  => 'When adding invitees to an event, connected people can be checked into the same event-specific invitation group. One email and QR code are sent per group to the primary invitee. The Invited Invitees list supports AJAX live search and a column-filter dropdown (Group Members, Email, Invite Sent, Registered). A Confirmation Code column displays each group\'s unique 16-character QR code at a glance — useful for cross-referencing exports without opening them.',
            ],
            [
                'icon'  => 'dashicons-edit',
                'title' => 'Customisable Email Templates',
                'body'  => 'Write invite emails with full HTML support via the WordPress editor. Insert primary recipient details, group names/counts, a personalised QR code image, the matching RSVP URL, and event information using template tags.',
            ],
            [
                'icon'  => 'dashicons-shield-alt',
                'title' => 'QR Code RSVP',
                'body'  => 'Each invitation group receives a unique QR code. Scanning it redirects to the configured RSVP page with the confirmation code and lets the recipient RSVP for every member in the group, choose food/beverage preferences, and enter dietary notes.',
            ],
            [
                'icon'  => 'dashicons-products',
                'title' => 'Gifts & Registry',
                'body'  => 'A full gifts and registry system. Gifts are created globally with a name, description, price, optional website URL, and image, then linked to events on the event\'s Gifts & Registry tab. Invitees can view the registry on their dashboard and mark gifts as purchased. Purchase records track which invitation group claimed the gift, and only that group can unmark it.',
            ],
            [
                'icon'  => 'dashicons-format-chat',
                'title' => 'Invitee Messaging',
                'body'  => 'A conversation thread system between invitees and the admin, scoped per event and connection group. Invitees send messages via the REST API; admins read and reply from the Messages admin tab. Unread message counts are surfaced in the list. Admin replies are flagged separately so threads render as a chronological conversation.',
            ],
            [
                'icon'  => 'dashicons-admin-users',
                'title' => 'Requested Invitee Add-Ons',
                'body'  => 'Invitees can request additional guests be added to their invitation group via the REST API. Requests are queued in the Requested Invitees admin tab for review. Admins can approve (creating the invitee, adding them to the connection group and invitation group, and auto-RSVPing them as attending inside a DB transaction) or deny.',
            ],
            [
                'icon'  => 'dashicons-rest-api',
                'title' => 'REST API',
                'body'  => 'Nine JSON endpoints power the front-end experience: GET /rsvp and POST /register drive the multi-step RSVP flow (rsvp_required → menu → lodging → dashboard); GET /dashboard returns upcoming events, RSVP summaries, newsletters, and registry; GET /newsletters and GET /registry serve content to dashboard visitors; POST /registry/purchase marks gifts as purchased; POST /request-guest submits guest requests; and GET + POST /messages handle invitee/admin conversation threads.',
            ],
            [
                'icon'  => 'dashicons-admin-generic',
                'title' => 'eim_change Action Hook',
                'body'  => 'Every create, edit, and delete across all entities fires the eim_change WordPress action, passing an EimChangeEvent object with type, change_type (added/edited/deleted), and data. External code snippets can listen to any change without modifying the plugin. TYPE_* and change-type constants make listener code refactor-safe.',
            ],
            [
                'icon'  => 'dashicons-shield',
                'title' => 'Search & Validation',
                'body'  => 'All list tables share a contextual search bar with a column-filter dropdown. The search bar hides when the list is empty, and returns a distinct message when a search finds no matches. Venue/lodging/menu item fields enforce library validation — free-text entries are blocked server-side.',
            ],
            [
                'icon'  => 'dashicons-media-document',
                'title' => 'Newsletters',
                'body'  => 'Create newsletter posts for email blasts or website display. Each newsletter supports HTML content via the WordPress TinyMCE editor, status (draft/published), a publish date, many-to-many event associations, and managed categories and tags. A live side-by-side content preview renders the HTML in an isolated iframe and refreshes automatically as you type.',
            ],
            [
                'icon'  => 'dashicons-chart-bar',
                'title' => 'Budget Tracking',
                'body'  => 'Plan and track event costs with named budget plans that can span multiple events. Each plan contains categorised line items with vendor, quantity (fixed or per-attending-guest), unit cost, total override, and paid amount. Estimated, paid, and remaining totals are computed and displayed in a summary row. Line items support drag-to-reorder.',
            ],
            [
                'icon'  => 'dashicons-download',
                'title' => 'Data Exports (CSV & JSON)',
                'body'  => 'Export buttons appear above the tab navigation on every event and budget plan edit screen. Event exports include all invited invitees with QR confirmation codes and image URLs, food/beverage/lodging selections, registry items (claimed and available), and invitee/admin messages. Budget exports include plan totals, a vendors section with database IDs, and line items with vendor_id references. Both formats are available: multi-section CSV for spreadsheet use, and structured JSON for programmatic use.',
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

    /** Renders the email template-tag reference table. */
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
                    <tr><td><code>{{ qr_code }}</code></td><td>An <code>&lt;img&gt;</code> tag containing the invitation group's unique PNG QR code image, displayed at 480 × 480 px</td></tr>
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

    /** Renders the REST API endpoint reference section. */
    private function renderRestApi(): void
    {
        $baseUrl = rest_url('eim/v1');
        ?>
        <div class="eim-about-section">
            <h2>REST API Reference</h2>
            <p style="color:#50575e;font-size:13px;margin-bottom:16px;">
                Base URL: <code><?= esc_html($baseUrl); ?></code><br>
                All endpoints are public — access is gated by the 16-character confirmation code embedded in each invitation's QR code URL.
            </p>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method" style="background:#2e7d32;">GET</span>
                    <code>/wp-json/eim/v1/rsvp?confirmation_code={code}</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Returns the current RSVP flow state: event details, food/beverage options, lodging options, all group members, and a <code>next_action</code> field (<code>rsvp_required</code>, <code>menu_required</code>, <code>lodging_required</code>, <code>dashboard_redirect</code>, or <code>declined</code>) that tells the frontend exactly what to present next. Call this on every RSVP page load.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/register</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Validates the confirmation code and updates RSVP status for the invitation group. With a <code>members</code> array each listed member can be set individually with RSVP status, food/beverage selection, dietary notes, and lodging choice. Members omitted while still pending are auto-declined. Accepts top-level lodging fields (<code>lodging_id</code>, <code>lodging_is_other</code>, <code>lodging_undisclosed</code>, <code>lodging_booked</code>, <code>lodging_notes</code>) and shared <code>rsvp_notes</code>. Returns the full updated flow state including the new <code>next_action</code>.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method" style="background:#2e7d32;">GET</span>
                    <code>/wp-json/eim/v1/dashboard?confirmation_code={code}</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Returns all upcoming events the primary invitee has been invited to, including pending, declined, incomplete, and registered events. Each event includes its per-event <code>confirmation_code</code>, <code>invitation_group_id</code>, RSVP details with invitee contact information, published newsletters when the event flow is complete, and registry data when available. Requires the current QR code's RSVP flow to be complete before the dashboard can load.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method" style="background:#2e7d32;">GET</span>
                    <code>/wp-json/eim/v1/newsletters?confirmation_code={code}</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Returns published newsletters for all complete, upcoming events the group is registered for. Pass <code>newsletter_id</code> to fetch a single newsletter's full content. Requires a complete RSVP flow.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method" style="background:#2e7d32;">GET</span>
                    <code>/wp-json/eim/v1/registry?confirmation_code={code}</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Returns registry gifts for all complete, upcoming events accessible from the confirmation code. Pass <code>event_id</code> to filter to one event. Each gift includes purchase status and a flag indicating whether the current group is the purchasing group.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/registry/purchase</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Marks or unmarks a gift as purchased for a specific event. A group can only unmark a gift it previously marked. Fields: <code>confirmation_code</code>, <code>event_id</code>, <code>gift_id</code>, <code>is_purchased</code> (boolean, defaults true). Returns the updated gift object.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/request-guest</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Submits a pending request to add an additional guest to the invitation group. Fields: <code>confirmation_code</code>, <code>first_name</code>, <code>last_name</code>, <code>email</code> (required), plus optional <code>phone</code>, <code>street_address</code>, <code>city</code>, <code>state</code>, <code>zip_code</code>, <code>notes</code>. Prevents duplicate pending requests for the same email.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method" style="background:#2e7d32;">GET</span>
                    <code>/wp-json/eim/v1/messages?confirmation_code={code}&amp;event_id={id}</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Returns all messages in the conversation thread for the given event and connection group. The <code>event_id</code> must match the supplied confirmation code's event. The response includes <code>connection_group_id</code>, and each message includes <code>is_admin_reply</code> and <code>is_read</code> flags.</p>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/messages</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Sends a new invitee message for a specific event/group thread. Fields: <code>confirmation_code</code>, <code>event_id</code>, <code>message</code>. Returns the new message ID.</p>
                </div>
            </div>
        </div>
        <?php
    }
}
