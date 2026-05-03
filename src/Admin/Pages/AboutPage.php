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
     * No-op: the About page handles no form submissions or GET actions.
     *
     * @param string $action
     * @return void
     */
    public function handleAction(string $action): void {}

    /**
     * Renders the About admin page.
     *
     * @return void
     */
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

    /**
     * Renders the page header banner with the plugin name, tagline, and version.
     *
     * @return void
     */
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
                <p>Manage private event invitations, attendee registration, venue and lodging assignments, and automated email workflows — all from the WordPress admin.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the numbered Getting Started guide.
     *
     * @return void
     */
    private function renderGettingStarted(): void
    {
        $locationsUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=add');
        $eventsUrl    = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add');
        $inviteesUrl  = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES);
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
                        <h3>Create an event</h3>
                        <p>
                            Go to <strong>Events → Add New Event</strong>. Set the date, time, RSVP page, venue (search the library), and optionally add lodging options.
                            Configure the invite and confirmation email templates with your messaging before saving.
                            <a href="<?= esc_url($eventsUrl); ?>">Create your first event →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">3</div>
                    <div>
                        <h3>Add your invitees</h3>
                        <p>
                            Open the event's <strong>Invitees</strong> list and add each guest with their name and email address.
                            Every invitee receives a unique invite code automatically — no manual code management required.
                            <a href="<?= esc_url($inviteesUrl); ?>">Go to Invitees →</a>
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">4</div>
                    <div>
                        <h3>Send invites</h3>
                        <p>
                            From the Invitees list, click <strong>Send Invite</strong> for an individual guest or use
                            <strong>Send All Unsent</strong> to dispatch to everyone who hasn't received an invite yet.
                            Each email contains the guest's personal RSVP link with their invite code embedded.
                        </p>
                    </div>
                </div>

                <div class="eim-about-step">
                    <div class="eim-about-step-num">5</div>
                    <div>
                        <h3>Build your RSVP page</h3>
                        <p>
                            Create a WordPress page, assign it to the event's <strong>RSVP Page</strong> field, and wire up
                            the two-step registration flow using the REST API: first call
                            <code>/wp-json/eim/v1/request-code</code> with the guest's email to send a 6-digit code,
                            then call <code>/wp-json/eim/v1/register</code> to verify it and mark them as attending.
                        </p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Renders the features card grid.
     *
     * @return void
     */
    private function renderFeatures(): void
    {
        $features = [
            [
                'icon'  => 'dashicons-location',
                'title' => 'Location Library',
                'body'  => 'A centralized library of reusable locations maintained independently of any event. Locations are created once and selected by name across events via live autocomplete search.',
            ],
            [
                'icon'  => 'dashicons-calendar-alt',
                'title' => 'Event Management',
                'body'  => 'Create events with name, description, date, start/end time, and a linked RSVP page. A monthly calendar grid gives a visual overview of all dated events.',
            ],
            [
                'icon'  => 'dashicons-admin-home',
                'title' => 'Venue Assignment',
                'body'  => 'Assign a venue to each event by searching the location library. The formatted address appears as read-only confirmation text beneath the autocomplete field.',
            ],
            [
                'icon'  => 'dashicons-building',
                'title' => 'Lodging Locations',
                'body'  => 'Add multiple lodging options per event — hotels, personal arrangements, and more. Each is selected from the location library and presented to invitees as choices on the RSVP page.',
            ],
            [
                'icon'  => 'dashicons-groups',
                'title' => 'Invitee Management',
                'body'  => 'Add guests with name, email, and optional address. Every invitee gets a unique cryptographically generated invite code embedded in their personal RSVP link.',
            ],
            [
                'icon'  => 'dashicons-email-alt',
                'title' => 'Customizable Email Templates',
                'body'  => 'Write invite and confirmation code emails with full HTML support via the WordPress editor. Insert guest details, RSVP links, and event information using template tags.',
            ],
            [
                'icon'  => 'dashicons-lock',
                'title' => 'Two-Step RSVP Flow',
                'body'  => 'Guests enter their email to receive a 6-digit confirmation code (valid 15 minutes). Entering the code marks them as registered — preventing unauthorized sign-ups without friction for genuine guests.',
            ],
            [
                'icon'  => 'dashicons-rest-api',
                'title' => 'REST API',
                'body'  => 'Two JSON endpoints power the front-end RSVP experience: request-code validates the guest and sends the code; register verifies it and confirms attendance.',
            ],
            [
                'icon'  => 'dashicons-shield',
                'title' => 'Library Validation',
                'body'  => 'Venue and lodging fields use browser-native form validation to prevent saving free-text entries. Server-side validation provides a second layer of enforcement on every save.',
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

    /**
     * Renders the email template tags reference tables.
     *
     * @return void
     */
    private function renderTemplateTags(): void
    {
        ?>
        <div class="eim-about-section">
            <h2>Email Template Tags</h2>
            <p style="color:#50575e;font-size:13px;margin-bottom:16px;">
                Tags are replaced with live values at send time. They are case-insensitive and tolerant of spaces
                inside the braces — <code>{{ event_name }}</code> and <code>{{event_name}}</code> are equivalent.
            </p>

            <h3 style="font-size:13px;margin-bottom:8px;">Invite email</h3>
            <table class="eim-about-table" style="margin-bottom:16px;">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Replaced with</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>{{ event_name }}</code></td><td>The event's name</td></tr>
                    <tr><td><code>{{ first_name }}</code></td><td>Invitee's first name</td></tr>
                    <tr><td><code>{{ last_name }}</code></td><td>Invitee's last name</td></tr>
                    <tr><td><code>{{ full_name }}</code></td><td>First and last name combined</td></tr>
                    <tr><td><code>{{ email }}</code></td><td>Invitee's email address</td></tr>
                    <tr><td><code>{{ invite_code }}</code></td><td>The invitee's unique invite code</td></tr>
                    <tr><td><code>{{ rsvp_url }}</code></td><td>Full RSVP page URL with <code>?invite_code=…&amp;event_id=…</code> appended</td></tr>
                </tbody>
            </table>

            <h3 style="font-size:13px;margin-bottom:8px;">Confirmation code email</h3>
            <table class="eim-about-table" style="margin-bottom:16px;">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Replaced with</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>{{ confirmation_code }}</code></td><td>The 6-digit code sent to the invitee</td></tr>
                </tbody>
            </table>

            <h3 style="font-size:13px;margin-bottom:8px;">From Email field (both emails)</h3>
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

    /**
     * Renders the REST API endpoint reference.
     *
     * @return void
     */
    private function renderRestApi(): void
    {
        $baseUrl = rest_url('eim/v1');
        ?>
        <div class="eim-about-section">
            <h2>REST API Reference</h2>
            <p style="color:#50575e;font-size:13px;margin-bottom:16px;">
                Base URL: <code><?= esc_html($baseUrl); ?></code>
            </p>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/request-code</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Validates that the supplied email belongs to an invitee for the given event, generates a 6-digit confirmation code, stores it as a transient for 15 minutes, and sends it to the invitee's email address.</p>
                    <table class="eim-about-table">
                        <thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>email</code></td><td>string</td><td>Yes</td><td>Invitee's email address</td></tr>
                            <tr><td><code>event_id</code></td><td>integer</td><td>Yes</td><td>Event ID</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="eim-about-endpoint">
                <div class="eim-about-endpoint-header">
                    <span class="eim-about-method">POST</span>
                    <code>/wp-json/eim/v1/register</code>
                </div>
                <div class="eim-about-endpoint-body">
                    <p>Verifies the confirmation code against the stored transient and marks the invitee as registered. The transient is deleted immediately after a successful match to prevent replay attacks.</p>
                    <table class="eim-about-table">
                        <thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>email</code></td><td>string</td><td>Yes</td><td>Invitee's email address</td></tr>
                            <tr><td><code>code</code></td><td>string</td><td>Yes</td><td>6-digit confirmation code</td></tr>
                            <tr><td><code>event_id</code></td><td>integer</td><td>Yes</td><td>Event ID</td></tr>
                        </tbody>
                    </table>
                    <p style="margin-top:10px;">
                        A successful response includes <code>success</code>, <code>already_registered</code>, and an
                        <code>invitee</code> object with <code>first_name</code>, <code>last_name</code>, and <code>email</code>
                        so the RSVP page can display a personalised confirmation.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
