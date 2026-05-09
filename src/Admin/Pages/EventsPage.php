<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\LocationLibrary;

/**
 * Handles event-related admin actions and rendering.
 */
final class EventsPage extends AbstractAdminPage
{
    /** @var EmailService Used when sending event invite emails from the admin. */
    private EmailService $emailService;

    /**
     * @param EmailService $emailService
     */
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

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
            'add_invitee_to_event'      => $this->handleAddInviteeToEvent(),
            'remove_invitee_from_event' => $this->handleRemoveInviteeFromEvent(),
            'send_event_invite'         => $this->handleSendEventInvite(),
            'send_all_event_invites'    => $this->handleSendAllEventInvites(),
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
            'start_datetime'              => $this->sanitizeDatetimeLocal($_POST['start_datetime'] ?? ''),
            'end_datetime'                => $this->sanitizeDatetimeLocal($_POST['end_datetime'] ?? ''),
            'timezone'                    => sanitize_text_field($_POST['timezone'] ?? ''),
            'lodging_enabled'             => !empty($_POST['lodging_enabled']) ? 1 : 0,
            'max_invitees'                => (int) ($_POST['max_invitees'] ?? 0),
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
     * Converts a datetime-local input value ("YYYY-MM-DDTHH:MM") to a MySQL
     * DATETIME string ("YYYY-MM-DD HH:MM:00"), or returns an empty string if blank.
     *
     * @param string $value Raw POST value.
     * @return string
     */
    private function sanitizeDatetimeLocal(string $value): string
    {
        $value = sanitize_text_field(wp_unslash($value));

        if ($value === '') {
            return '';
        }

        // datetime-local sends "YYYY-MM-DDTHH:MM" — replace T with a space.
        $value = str_replace('T', ' ', $value);

        // Append seconds if missing (browser may omit them).
        if (strlen($value) === 16) {
            $value .= ':00';
        }

        return strtotime($value) ? $value : '';
    }

    /**
     * Processes deleting an event and its invitation associations via a GET request with a nonce.
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
     * Adds an existing global invitee profile to an event.
     *
     * @return void
     */
    private function handleAddInviteeToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_invitee_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId   = (int) ($_POST['event_id'] ?? 0);
        $inviteeId = (int) ($_POST['invitee_id'] ?? 0);

        $event = $eventId > 0 ? Event::find($eventId) : null;

