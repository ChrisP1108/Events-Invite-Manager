<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\LocationLibrary;

/**
 * Handles event-related admin actions and rendering.
 */
final class EventsPage extends AbstractAdminPage
{
    /**
     * Dispatches event-page form submissions and GET actions.
     *
     * @param string $action
     * @return void
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_event'                => $this->handleSaveEvent(),
            'delete_event'              => $this->handleDeleteEvent(),
            'add_lodging_to_event'      => $this->handleAddLodgingToEvent(),
            'remove_lodging_from_event' => $this->handleRemoveLodgingFromEvent(),
            default                     => null,
        };
    }

    /**
     * Renders the Events admin page, dispatching to the list or add/edit form.
     *
     * @return void
     */
    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderEventForm(null),
            'edit'  => $this->renderEventForm(Event::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderEventsList(),
        };
    }

    /**
     * Processes creating or updating an event from the admin form.
     *
     * @return void
     */
    private function handleSaveEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_event')) {
            wp_die('Security check failed.');
        }

        $id   = (int) ($_POST['event_id'] ?? 0);
        $data = [
            'name'                        => sanitize_text_field($_POST['name'] ?? ''),
            'description'                 => sanitize_textarea_field($_POST['description'] ?? ''),
            'rsvp_page_url'               => esc_url_raw($_POST['rsvp_page_url'] ?? ''),
            'from_name'                   => sanitize_text_field($_POST['from_name'] ?? ''),
            'from_email'                  => $this->sanitizeFromEmailTemplate((string) ($_POST['from_email'] ?? '')),
            'invite_email_subject'        => sanitize_text_field($_POST['invite_email_subject'] ?? ''),
            'invite_email_template'       => wp_kses_post($_POST['invite_email_template'] ?? ''),
            'confirmation_email_subject'  => sanitize_text_field($_POST['confirmation_email_subject'] ?? ''),
            'confirmation_email_template' => wp_kses_post($_POST['confirmation_email_template'] ?? ''),
            'event_date'                  => sanitize_text_field($_POST['event_date'] ?? ''),
            'start_time'                  => sanitize_text_field($_POST['start_time'] ?? ''),
            'end_time'                    => sanitize_text_field($_POST['end_time'] ?? ''),
            'lodging_enabled'             => !empty($_POST['lodging_enabled']) ? 1 : 0,
        ];

        if (empty($data['name'])) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'name_required',
            ], admin_url('admin.php')));
            exit;
        }

        // Validate venue library selection.
        $venueName      = sanitize_text_field($_POST['venue_name'] ?? '');
        $venueLibraryId = (int) ($_POST['venue_library_id'] ?? 0);
        if ($venueName !== '' && ($venueLibraryId <= 0 || LocationLibrary::find($venueLibraryId) === null)) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'venue_invalid_location',
            ], admin_url('admin.php')));
            exit;
        }

        // Validate initial lodging selections (new events only; arrays from multi-row form).
        if ($id === 0) {
            $lodgingNames      = $_POST['lodging_init_name']       ?? [];
            $lodgingLibraryIds = $_POST['lodging_init_library_id'] ?? [];
            if (is_array($lodgingNames)) {
                foreach ($lodgingNames as $i => $rawName) {
                    if (sanitize_text_field($rawName) === '') continue;
                    $libId = (int) ($lodgingLibraryIds[$i] ?? 0);
                    if ($libId <= 0 || LocationLibrary::find($libId) === null) {
                        wp_redirect(add_query_arg([
                            'page'      => AdminMenu::PAGE_EVENTS,
                            'action'    => 'add',
                            'eim_error' => 'lodging_invalid_location',
                        ], admin_url('admin.php')));
                        exit;
                    }
                }
            }
        }

        if ($id > 0) {
            Event::update($id, $data);
            $this->saveVenueLocation($id);
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_EVENTS,
                'eim_message' => 'event_updated',
            ], admin_url('admin.php')));
        } else {
            $newId = Event::create($data);
            if ($newId) {
                $this->saveVenueLocation($newId);
                $this->saveInitialLodgingLocation($newId);
            }
            // Redirect to edit so the admin can continue managing lodging locations.
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_EVENTS,
                'action'      => 'edit',
                'id'          => $newId ?: 0,
                'eim_message' => 'event_created',
            ], admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Creates, updates, or deletes the venue location for an event based on POST data.
     *
     * @param int $eventId
     * @return void
     */
    private function saveVenueLocation(int $eventId): void
    {
        $venueName = sanitize_text_field($_POST['venue_name'] ?? '');
        $existing  = Location::venueForEvent($eventId);

        if ($venueName !== '') {
            $venueData = [
                'event_id'       => $eventId,
                'type'           => 'venue',
                'name'           => $venueName,
                'street_address' => sanitize_text_field($_POST['venue_street_address'] ?? ''),
                'city'           => sanitize_text_field($_POST['venue_city']           ?? ''),
                'state'          => sanitize_text_field($_POST['venue_state']          ?? ''),
                'zip_code'       => sanitize_text_field($_POST['venue_zip_code']       ?? ''),
            ];

            if ($existing) {
                Location::update($existing->id, $venueData);
            } else {
                Location::create($venueData);
            }
        } elseif ($existing) {
            Location::delete($existing->id);
        }
    }

    /**
     * Saves all initial lodging locations submitted with a new event form.
     *
     * Reads lodging_init_name[], lodging_init_library_id[], etc. as parallel arrays
     * (one entry per row the admin added). Skips any row with an empty name.
     *
     * @param int $eventId Newly created event ID.
     * @return void
     */
    private function saveInitialLodgingLocation(int $eventId): void
    {
        $names = $_POST['lodging_init_name'] ?? [];
        if (!is_array($names)) {
            return;
        }

        foreach ($names as $i => $rawName) {
            $name = sanitize_text_field($rawName);
            if ($name === '') {
                continue;
            }

            $isOther = !empty(($_POST['lodging_init_is_other'][$i] ?? ''));

            Location::create([
                'event_id'       => $eventId,
                'type'           => 'lodging',
                'name'           => $name,
                'street_address' => sanitize_text_field($_POST['lodging_init_street'][$i] ?? ''),
                'city'           => sanitize_text_field($_POST['lodging_init_city'][$i]   ?? ''),
                'state'          => sanitize_text_field($_POST['lodging_init_state'][$i]  ?? ''),
                'zip_code'       => sanitize_text_field($_POST['lodging_init_zip'][$i]    ?? ''),
                'is_other'       => $isOther,
                'sort_order'     => (int) ($_POST['lodging_init_sort'][$i] ?? 0),
            ]);
        }
    }

    /**
     * Sanitizes the optional event "From Email" field while preserving the
     * {{current_domain}} placeholder for runtime expansion.
     *
     * The stored value must still become a valid email address once the
     * placeholder is replaced with a real host.
     *
     * @param string $value Raw submitted value.
     * @return string
     */
    private function sanitizeFromEmailTemplate(string $value): string
    {
        $value = sanitize_text_field(wp_unslash($value));

        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/\{\{\s*current_domain\s*\}\}/i', '{{current_domain}}', $value);

        if (!is_string($normalized)) {
            return '';
        }

        $validationValue = str_ireplace('{{current_domain}}', 'example.com', $normalized);

        return is_email($validationValue) ? $normalized : '';
    }

    /**
     * Processes deleting an event (and all its invitees) via a GET request with a nonce.
     *
     * @return void
     */
    private function handleDeleteEvent(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_event_' . $id)) {
            wp_die('Security check failed.');
        }

        Event::delete($id);

        wp_redirect(add_query_arg(['page' => AdminMenu::PAGE_EVENTS, 'eim_message' => 'event_deleted'], admin_url('admin.php')));
        exit;
    }

    /**
     * Adds a lodging location (selected from the library) to an event.
     *
     * @return void
     */
    private function handleAddLodgingToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_lodging_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId   = (int) ($_POST['event_id'] ?? 0);
        $name      = sanitize_text_field($_POST['name'] ?? '');
        $libraryId = (int) ($_POST['lodging_add_library_id'] ?? 0);

        if ($name === '' || $eventId === 0) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'lodging_name_required',
            ], admin_url('admin.php')));
            exit;
        }

        if ($libraryId <= 0 || LocationLibrary::find($libraryId) === null) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'lodging_invalid_location',
            ], admin_url('admin.php')));
            exit;
        }

        $isOther = !empty($_POST['is_other']);

        Location::create([
            'event_id'       => $eventId,
            'type'           => 'lodging',
            'name'           => $name,
            'street_address' => sanitize_text_field($_POST['street_address'] ?? ''),
            'city'           => sanitize_text_field($_POST['city']           ?? ''),
            'state'          => sanitize_text_field($_POST['state']          ?? ''),
            'zip_code'       => sanitize_text_field($_POST['zip_code']       ?? ''),
            'is_other'       => $isOther,
            'sort_order'     => (int) ($_POST['sort_order'] ?? 0),
        ]);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'lodging_created',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Removes a lodging location from an event via a GET request with a nonce.
     *
     * @return void
     */
    private function handleRemoveLodgingFromEvent(): void
    {
        $id      = (int) ($_GET['id']       ?? 0);
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_lodging_' . $id)) {
            wp_die('Security check failed.');
        }

        Location::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'lodging_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the events list page: admin notices, calendar (when events exist), and the events table.
     *
     * @return void
     */
    private function renderEventsList(): void
    {
        $events       = Event::all();
        $message      = (string) ($_GET['eim_message'] ?? '');
        $error        = (string) ($_GET['eim_error'] ?? '');
        $hasLocations = LocationLibrary::count() > 0;

        $calYear  = max(1970, min(2099, (int) ($_GET['cal_year']  ?? date('Y'))));
        $calMonth = max(1,    min(12,   (int) ($_GET['cal_month'] ?? date('n'))));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Events Invite Manager</h1>
            <?php if ($hasLocations): ?>
                <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add')); ?>" class="page-title-action">Add New Event</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <?php if (!$hasLocations): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>No locations found.</strong>
                        You need at least one location in the library before creating an event.
                        <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=add')); ?>">Add a location now →</a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (empty($events) && $hasLocations): ?>
                <p>No events yet. <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add')); ?>">Create your first event.</a></p>
            <?php elseif ($hasLocations): ?>

                <?php $this->renderCalendar($calYear, $calMonth); ?>

                <table class="wp-list-table widefat fixed striped" style="margin-top:24px;">
                    <thead>
                        <tr>
                            <th style="width:12%;">Name</th>
                            <th style="width:18%;">Date / Time</th>
                            <th>Description</th>
                            <th style="width:18%;">RSVP Page</th>
                            <th style="width:12%;">Invitees</th>
                            <th style="width:14%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $total       = $event->inviteeCount();
                            $registered  = $event->registeredCount();
                            $venue       = Location::venueForEvent($event->id);
                            $editUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $event->id);
                            $deleteUrl   = wp_nonce_url(
                                admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=delete_event&id=' . $event->id),
                                'eim_delete_event_' . $event->id
                            );
                            $inviteesUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id);
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($event->name); ?></a></strong>
                                </td>
                                <td>
                                    <?php if ($event->eventDate): ?>
                                        <?= esc_html($event->formattedDate()); ?>
                                        <?php if ($event->startTime): ?>
                                            <br><span style="color:#666;font-size:12px;"><?= esc_html($event->formattedTimeRange()); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                    <?php if ($venue): ?>
                                        <br><span style="color:#444;font-size:12px;"><?= esc_html($venue->name); ?></span>
                                        <?php if ($venue->formattedAddress()): ?>
                                            <br><span style="color:#999;font-size:11px;"><?= esc_html($venue->formattedAddress()); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc_html(wp_trim_words($event->description, 12, '…')); ?></td>
                                <td>
                                    <?php if ($event->rsvpPageUrl): ?>
                                        <a href="<?= esc_url($event->rsvpPageUrl); ?>" target="_blank" rel="noopener">
                                            <?= esc_html(wp_parse_url($event->rsvpPageUrl, PHP_URL_PATH) ?: $event->rsvpPageUrl); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= esc_url($inviteesUrl); ?>">
                                        <?= esc_html($total); ?> / <?= esc_html($registered); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                                    <a href="<?= esc_url($inviteesUrl); ?>">Invitees</a> |
                                    <a href="<?= esc_url($deleteUrl); ?>"
                                       onclick="return confirm('Delete <?= esc_js($event->name); ?> and all its invitees? This cannot be undone.');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the monthly calendar view including the jump-to-event dropdown and month navigation.
     *
     * Only events that have an event_date set appear on the calendar grid. Events without
     * a date still appear in the list table below.
     *
     * @param int $year  Four-digit year to display.
     * @param int $month Month number (1–12) to display.
     * @return void
     */
    private function renderCalendar(int $year, int $month): void
    {
        $eventsByDay  = Event::byDayForMonth($year, $month);
        $allDated     = Event::allByDate();

        $firstDayTs  = mktime(0, 0, 0, $month, 1, $year);
        $daysInMonth = (int) date('t', $firstDayTs);
        $startDow    = (int) date('w', $firstDayTs);
        $monthLabel  = date_i18n('F Y', $firstDayTs);

        $todayY = (int) date('Y');
        $todayM = (int) date('n');
        $todayD = (int) date('j');

        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear  = $month === 1 ? $year - 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextYear  = $month === 12 ? $year + 1 : $year;

        $prevUrl = esc_url(admin_url("admin.php?page=" . AdminMenu::PAGE_EVENTS . "&cal_year={$prevYear}&cal_month={$prevMonth}"));
        $nextUrl = esc_url(admin_url("admin.php?page=" . AdminMenu::PAGE_EVENTS . "&cal_year={$nextYear}&cal_month={$nextMonth}"));
        $baseUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS);

        $cells = array_merge(array_fill(0, $startDow, null), range(1, $daysInMonth));
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        $weeks = array_chunk($cells, 7);
        ?>
        <style>
        .eim-cal-wrap{margin:16px 0 0;}
        .eim-cal-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
        .eim-cal-toolbar h2{margin:0;font-size:1.25em;min-width:160px;text-align:center;}
        .eim-cal-toolbar .button{text-decoration:none;}
        .eim-jump-wrap{margin-left:auto;}
        .eim-jump-wrap select{min-width:260px;}
        .eim-calendar{width:100%;border-collapse:collapse;table-layout:fixed;}
        .eim-calendar thead th{background:#2271b1;color:#fff;padding:7px 5px;text-align:center;font-weight:600;font-size:13px;}
        .eim-calendar td{border:1px solid #dcdcde;vertical-align:top;padding:5px 6px;height:78px;background:#fff;}
        .eim-cal-pad{background:#f6f7f7 !important;}
        .eim-cal-today{background:#fff8e5 !important;}
        .eim-cal-daynum{font-size:11px;color:#999;display:block;margin-bottom:3px;}
        .eim-cal-today .eim-cal-daynum{color:#2271b1;font-weight:700;font-size:12px;}
        .eim-cal-event{display:block;font-size:11px;line-height:1.4;background:#2271b1;color:#fff !important;border-radius:3px;padding:2px 6px;margin-bottom:2px;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .eim-cal-event:hover{background:#135e96;}
        .eim-cal-event-time{opacity:.8;font-size:10px;}
        </style>

        <div class="eim-cal-wrap">
            <div class="eim-cal-toolbar">
                <a href="<?= $prevUrl; ?>" class="button">&lsaquo; <?= esc_html(date_i18n('M', mktime(0, 0, 0, $prevMonth, 1, $prevYear))); ?></a>
                <h2><?= esc_html($monthLabel); ?></h2>
                <a href="<?= $nextUrl; ?>" class="button"><?= esc_html(date_i18n('M', mktime(0, 0, 0, $nextMonth, 1, $nextYear))); ?> &rsaquo;</a>

                <?php if (!empty($allDated)): ?>
                <div class="eim-jump-wrap">
                    <select onchange="if(this.value) window.location.href = this.value;" aria-label="Jump to event">
                        <option value="">— Jump to event —</option>
                        <?php foreach ($allDated as $e): ?>
                            <?php
                            $eYear   = (int) date('Y', strtotime($e->eventDate));
                            $eMonth  = (int) date('n', strtotime($e->eventDate));
                            $jumpUrl = esc_url(add_query_arg(['cal_year' => $eYear, 'cal_month' => $eMonth], $baseUrl));
                            $label   = $e->name . ' — ' . $e->formattedDate();
                            if ($e->startTime) {
                                $label .= ' ' . $e->formattedTimeRange();
                            }
                            ?>
                            <option value="<?= $jumpUrl; ?>"><?= esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <table class="eim-calendar">
                <thead>
                    <tr>
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                            <th><?= esc_html($dow); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weeks as $week): ?>
                        <tr>
                            <?php foreach ($week as $day): ?>
                                <?php if ($day === null): ?>
                                    <td class="eim-cal-pad"></td>
                                <?php else: ?>
                                    <?php
                                    $isToday   = ($year === $todayY && $month === $todayM && $day === $todayD);
                                    $dayEvents = $eventsByDay[$day] ?? [];
                                    $tdClass   = $isToday ? 'eim-cal-today' : '';
                                    ?>
                                    <td class="<?= esc_attr($tdClass); ?>">
                                        <span class="eim-cal-daynum"><?= esc_html($day); ?></span>
                                        <?php foreach ($dayEvents as $e): ?>
                                            <?php $editUrl = esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $e->id)); ?>
                                            <a href="<?= $editUrl; ?>" class="eim-cal-event" title="<?= esc_attr($e->name); ?>">
                                                <?= esc_html($e->name); ?>
                                                <?php if ($e->startTime): ?>
                                                    <span class="eim-cal-event-time"><?= esc_html($e->formattedTimeRange()); ?></span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Renders the add/edit event form.
     *
     * @param Event|null $event Existing event to edit, or null when creating a new one.
     * @return void
     */
    private function renderEventForm(?Event $event): void
    {
        $isNew = $event === null;

        // Require at least one library location before creating a new event.
        if ($isNew && LocationLibrary::count() === 0) {
            ?>
            <div class="wrap">
                <h1>Add New Event</h1>
                <div class="notice notice-warning">
                    <p>
                        <strong>No locations found.</strong>
                        You must add at least one location to the library before creating an event.
                        <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=add')); ?>">Add a location now →</a>
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error'] ?? '');
        $title   = $isNew ? 'Add New Event' : 'Edit Event: ' . esc_html($event->name);
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>" style="margin-top: 12px; display: block;">← Back to Events</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>">
                <?php wp_nonce_field('eim_save_event'); ?>
                <input type="hidden" name="eim_action" value="save_event">
                <input type="hidden" name="event_id" value="<?= esc_attr($isNew ? 0 : $event->id); ?>">

                <table class="form-table" role="presentation">
                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Details</h2></td></tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_name">Event Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $event->name); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_description">Description</label>
                        </th>
                        <td>
                            <textarea id="eim_description" name="description" class="large-text" rows="4"><?= esc_textarea($isNew ? '' : $event->description); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_event_date">Event Date</label>
                        </th>
                        <td>
                            <input type="date" id="eim_event_date" name="event_date"
                                   value="<?= esc_attr($isNew ? '' : ($event->eventDate ?? '')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Start Time</th>
                        <td style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                            <span>
                                <label for="eim_start_time" style="margin-right:6px;">Start</label>
                                <input type="time" id="eim_start_time" name="start_time"
                                       value="<?= esc_attr($isNew ? '' : ($event->startTime ?? '')); ?>">
                            </span>
                            <span>
                                <label for="eim_end_time" style="margin-right:6px;">End</label>
                                <input type="time" id="eim_end_time" name="end_time"
                                       value="<?= esc_attr($isNew ? '' : ($event->endTime ?? '')); ?>">
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_rsvp_page_url">RSVP Page</label>
                        </th>
                        <td>
                            <?php
                            $wpPages        = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish']);
                            $selectedPageId = (!$isNew && $event->rsvpPageUrl)
                                ? url_to_postid($event->rsvpPageUrl)
                                : 0;
                            ?>
                            <select id="eim_rsvp_page_url" name="rsvp_page_url">
                                <option value="">— Select a page —</option>
                                <?php foreach ($wpPages as $wpPage): ?>
                                    <option value="<?= esc_attr(get_permalink($wpPage->ID)); ?>"
                                            <?php selected($selectedPageId, $wpPage->ID); ?>>
                                        <?= esc_html($wpPage->post_title); ?>
                                        (<?= esc_html($wpPage->post_name); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Invite links will append <code>?invite_code=…&amp;event_id=<?= esc_html($isNew ? '{id}' : $event->id); ?></code> to the selected page's URL.
                            </p>
                        </td>
                    </tr>
                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Venue / Location</h2></td></tr>

                    <?php
                    $venue          = $isNew ? null : Location::venueForEvent($event->id);
                    $venueLibraryId = 0;
                    if ($venue) {
                        $libMatch = LocationLibrary::findByName($venue->name);
                        $venueLibraryId = $libMatch ? $libMatch->id : 0;
                    }
                    ?>
                    <?php $venueAddress = $venue ? $venue->formattedAddress() : ''; ?>
                    <input type="hidden" id="eim_venue_library_id"    name="venue_library_id"    value="<?= esc_attr($venueLibraryId); ?>">
                    <input type="hidden" id="eim_venue_street"        name="venue_street_address" value="<?= esc_attr($venue ? $venue->streetAddress : ''); ?>">
                    <input type="hidden" id="eim_venue_city"          name="venue_city"           value="<?= esc_attr($venue ? $venue->city : ''); ?>">
                    <input type="hidden" id="eim_venue_state"         name="venue_state"          value="<?= esc_attr($venue ? $venue->state : ''); ?>">
                    <input type="hidden" id="eim_venue_zip"           name="venue_zip_code"       value="<?= esc_attr($venue ? $venue->zipCode : ''); ?>">
                    <tr>
                        <th scope="row"><label for="eim_venue_name">Venue Name</label></th>
                        <td>
                            <input type="text" id="eim_venue_name" name="venue_name" class="regular-text"
                                   value="<?= esc_attr($venue ? $venue->name : ''); ?>"
                                   autocomplete="off">
                            <p id="eim_venue_address_display" style="margin-top:6px;color:#3c434a;<?= $venueAddress ? '' : 'display:none;'; ?>">
                                <?= esc_html($venueAddress); ?>
                            </p>
                            <p class="description" style="margin-top:4px;">
                                Start typing to search the locations library.
                                <?php if (!$isNew): ?>Clear this field and save to remove the venue.<?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Invite Email</h2></td></tr>

                    <tr>
                        <th scope="row">
                            <label for="eim_from_name">From Name</label>
                        </th>
                        <td>
                            <input type="text" id="eim_from_name" name="from_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $event->fromName); ?>">
                            <p class="description">
                                Optional display name for outgoing event emails.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_from_email">From Email</label>
                        </th>
                        <td>
                            <input type="text" id="eim_from_email" name="from_email" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $event->fromEmail); ?>">
                            <p class="description">
                                Optional. Leave blank to use the site's default WordPress sender address.
                                Supports <code>{{ current_domain }}</code>, for example
                                <code>noreply@{{current_domain}}</code>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="eim_invite_email_subject">Subject Line</label>
                        </th>
                        <td>
                            <input type="text" id="eim_invite_email_subject" name="invite_email_subject" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $event->inviteEmailSubject); ?>">
                            <p class="description">
                                Available tags: <code>{{ event_name }}</code> <code>{{ first_name }}</code> <code>{{ last_name }}</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="invite_email_template">Email Body</label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $isNew ? '' : $event->inviteEmailTemplate,
                                'invite_email_template',
                                ['textarea_name' => 'invite_email_template', 'media_buttons' => false, 'textarea_rows' => 15]
                            );
                            ?>
                            <p class="description">
                                Available tags:
                                <code>{{ event_name }}</code> <code>{{ first_name }}</code> <code>{{ last_name }}</code>
                                <code>{{ full_name }}</code> <code>{{ email }}</code>
                                <code>{{ invite_code }}</code> <code>{{ rsvp_url }}</code>
                            </p>
                        </td>
                    </tr>

                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Confirmation Code Email</h2></td></tr>

                    <tr>
                        <th scope="row">
                            <label for="eim_confirmation_email_subject">Subject Line</label>
                        </th>
                        <td>
                            <input type="text" id="eim_confirmation_email_subject" name="confirmation_email_subject" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $event->confirmationEmailSubject); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="confirmation_email_template">Email Body</label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $isNew ? '' : $event->confirmationEmailTemplate,
                                'confirmation_email_template',
                                ['textarea_name' => 'confirmation_email_template', 'media_buttons' => false, 'textarea_rows' => 10]
                            );
                            ?>
                            <p class="description">
                                Available tag: <code>{{ confirmation_code }}</code> — replaced with the six-digit code sent to the registrant.
                            </p>
                        </td>
                    </tr>
                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Lodging</h2></td></tr>

                    <tr>
                        <th scope="row">Lodging Options</th>
                        <td>
                            <label>
                                <input type="checkbox" name="lodging_enabled" value="1"
                                       <?php checked($isNew ? false : $event->lodgingEnabled); ?>>
                                Enable lodging options for this event
                            </label>
                            <p class="description">When enabled, lodging location choices can be presented to invitees.</p>
                        </td>
                    </tr>
                    <?php if ($isNew): ?>
                        <tr>
                            <th scope="row">Lodging Locations</th>
                            <td>
                                <div id="eim-lodging-init-rows">
                                    <div class="eim-lodging-init-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                                        <input type="hidden" class="eim-lodging-init-library-id" name="lodging_init_library_id[]" value="">
                                        <input type="hidden" class="eim-lodging-init-street"     name="lodging_init_street[]">
                                        <input type="hidden" class="eim-lodging-init-city"       name="lodging_init_city[]">
                                        <input type="hidden" class="eim-lodging-init-state"      name="lodging_init_state[]">
                                        <input type="hidden" class="eim-lodging-init-zip"        name="lodging_init_zip[]">
                                        <input type="hidden" class="eim-lodging-init-is-other"   name="lodging_init_is_other[]" value="">
                                        <input type="text" class="eim-lodging-init-name regular-text"
                                               name="lodging_init_name[]" placeholder="Search locations library…" autocomplete="off">
                                        <label style="white-space:nowrap;">Order:
                                            <input type="number" class="eim-lodging-init-sort" name="lodging_init_sort[]" value="0" min="0" style="width:58px;">
                                        </label>
                                        <button type="button" class="button eim-remove-lodging-row">Remove</button>
                                    </div>
                                </div>
                                <button type="button" id="eim-add-lodging-row" class="button" style="margin-bottom:8px;">+ Add Another Location</button>
                                <p class="description">Optional. Select locations from the library. More can be added after saving.</p>

                                <template id="eim-lodging-init-row-template">
                                    <div class="eim-lodging-init-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                                        <input type="hidden" class="eim-lodging-init-library-id" name="lodging_init_library_id[]" value="">
                                        <input type="hidden" class="eim-lodging-init-street"     name="lodging_init_street[]">
                                        <input type="hidden" class="eim-lodging-init-city"       name="lodging_init_city[]">
                                        <input type="hidden" class="eim-lodging-init-state"      name="lodging_init_state[]">
                                        <input type="hidden" class="eim-lodging-init-zip"        name="lodging_init_zip[]">
                                        <input type="hidden" class="eim-lodging-init-is-other"   name="lodging_init_is_other[]" value="">
                                        <input type="text" class="eim-lodging-init-name regular-text"
                                               name="lodging_init_name[]" placeholder="Search locations library…" autocomplete="off">
                                        <label style="white-space:nowrap;">Order:
                                            <input type="number" class="eim-lodging-init-sort" name="lodging_init_sort[]" value="0" min="0" style="width:58px;">
                                        </label>
                                        <button type="button" class="button eim-remove-lodging-row">Remove</button>
                                    </div>
                                </template>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th scope="row">Lodging Locations</th>
                            <td>
                                <?php $lodgingLocations = Location::forEvent($event->id); ?>
                                <?php if (!empty($lodgingLocations)): ?>
                                    <table class="wp-list-table widefat fixed striped" style="margin-bottom:12px;">
                                        <thead>
                                            <tr>
                                                <th style="width:8%;">Order</th>
                                                <th>Name / Address</th>
                                                <th style="width:12%;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lodgingLocations as $loc): ?>
                                                <?php
                                                $removeUrl = wp_nonce_url(
                                                    admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=remove_lodging_from_event&id=' . $loc->id . '&event_id=' . $event->id),
                                                    'eim_remove_lodging_' . $loc->id
                                                );
                                                ?>
                                                <tr>
                                                    <td><?= esc_html($loc->sortOrder); ?></td>
                                                    <td>
                                                        <strong><?= esc_html($loc->name); ?></strong>
                                                        <?php if ($loc->isOther): ?>
                                                            <span style="background:#f0f0f1;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">Other</span>
                                                        <?php elseif ($loc->formattedAddress()): ?>
                                                            <br><span style="color:#666;font-size:12px;"><?= esc_html($loc->formattedAddress()); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="<?= esc_url($removeUrl); ?>"
                                                           onclick="return confirm('Remove <?= esc_js($loc->name); ?> from this event?');">Remove</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p style="margin:0 0 8px;">No lodging locations added yet.</p>
                                <?php endif; ?>

                                <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>">
                                    <?php wp_nonce_field('eim_add_lodging_to_event'); ?>
                                    <input type="hidden" name="eim_action" value="add_lodging_to_event">
                                    <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">
                                    <input type="hidden" id="eim_lodging_add_library_id" name="lodging_add_library_id" value="">
                                    <input type="hidden" id="eim_lodging_add_street"     name="street_address">
                                    <input type="hidden" id="eim_lodging_add_city"       name="city">
                                    <input type="hidden" id="eim_lodging_add_state"      name="state">
                                    <input type="hidden" id="eim_lodging_add_zip"        name="zip_code">
                                    <input type="hidden" id="eim_lodging_add_is_other"   name="is_other" value="">
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                        <input type="text" id="eim_lodging_add_name" name="name" class="regular-text"
                                               placeholder="Search locations library…" autocomplete="off">
                                        <label style="white-space:nowrap;">Order:
                                            <input type="number" name="sort_order" value="0" min="0" style="width:58px;">
                                        </label>
                                        <button type="submit" class="button">Add Location</button>
                                    </div>
                                    <p class="description">Start typing to search the locations library.</p>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($isNew ? 'Create Event' : 'Update Event'); ?>
            </form>
        </div>
        <?php
    }
}