        if ($eventId <= 0 || $inviteeId <= 0 || $event === null || Invitee::find($inviteeId) === null) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invitee_required',
            ], admin_url('admin.php')) . '#eim-event-invitees');
            exit;
        }

        if ($event->maxInvitees !== null && $event->inviteeCount() >= $event->maxInvitees) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_limit_reached',
            ], admin_url('admin.php')) . '#eim-event-invitees');
            exit;
        }

        Invitee::inviteToEvent($inviteeId, $eventId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_added',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

    /**
     * Removes an invitee from an event without deleting the global invitee profile.
     *
     * @return void
     */
    private function handleRemoveInviteeFromEvent(): void
    {
        $eventId   = (int) ($_GET['event_id'] ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_invitee_' . $eventId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        Invitee::removeFromEvent($inviteeId, $eventId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_removed',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

    /**
     * Sends an event invite email to a single event invitee.
     *
     * @return void
     */
    private function handleSendEventInvite(): void
    {
        $eventId   = (int) ($_GET['event_id'] ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_send_event_invite_' . $eventId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        $event   = Event::find($eventId);
        $invitee = Invitee::findForEvent($inviteeId, $eventId);

        if ($event && $invitee) {
            $sent = $this->emailService->sendInvite($event, $invitee);
            if ($sent) {
                Invitee::markInviteSentForEvent($inviteeId, $eventId);
            }
            $message = $sent ? 'invite_sent' : 'invite_failed';
        } else {
            $message = 'not_found';
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => $message,
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

    /**
     * Sends event invite emails to all event invitees who have not received one.
     *
     * @return void
     */
    private function handleSendAllEventInvites(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_send_all_event_invites_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event     = Event::find($eventId);
        $sentCount = 0;

        if ($event) {
            foreach (Invitee::forEvent($eventId) as $invitee) {
                if ($invitee->inviteSentAt !== null) {
                    continue;
                }

                if ($this->emailService->sendInvite($event, $invitee)) {
                    Invitee::markInviteSentForEvent($invitee->id, $eventId);
                    $sentCount++;
                }
            }
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'invites_sent',
            'count'       => $sentCount,
        ], admin_url('admin.php')) . '#eim-event-invitees');
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
                            $inviteesUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $event->id . '#eim-event-invitees');
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($event->name); ?></a></strong>
                                </td>
                                <td>
                                    <?php if ($event->startDatetime): ?>
                                        <?= esc_html($event->formattedDateTimeRange()); ?>
                                        <?php if ($event->timezone): ?>
                                            <br><span style="color:#999;font-size:11px;"><?= esc_html($event->timezone); ?></span>
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
                                        <?= esc_html($total); ?><?= $event->maxInvitees !== null ? ' / ' . esc_html($event->maxInvitees) : ''; ?> invited, <?= esc_html($registered); ?> registered
                                    </a>
                                </td>
                                <td>
                                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                                    <a href="<?= esc_url($inviteesUrl); ?>">Invitees</a> |
                                    <a href="<?= esc_url($deleteUrl); ?>"
                                       onclick="return confirm('Delete <?= esc_js($event->name); ?> and its event invitations? Invitee profiles will not be deleted.');">Delete</a>
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
                            $eYear   = (int) date('Y', strtotime($e->startDatetime));
                            $eMonth  = (int) date('n', strtotime($e->startDatetime));
                            $jumpUrl = esc_url(add_query_arg(['cal_year' => $eYear, 'cal_month' => $eMonth], $baseUrl));
                            $label   = $e->name . ' — ' . $e->formattedDateTimeRange();
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
                                                <?php if ($e->startDatetime): ?>
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
                            <label for="eim_start_datetime">Event Start</label>
                        </th>
                        <td>
                            <?php
                            $startVal = '';
                            if (!$isNew && $event->startDatetime) {
                                $startVal = substr(str_replace(' ', 'T', $event->startDatetime), 0, 16);
                            }
                            ?>
                            <input type="datetime-local" id="eim_start_datetime" name="start_datetime"
                                   value="<?= esc_attr($startVal); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_end_datetime">Event End</label>
                        </th>
                        <td>
                            <?php
                            $endVal = '';
                            if (!$isNew && $event->endDatetime) {
                                $endVal = substr(str_replace(' ', 'T', $event->endDatetime), 0, 16);
                            }
                            ?>
                            <input type="datetime-local" id="eim_end_datetime" name="end_datetime"
                                   value="<?= esc_attr($endVal); ?>">
                            <p class="description">Leave blank if the event has no fixed end time.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_timezone">Timezone</label>
                        </th>
                        <td>
                            <?php
                            $usTimezones = [
                                'America/New_York'    => 'Eastern (ET) — New York, Miami, Atlanta',
                                'America/Chicago'     => 'Central (CT) — Chicago, Dallas, Houston',
                                'America/Denver'      => 'Mountain (MT) — Denver, Salt Lake City',
                                'America/Phoenix'     => 'Mountain no DST (MT) — Phoenix, Tucson',
                                'America/Los_Angeles' => 'Pacific (PT) — Los Angeles, Seattle, Las Vegas',
                                'America/Anchorage'   => 'Alaska (AKT) — Anchorage',
                                'Pacific/Honolulu'    => 'Hawaii (HT) — Honolulu',
                            ];
                            $selectedTz = $isNew ? '' : $event->timezone;
                            ?>
                            <select id="eim_timezone" name="timezone">
                                <option value="">— Select a timezone —</option>
                                <?php foreach ($usTimezones as $tzId => $tzLabel): ?>
                                    <option value="<?= esc_attr($tzId); ?>" <?php selected($selectedTz, $tzId); ?>>
                                        <?= esc_html($tzLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                    <tr>
                        <th scope="row">
                            <label for="eim_max_invitees">Max Invitees</label>
                        </th>
                        <td>
                            <input type="number" id="eim_max_invitees" name="max_invitees" min="1" step="1"
                                   class="small-text"
                                   value="<?= esc_attr($isNew ? '' : ($event->maxInvitees ?? '')); ?>">
                            <p class="description">
                                Leave blank for no limit. When set, new invitees cannot be added once this number is reached.
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
                                    <div class="eim-lodging-init-row" style="margin-bottom:8px;">
                                        <input type="hidden" class="eim-lodging-init-library-id" name="lodging_init_library_id[]" value="">
                                        <input type="hidden" class="eim-lodging-init-street"     name="lodging_init_street[]">
                                        <input type="hidden" class="eim-lodging-init-city"       name="lodging_init_city[]">
                                        <input type="hidden" class="eim-lodging-init-state"      name="lodging_init_state[]">
                                        <input type="hidden" class="eim-lodging-init-zip"        name="lodging_init_zip[]">
                                        <input type="hidden" class="eim-lodging-init-is-other"   name="lodging_init_is_other[]" value="">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                            <input type="text" class="eim-lodging-init-name regular-text"
                                                   name="lodging_init_name[]" placeholder="Search locations library…" autocomplete="off">
                                            <label style="white-space:nowrap;">Order:
                                                <input type="number" class="eim-lodging-init-sort" name="lodging_init_sort[]" value="0" min="0" style="width:58px;">
                                            </label>
                                            <button type="button" class="button eim-remove-lodging-row">Remove</button>
                                        </div>
                                        <p class="eim-lodging-init-display" style="margin:0;color:#3c434a;font-size:13px;display:none;"></p>
                                    </div>
                                </div>
                                <button type="button" id="eim-add-lodging-row" class="button" style="margin-bottom:8px;">+ Add Another Location</button>
                                <p class="description">Optional. Select locations from the library. More can be added after saving.</p>

                                <template id="eim-lodging-init-row-template">
                                    <div class="eim-lodging-init-row" style="margin-bottom:8px;">
                                        <input type="hidden" class="eim-lodging-init-library-id" name="lodging_init_library_id[]" value="">
                                        <input type="hidden" class="eim-lodging-init-street"     name="lodging_init_street[]">
                                        <input type="hidden" class="eim-lodging-init-city"       name="lodging_init_city[]">
                                        <input type="hidden" class="eim-lodging-init-state"      name="lodging_init_state[]">
                                        <input type="hidden" class="eim-lodging-init-zip"        name="lodging_init_zip[]">
                                        <input type="hidden" class="eim-lodging-init-is-other"   name="lodging_init_is_other[]" value="">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                            <input type="text" class="eim-lodging-init-name regular-text"
                                                   name="lodging_init_name[]" placeholder="Search locations library…" autocomplete="off">
                                            <label style="white-space:nowrap;">Order:
                                                <input type="number" class="eim-lodging-init-sort" name="lodging_init_sort[]" value="0" min="0" style="width:58px;">
                                            </label>
                                            <button type="button" class="button eim-remove-lodging-row">Remove</button>
                                        </div>
                                        <p class="eim-lodging-init-display" style="margin:0;color:#3c434a;font-size:13px;display:none;"></p>
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
                                    <p id="eim_lodging_add_display" style="margin:4px 0 0;color:#3c434a;font-size:13px;display:none;"></p>
                                    <p class="description">Start typing to search the locations library.</p>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($isNew ? 'Create Event' : 'Update Event'); ?>
            </form>

            <?php if (!$isNew): ?>
                <?php $this->renderEventInviteesSection($event); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the event-specific invitee assignment and invite status section.
     *
     * Invitee profiles are not edited here; this section only associates existing
     * global invitees with the current event and handles event-specific email sends.
     *
     * @param Event $event
     * @return void
     */
    private function renderEventInviteesSection(Event $event): void
    {
        $invitees   = Invitee::forEvent($event->id);
        $dateFormat = get_option('date_format');
        $sendAllUrl = wp_nonce_url(
            admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=send_all_event_invites&event_id=' . $event->id),
            'eim_send_all_event_invites_' . $event->id
        );
        $addInviteeUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=add');
        ?>
        <?php
        $inviteeCount = count($invitees);
        $maxInvitees  = $event->maxInvitees;
        $atLimit      = $maxInvitees !== null && $inviteeCount >= $maxInvitees;
        ?>
        <hr id="eim-event-invitees" style="margin:32px 0 20px;">
        <h2>
            Invited Invitees
            <?php if ($maxInvitees !== null): ?>
                <span style="font-size:14px;font-weight:normal;color:<?= $atLimit ? '#d63638' : '#3c434a'; ?>;">
                    (<?= esc_html($inviteeCount); ?> / <?= esc_html($maxInvitees); ?>)
                </span>
            <?php else: ?>
                <span style="font-size:14px;font-weight:normal;color:#3c434a;">(<?= esc_html($inviteeCount); ?>)</span>
            <?php endif; ?>
        </h2>
        <p class="description">
            Add existing invitees to this event here. Create or edit invitee profiles from the Invitees page.
            <?php if ($maxInvitees !== null): ?>
                Maximum of <?= esc_html($maxInvitees); ?> invitees for this event.
            <?php endif; ?>
        </p>
        <?php if ($atLimit): ?>
            <div class="notice notice-warning inline" style="margin:8px 0;"><p>This event has reached its maximum of <?= esc_html($maxInvitees); ?> invitees. No more can be added.</p></div>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>" class="eim-event-invitee-add-form">
            <?php wp_nonce_field('eim_add_invitee_to_event'); ?>
            <input type="hidden" name="eim_action" value="add_invitee_to_event">
            <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">
            <input type="hidden" id="eim_event_invitee_id" name="invitee_id" value="">

            <div class="eim-invitee-picker-wrap">
                <label class="screen-reader-text" for="eim_event_invitee_search">Search invitees to add</label>
                <input type="text"
                       id="eim_event_invitee_search"
                       class="regular-text"
                       placeholder="Search existing invitees..."
                       autocomplete="off"
                       data-event-id="<?= esc_attr($event->id); ?>">
                <button type="submit" class="button">Add to Event</button>
                <a href="<?= esc_url($addInviteeUrl); ?>" class="button">Create Invitee</a>
            </div>
            <p id="eim_event_invitee_selected" class="description" style="margin-top:6px;"></p>
        </form>

        <?php if (!empty($invitees)): ?>
            <p style="margin-top:14px;">
                <a href="<?= esc_url($sendAllUrl); ?>" class="button"
                   onclick="return confirm('Send invite emails to all event invitees who have not yet received one?');">
                    Send All Unsent Invites
                </a>
            </p>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:14%;">First Name</th>
                    <th style="width:14%;">Last Name</th>
                    <th style="width:22%;">Email</th>
                    <th style="width:14%;">Phone</th>
                    <th style="width:11%;">Invite Sent</th>
                    <th style="width:11%;">Registered</th>
                    <th style="width:14%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invitees)): ?>
                    <tr>
                        <td colspan="7">No invitees have been added to this event yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invitees as $invitee): ?>
                        <?php
                        $editUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=edit&id=' . $invitee->id);
                        $sendUrl = wp_nonce_url(
                            admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=send_event_invite&event_id=' . $event->id . '&invitee_id=' . $invitee->id),
                            'eim_send_event_invite_' . $event->id . '_' . $invitee->id
                        );
                        $removeUrl = wp_nonce_url(
                            admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=remove_invitee_from_event&event_id=' . $event->id . '&invitee_id=' . $invitee->id),
                            'eim_remove_invitee_' . $event->id . '_' . $invitee->id
                        );
                        ?>
                        <tr>
                            <td><strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($invitee->firstName); ?></a></strong></td>
                            <td><?= esc_html($invitee->lastName); ?></td>
                            <td><a href="mailto:<?= esc_attr($invitee->email); ?>"><?= esc_html($invitee->email); ?></a></td>
                            <td><?= esc_html($invitee->phone ?: '-'); ?></td>
                            <td>
                                <?php if ($invitee->inviteSentAt): ?>
                                    <?= esc_html(date_i18n($dateFormat, strtotime($invitee->inviteSentAt))); ?>
                                <?php else: ?>
                                    <span style="color:#999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($invitee->isRegistered): ?>
                                    <span style="color:#00a32a;font-weight:600;">
                                        Yes<?= $invitee->registeredAt ? ' - ' . esc_html(date_i18n($dateFormat, strtotime($invitee->registeredAt))) : ''; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#999;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= esc_url($editUrl); ?>">Edit Profile</a> |
                                <a href="<?= esc_url($sendUrl); ?>">Send Invite</a> |
                                <a href="<?= esc_url($removeUrl); ?>"
                                   onclick="return confirm('Remove <?= esc_js($invitee->fullName()); ?> from this event? Their invitee profile will remain.');">Remove</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
