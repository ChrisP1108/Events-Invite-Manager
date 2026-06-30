<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages\EventsManager\SubPages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Models\Category;
use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\EventMessage;
use EventsInviteManager\Models\Gift;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Models\RequestedInviteeAddOn;
use EventsInviteManager\Services\QrCodeService;

/**
 * Handles event-related admin actions and rendering.
 */
final class EventsPage extends AbstractAdminPage
{
    /** @var EmailService Sends invite emails. */
    private EmailService  $emailService;

    /** @var QrCodeService Generates and retrieves per-group QR codes. */
    private QrCodeService $qrCodeService;

    /**
     * @param EmailService  $emailService  Email sending service.
     * @param QrCodeService $qrCodeService QR code generation service.
     */
    public function __construct(EmailService $emailService, QrCodeService $qrCodeService)
    {
        $this->emailService  = $emailService;
        $this->qrCodeService = $qrCodeService;
    }

    /**
     * Dispatches event-related form submissions and GET actions.
     *
     * @param string $action The action slug.
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_event'                => $this->handleSaveEvent(),
            'delete_event'              => $this->handleDeleteEvent(),
            'bulk_delete_events'        => $this->handleBulkDeleteEvents(),
            'add_lodging_to_event'      => $this->handleAddLodgingToEvent(),
            'remove_lodging_from_event' => $this->handleRemoveLodgingFromEvent(),
            'bulk_remove_lodging_from_event' => $this->handleBulkRemoveLodgingFromEvent(),
            'add_invitee_to_event'      => $this->handleAddInviteeToEvent(),
            'remove_invitee_from_event' => $this->handleRemoveInviteeFromEvent(),
            'set_group_primary'         => $this->handleSetGroupPrimary(),
            'add_member_to_group'       => $this->handleAddMemberToGroup(),
            'remove_group_from_event'   => $this->handleRemoveGroupFromEvent(),
            'bulk_remove_groups_from_event' => $this->handleBulkRemoveGroupsFromEvent(),
            'send_event_invite'         => $this->handleSendEventInvite(),
            'send_all_event_invites'    => $this->handleSendAllEventInvites(),
            'generate_all_qr_codes'     => $this->handleGenerateAllQrCodes(),
            'delete_all_qr_codes'       => $this->handleDeleteAllQrCodes(),
            'export_event_csv'          => $this->handleExportEventCsv(),
            'export_event_json'         => $this->handleExportEventJson(),
            'add_gift_to_event'         => $this->handleAddGiftToEvent(),
            'remove_gift_from_event'    => $this->handleRemoveGiftFromEvent(),
            'bulk_remove_gifts_from_event' => $this->handleBulkRemoveGiftsFromEvent(),
            'add_menu_item_to_event'     => $this->handleAddMenuItemToEvent(),
            'remove_menu_item_from_event' => $this->handleRemoveMenuItemFromEvent(),
            'bulk_remove_menu_items_from_event' => $this->handleBulkRemoveMenuItemsFromEvent(),
            default                     => null,
        };
    }

    /**
     * Renders the Events admin page, routing to the list, add, or edit view.
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

    /** Handles creating or updating an event from the admin form. */
    private function handleSaveEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_event')) {
            wp_die('Security check failed.');
        }

        $id       = (int) ($_POST['event_id'] ?? 0);
        $timezone = sanitize_text_field(wp_unslash($_POST['timezone'] ?? ''));
        $data     = [
            'name'                  => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description'           => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'from_name'             => sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')),
            'from_email'            => $this->sanitizeFromEmailTemplate((string) ($_POST['from_email'] ?? '')),
            'invite_email_subject'  => sanitize_text_field(wp_unslash($_POST['invite_email_subject'] ?? '')),
            'invite_email_template' => wp_unslash($_POST['invite_email_template'] ?? ''),
            'rsvp_page_id'          => (int) ($_POST['rsvp_page_id'] ?? 0),
            'rsvp_before_start_page_id'  => (int) ($_POST['rsvp_before_start_page_id']  ?? 0),
            'rsvp_after_deadline_page_id' => (int) ($_POST['rsvp_after_deadline_page_id'] ?? 0),
            'venue_id'              => (int) ($_POST['venue_library_id'] ?? 0),
            'start_datetime'        => $this->sanitizeDatetimeLocal($_POST['start_datetime'] ?? '', $timezone),
            'end_datetime'          => $this->sanitizeDatetimeLocal($_POST['end_datetime']   ?? '', $timezone),
            'timezone'              => $timezone,
            'calendar_span_start_date'  => $this->sanitizeDateInput($_POST['calendar_span_start_date'] ?? ''),
            'calendar_span_end_date'    => $this->sanitizeDateInput($_POST['calendar_span_end_date'] ?? ''),
            'calendar_span_title'       => sanitize_text_field(wp_unslash($_POST['calendar_span_title'] ?? '')),
            'calendar_span_description' => sanitize_textarea_field(wp_unslash($_POST['calendar_span_description'] ?? '')),
            'lodging_enabled'          => !empty($_POST['lodging_enabled']) ? 1 : 0,
            'food_options_enabled'     => !empty($_POST['food_options_enabled']) ? 1 : 0,
            'beverage_options_enabled' => !empty($_POST['beverage_options_enabled']) ? 1 : 0,
            'newsletter_page_id'       => (int) ($_POST['newsletter_page_id'] ?? 0),
            'dashboard_page_id'        => (int) ($_POST['dashboard_page_id'] ?? 0),
            'max_invitees'             => (int) ($_POST['max_invitees'] ?? 0),
            'rsvp_start_datetime'      => $this->sanitizeDatetimeLocal($_POST['rsvp_start_datetime'] ?? '', $timezone),
            'rsvp_deadline'            => $this->sanitizeDatetimeLocal($_POST['rsvp_deadline'] ?? '', $timezone),
        ];

        if (empty($data['name'])) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'name_required',
            ]));
            exit;
        }

        if ($data['calendar_span_start_date'] === '' && $data['calendar_span_end_date'] !== '') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'calendar_span_start_required',
            ]));
            exit;
        }

        if (
            $data['calendar_span_start_date'] !== ''
            && $data['calendar_span_end_date'] !== ''
            && $data['calendar_span_end_date'] < $data['calendar_span_start_date']
        ) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'calendar_span_invalid_range',
            ]));
            exit;
        }

        $venueLocationId = (int) ($_POST['venue_library_id'] ?? 0);
        if ($venueLocationId > 0 && Location::find($venueLocationId) === null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'venue_invalid_location',
            ]));
            exit;
        }

        if ($id === 0 && !empty($data['lodging_enabled'])) {
            $lodgingLibraryIds = wp_unslash($_POST['lodging_init_library_id'] ?? []);
            $seenLodgingIds    = [];
            if (is_array($lodgingLibraryIds)) {
                foreach ($lodgingLibraryIds as $rawId) {
                    $locId = (int) $rawId;
                    if ($locId <= 0) continue;
                    if (isset($seenLodgingIds[$locId])) {
                        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                            'action'    => 'add',
                            'eim_error' => 'lodging_duplicate_location',
                        ]));
                        exit;
                    }
                    $seenLodgingIds[$locId] = true;
                    $loc = Location::find($locId);
                    if ($loc === null || !$loc->hasLodging) {
                        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                            'action'    => 'add',
                            'eim_error' => 'lodging_invalid_location',
                        ]));
                        exit;
                    }
                }
            }
        }

        $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));

        if ($id > 0) {
            Event::update($id, $data);
            Category::syncToEntity('event', $id, $categoryIds);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'eim_message' => 'event_updated',
            ]));
        } else {
            $newId = Event::create($data);
            if (is_int($newId) && $newId > 0) {
                Category::syncToEntity('event', $newId, $categoryIds);
                if (!empty($data['lodging_enabled'])) {
                    $this->saveInitialLodgingLocation($newId);
                }
            }
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'      => 'edit',
                'id'          => $newId ?: 0,
                'eim_message' => 'event_created',
            ]));
        }
        exit;
    }

    /**
     * Persists the lodging location(s) submitted with a new event creation form.
     *
     * @param int $eventId The ID of the newly created event.
     */
    private function saveInitialLodgingLocation(int $eventId): void
    {
        $locationIds = wp_unslash($_POST['lodging_init_library_id'] ?? []);
        if (!is_array($locationIds)) {
            return;
        }

        $seenLocationIds = [];
        $sortOrder = 1;
        foreach ($locationIds as $rawId) {
            $locationId = (int) $rawId;
            if ($locationId <= 0 || isset($seenLocationIds[$locationId])) {
                continue;
            }
            $seenLocationIds[$locationId] = true;

            EventLodging::create(
                $eventId,
                $locationId,
                $sortOrder
            );
            $sortOrder++;
        }
    }

    /**
     * Sanitizes a From Email field value that may contain the {{current_domain}} template tag.
     *
     * @param string $value Raw POST value.
     * @return string Sanitized value, or '' if the resulting address is not a valid email.
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
     * Sanitizes and converts a datetime-local input value to a UTC datetime string.
     *
     * @param string $value    Raw POST value in 'Y-m-d\TH:i' or 'Y-m-d\TH:i:s' format.
     * @param string $timezone IANA timezone identifier for the input (e.g. 'America/New_York').
     * @return string UTC datetime in 'Y-m-d H:i:s' format, or '' if invalid.
     */
    private function sanitizeDatetimeLocal(string $value, string $timezone = ''): string
    {
        $value = sanitize_text_field(wp_unslash($value));

        if ($value === '') {
            return '';
        }

        $local = str_replace('T', ' ', $value);
        if (strlen($local) === 16) {
            $local .= ':00';
        }

        if (!strtotime($local)) {
            return '';
        }

        if ($timezone === '') {
            return $local;
        }

        try {
            $dt = new \DateTime($local, new \DateTimeZone($timezone));
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $local;
        }
    }

    /**
     * Sanitizes a date input value.
     *
     * @param string $value Raw POST value in 'Y-m-d' format.
     * @return string Date in 'Y-m-d' format, or '' if empty/invalid.
     */
    private function sanitizeDateInput(string $value): string
    {
        $value = sanitize_text_field(wp_unslash($value));

        if ($value === '') {
            return '';
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $dt !== false && $dt->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * Converts a stored UTC datetime string to a datetime-local input value in the event's timezone.
     *
     * @param string $utcDatetime UTC datetime from the database.
     * @param string $timezone    IANA timezone identifier for the event.
     * @return string Datetime in 'Y-m-d\TH:i' format for the datetime-local input, or '' if empty.
     */
    private function utcToDatetimeLocal(string $utcDatetime, string $timezone): string
    {
        if ($utcDatetime === '') {
            return '';
        }

        try {
            $dt = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
            if ($timezone !== '') {
                $dt->setTimezone(new \DateTimeZone($timezone));
            }
            return $dt->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return substr(str_replace(' ', 'T', $utcDatetime), 0, 16);
        }
    }

    /** Handles deleting an event via a GET nonce link. */
    private function handleDeleteEvent(): void
    {
        $id    = (int) ($_GET['id'] ?? 0);
        $nonce = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_event_' . $id)) {
            wp_die('Security check failed.');
        }

        Category::syncToEntity('event', $id, []);
        Event::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['eim_message' => 'event_deleted']));
        exit;
    }

    private function handleBulkDeleteEvents(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_delete_events')) {
            wp_die('Security check failed.');
        }

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['eim_error' => 'bulk_invalid_action']));
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['eim_error' => 'bulk_no_selection']));
            exit;
        }

        foreach ($ids as $id) {
            Category::syncToEntity('event', $id, []);
            Event::delete($id);
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['eim_message' => 'bulk_deleted']));
        exit;
    }

    /** Handles adding a lodging location to an existing event. */
    private function handleAddLodgingToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_lodging_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId    = (int) ($_POST['event_id'] ?? 0);
        $locationId = (int) ($_POST['lodging_add_library_id'] ?? 0);
        $loc        = $locationId > 0 ? Location::find($locationId) : null;

        if ($eventId === 0 || $loc === null || !$loc->hasLodging) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'lodging_invalid_location',
            ]));
            exit;
        }

        $created = EventLodging::create($eventId, $locationId);
        $args    = ['action' => 'edit', 'id' => $eventId];

        if ($created) {
            $args['eim_message'] = 'lodging_created';
        } else {
            $args['eim_error'] = 'lodging_create_failed';
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, $args) . '#eim-etab-lodging');
        exit;
    }

    /** Handles removing a lodging entry from an event via a GET nonce link. */
    private function handleRemoveLodgingFromEvent(): void
    {
        $id      = (int) ($_GET['id']       ?? 0);
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_lodging_' . $id)) {
            wp_die('Security check failed.');
        }

        EventLodging::delete($id);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'lodging_deleted',
        ]) . '#eim-etab-lodging');
        exit;
    }

    private function handleBulkRemoveLodgingFromEvent(): void
    {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_remove_lodging_from_event_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $redirectUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $eventId]);

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect($redirectUrl . '&eim_error=bulk_invalid_action#eim-etab-lodging');
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect($redirectUrl . '&eim_error=bulk_no_selection#eim-etab-lodging');
            exit;
        }

        $validIds = array_flip(array_map(static fn(EventLodging $loc): int => $loc->id, EventLodging::forEvent($eventId)));
        foreach ($ids as $id) {
            if (isset($validIds[$id])) {
                EventLodging::delete($id);
            }
        }

        wp_redirect($redirectUrl . '&eim_message=bulk_deleted#eim-etab-lodging');
        exit;
    }

    /**
     * Adds an invitee (and optionally connected invitees) to an event as a group.
     *
     * POST params:
     *   - event_id
     *   - invitee_id            (primary)
     *   - connected_invitee_ids[] (optional, checked connections to include in group)
     */
    private function handleAddInviteeToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_invitee_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId   = (int) ($_POST['event_id']   ?? 0);
        $inviteeId = (int) ($_POST['invitee_id'] ?? 0);

        $event   = $eventId > 0 ? Event::find($eventId) : null;
        $invitee = $inviteeId > 0 ? Invitee::find($inviteeId) : null;

        if ($event === null || $invitee === null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invitee_required',
            ]) . '#eim-etab-invitees');
            exit;
        }

        if (Invitee::findForEvent($inviteeId, $eventId) !== null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_already_invited',
            ]) . '#eim-etab-invitees');
            exit;
        }

        // Collect connected invitees that were checked and are not yet invited.
        $rawConnected      = wp_unslash($_POST['connected_invitee_ids'] ?? []);
        $connectedIds      = is_array($rawConnected)
            ? array_values(array_unique(array_filter(array_map('intval', $rawConnected))))
            : [];

        // Filter out any connected invitees already in the event.
        $filteredConnectedIds = [];
        foreach ($connectedIds as $cid) {
            if ($cid > 0 && Invitee::findForEvent($cid, $eventId) === null && Invitee::find($cid) !== null) {
                $filteredConnectedIds[] = $cid;
            }
        }

        $totalToAdd = 1 + count($filteredConnectedIds);

        if ($event->maxInvitees !== null && ($event->inviteeCount() + $totalToAdd) > $event->maxInvitees) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_limit_reached',
            ]) . '#eim-etab-invitees');
            exit;
        }

        // Add primary to event_invitees.
        Invitee::addToEvent($inviteeId, $eventId);

        // Add connected invitees to event_invitees.
        foreach ($filteredConnectedIds as $cid) {
            Invitee::addToEvent($cid, $eventId);
        }

        // Create one invitation group for all of them.
        InvitationGroup::create($eventId, $inviteeId, $filteredConnectedIds);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_added',
        ]) . '#eim-etab-invitees');
        exit;
    }

    /** Handles removing an invitee from an event (and their group) via a GET nonce link. */
    private function handleRemoveInviteeFromEvent(): void
    {
        $eventId   = (int) ($_GET['event_id']   ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_invitee_' . $eventId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        InvitationGroup::removeMemberFromEvent($inviteeId, $eventId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_removed',
        ]) . '#eim-etab-invitees');
        exit;
    }

    /** Handles setting a different group member as the primary recipient for an invitation group. */
    private function handleSetGroupPrimary(): void
    {
        $eventId   = (int) ($_GET['event_id']   ?? 0);
        $groupId   = (int) ($_GET['group_id']   ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_set_primary_' . $eventId . '_' . $groupId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        InvitationGroup::setPrimaryMember($groupId, $inviteeId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'primary_updated',
        ]) . '#eim-etab-invitees');
        exit;
    }

    /** Handles adding an additional invitee to an existing invitation group. */
    private function handleAddMemberToGroup(): void
    {
        $groupId = (int) ($_POST['group_id'] ?? 0);

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_member_to_group_' . $groupId)) {
            wp_die('Security check failed.');
        }

        $eventId   = (int) ($_POST['event_id']   ?? 0);
        $inviteeId = (int) ($_POST['invitee_id'] ?? 0);

        $event   = $eventId > 0 ? Event::find($eventId) : null;
        $invitee = $inviteeId > 0 ? Invitee::find($inviteeId) : null;
        $group   = $groupId > 0 ? InvitationGroup::find($groupId) : null;

        if (!$event || !$invitee || !$group || $group->eventId !== $eventId) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invalid_request',
            ]) . '#eim-etab-invitees');
            exit;
        }

        if (Invitee::findForEvent($inviteeId, $eventId) !== null) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_already_invited',
            ]) . '#eim-etab-invitees');
            exit;
        }

        if ($event->maxInvitees !== null && ($event->inviteeCount() + 1) > $event->maxInvitees) {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_limit_reached',
            ]) . '#eim-etab-invitees');
            exit;
        }

        InvitationGroup::addMemberToGroup($groupId, $inviteeId, $eventId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_added',
        ]) . '#eim-etab-invitees');
        exit;
    }

    /** Handles removing an entire invitation group (and all its members) from an event. */
    private function handleRemoveGroupFromEvent(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $groupId = (int) ($_GET['group_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_group_' . $eventId . '_' . $groupId)) {
            wp_die('Security check failed.');
        }

        InvitationGroup::deleteGroup($groupId, $eventId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_removed',
        ]) . '#eim-etab-invitees');
        exit;
    }

    private function handleBulkRemoveGroupsFromEvent(): void
    {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_remove_groups_from_event_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $redirectUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $eventId]);

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect($redirectUrl . '&eim_error=bulk_invalid_action#eim-etab-invitees');
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect($redirectUrl . '&eim_error=bulk_no_selection#eim-etab-invitees');
            exit;
        }

        foreach ($ids as $groupId) {
            $group = InvitationGroup::find($groupId);
            if ($group !== null && $group->eventId === $eventId) {
                InvitationGroup::deleteGroup($groupId, $eventId);
            }
        }

        wp_redirect($redirectUrl . '&eim_message=bulk_deleted#eim-etab-invitees');
        exit;
    }

    /** Handles assigning a global registry gift to an event. */
    private function handleAddGiftToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_gift_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $giftId  = (int) ($_POST['gift_id']  ?? 0);

        $event = $eventId > 0 ? Event::find($eventId) : null;
        $gift  = $giftId > 0 ? Gift::find($giftId) : null;

        if ($event && $gift) {
            Gift::addToEvent($giftId, $eventId);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'      => 'edit',
                'id'          => $eventId,
                'eim_message' => 'gift_added_to_event',
            ]) . '#eim-etab-gifts');
        } else {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invalid_request',
            ]) . '#eim-etab-gifts');
        }
        exit;
    }

    /** Handles removing a registry gift assignment from an event via a GET nonce link. */
    private function handleRemoveGiftFromEvent(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $giftId  = (int) ($_GET['gift_id']  ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_gift_' . $eventId . '_' . $giftId)) {
            wp_die('Security check failed.');
        }

        Gift::removeFromEvent($giftId, $eventId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'gift_removed_from_event',
        ]) . '#eim-etab-gifts');
        exit;
    }

    private function handleBulkRemoveGiftsFromEvent(): void
    {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_remove_gifts_from_event_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $redirectUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $eventId]);

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect($redirectUrl . '&eim_error=bulk_invalid_action#eim-etab-gifts');
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect($redirectUrl . '&eim_error=bulk_no_selection#eim-etab-gifts');
            exit;
        }

        foreach ($ids as $giftId) {
            Gift::removeFromEvent($giftId, $eventId);
        }

        wp_redirect($redirectUrl . '&eim_message=bulk_deleted#eim-etab-gifts');
        exit;
    }

    /** Handles assigning a global menu item to an event. */
    private function handleAddMenuItemToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_menu_item_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId    = (int) ($_POST['event_id']    ?? 0);
        $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);

        $event    = $eventId > 0    ? Event::find($eventId)       : null;
        $menuItem = $menuItemId > 0 ? MenuItem::find($menuItemId) : null;

        if ($event && $menuItem) {
            MenuItem::addToEvent($eventId, $menuItemId);
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'      => 'edit',
                'id'          => $eventId,
                'eim_message' => 'menu_item_added_to_event',
            ]) . '#eim-etab-food');
        } else {
            wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invalid_request',
            ]) . '#eim-etab-food');
        }
        exit;
    }

    /** Handles removing a menu item assignment from an event via a GET nonce link. */
    private function handleRemoveMenuItemFromEvent(): void
    {
        $eventId    = (int) ($_GET['event_id']    ?? 0);
        $menuItemId = (int) ($_GET['menu_item_id'] ?? 0);
        $nonce      = (string) ($_GET['_wpnonce']  ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_menu_item_' . $eventId . '_' . $menuItemId)) {
            wp_die('Security check failed.');
        }

        MenuItem::removeFromEvent($eventId, $menuItemId);

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'menu_item_removed_from_event',
        ]) . '#eim-etab-food');
        exit;
    }

    private function handleBulkRemoveMenuItemsFromEvent(): void
    {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $type    = sanitize_key($_POST['type'] ?? MenuItem::TYPE_FOOD);
        $type    = $type === MenuItem::TYPE_BEVERAGE ? MenuItem::TYPE_BEVERAGE : MenuItem::TYPE_FOOD;

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_bulk_remove_menu_items_from_event_' . $eventId . '_' . $type)) {
            wp_die('Security check failed.');
        }

        $redirectUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $eventId]);

        if ($this->requestedBulkAction() !== 'delete') {
            wp_redirect($redirectUrl . '&eim_error=bulk_invalid_action#eim-etab-food');
            exit;
        }

        $ids = $this->bulkActionIds();
        if (empty($ids)) {
            wp_redirect($redirectUrl . '&eim_error=bulk_no_selection#eim-etab-food');
            exit;
        }

        foreach ($ids as $menuItemId) {
            $item = MenuItem::find($menuItemId);
            if ($item !== null && $item->type === $type) {
                MenuItem::removeFromEvent($eventId, $menuItemId);
            }
        }

        wp_redirect($redirectUrl . '&eim_message=bulk_deleted#eim-etab-food');
        exit;
    }

    /**
     * AJAX: suggests events matching a search query, excluding already-selected IDs.
     *
     * Expected GET params: nonce, query, exclude_ids (comma-separated IDs).
     * Returns JSON: { success: true, data: [ { id, name, start_label, end_label, start_raw, end_raw, label } ] }
     *
     * @return void
     */
    public function handleAjaxSuggestEvents(): void
    {
        check_ajax_referer('eim_suggest_events_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query      = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $excludeRaw = sanitize_text_field(wp_unslash($_GET['exclude_ids'] ?? ''));
        $excludeIds = array_filter(array_map('intval', explode(',', $excludeRaw)));

        $all = Event::all();

        if ($query !== '') {
            $all = array_values(array_filter(
                $all,
                static fn(Event $e) => mb_stripos($e->name, $query) !== false
            ));
        }

        if (!empty($excludeIds)) {
            $all = array_values(array_filter(
                $all,
                static fn(Event $e) => !in_array($e->id, $excludeIds, true)
            ));
        }

        $dateFormat = (string) get_option('date_format', 'M j, Y');

        $formatDt = static function (?string $utcDt, string $tz) use ($dateFormat): string {
            if (!$utcDt) {
                return '';
            }
            $dt = new \DateTime($utcDt, new \DateTimeZone('UTC'));
            if ($tz !== '') {
                try {
                    $dt->setTimezone(new \DateTimeZone($tz));
                } catch (\Throwable) {
                    // invalid timezone — stay in UTC
                }
            }
            return $dt->format($dateFormat . ', g:i A');
        };

        wp_send_json_success(array_map(static fn(Event $e): array => [
            'id'          => $e->id,
            'name'        => $e->name,
            'start_label' => $formatDt($e->startDatetime, $e->timezone),
            'end_label'   => $e->endDatetime ? $formatDt($e->endDatetime, $e->timezone) : '',
            'start_raw'   => $e->startDatetime ?? '',
            'end_raw'     => $e->endDatetime   ?? '',
            'label'       => $e->name . ($e->startDatetime
                ? ' — ' . $formatDt($e->startDatetime, $e->timezone)
                : ''),
        ], $all));
    }

    /**
     * AJAX: persists a new drag-sorted order for lodging entries on an event.
     *
     * Expected POST params: nonce, event_id, ids[] (ordered lodging IDs).
     */
    public function handleAjaxSortLodging(): void
    {
        check_ajax_referer('eim_event_assignment_sort_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $ids     = wp_unslash($_POST['ids'] ?? []);

        if ($eventId <= 0 || !is_array($ids) || Event::find($eventId) === null) {
            wp_send_json_error('Invalid request.', 400);
        }

        if (!EventLodging::updateSortOrder($eventId, $ids)) {
            wp_send_json_error('Unable to save lodging order.', 500);
        }

        wp_send_json_success(['message' => 'Lodging order saved.']);
    }

    /**
     * AJAX: saves event-specific notes for a lodging assignment.
     *
     * Expected POST params: nonce, event_id, lodging_id, notes.
     */
    public function handleAjaxSaveLodgingNotes(): void
    {
        check_ajax_referer('eim_save_lodging_notes_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId   = (int) ($_POST['event_id']   ?? 0);
        $lodgingId = (int) ($_POST['lodging_id'] ?? 0);
        $notes     = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));

        if ($eventId <= 0 || $lodgingId <= 0) {
            wp_send_json_error('Invalid request.', 400);
        }

        if (!EventLodging::updateNotes($lodgingId, $eventId, $notes)) {
            wp_send_json_error('Unable to save notes.', 500);
        }

        wp_send_json_success(['message' => 'Notes saved.']);
    }

    /**
     * AJAX: persists a new drag-sorted order for menu items of a specific type on an event.
     *
     * Expected POST params: nonce, event_id, type, ids[] (ordered menu item IDs).
     */
    public function handleAjaxSortMenuItems(): void
    {
        check_ajax_referer('eim_event_assignment_sort_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $type    = sanitize_key($_POST['type'] ?? MenuItem::TYPE_FOOD);
        $ids     = wp_unslash($_POST['ids'] ?? []);

        if ($eventId <= 0 || !is_array($ids) || Event::find($eventId) === null) {
            wp_send_json_error('Invalid request.', 400);
        }

        if (!MenuItem::updateEventSortOrder($eventId, $type, $ids)) {
            wp_send_json_error('Unable to save menu item order.', 500);
        }

        wp_send_json_success(['message' => 'Menu item order saved.']);
    }

    /**
     * AJAX: returns filtered and sorted registry gift rows for one event.
     */
    public function handleAjaxSearchEventGifts(): void
    {
        check_ajax_referer('eim_search_event_gifts_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_GET['event_id'] ?? 0);
        $event   = $eventId > 0 ? Event::find($eventId) : null;

        if ($event === null) {
            wp_send_json_error('Event not found.', 404);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeEventGiftSortKey(sanitize_key($_GET['sort'] ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $field   = $this->sanitizeEventGiftFieldKey(sanitize_key($_GET['field'] ?? ''));
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;
        $all     = Gift::forEvent($eventId, $query, $sort, $order, $field);
        $total   = count($all);
        $gifts   = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderEventGiftRows($event, $gifts, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * Sends an invite email for a specific invitation group.
     *
     * GET params: event_id, group_id, _wpnonce
     */
    private function handleSendEventInvite(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $groupId = (int) ($_GET['group_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_send_event_invite_' . $eventId . '_' . $groupId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        $group = InvitationGroup::find($groupId);

        if ($event && $group && $group->eventId === $eventId) {
            $primaryInvitee = Invitee::find($group->primaryInviteeId);
            $members        = $group->getMembers();

            if ($primaryInvitee) {
                if ($primaryInvitee->email === '') {
                    $message = 'invite_no_email';
                } elseif (($qrCode = $this->qrCodeService->getOrCreateForGroup($event, $group)) === null) {
                    $message = 'invite_failed';
                } else {
                    $sent = $this->emailService->sendGroupInvite(
                        $event,
                        $group,
                        $primaryInvitee,
                        $members,
                        $this->qrCodeService->imgTag($qrCode),
                        $this->qrCodeService->inviteUrl($qrCode)
                    );
                    $message = $sent ? 'invite_sent' : 'invite_failed';
                    if ($sent) {
                        InvitationGroup::markInviteSent($groupId);
                    }
                }
            } else {
                $message = 'not_found';
            }
        } else {
            $message = 'not_found';
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => $message,
        ]) . '#eim-etab-invitees');
        exit;
    }

    /**
     * Sends invite emails to all groups in an event that have not yet received one.
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
            $groups = InvitationGroup::forEvent($eventId);

            foreach ($groups as $group) {
                if ($group->inviteSentAt !== null) {
                    continue;
                }

                $primaryInvitee = Invitee::find($group->primaryInviteeId);
                if ($primaryInvitee === null || $primaryInvitee->email === '') {
                    continue;
                }

                $qrCode = $this->qrCodeService->getOrCreateForGroup($event, $group);
                if ($qrCode === null) {
                    continue;
                }

                $members = $group->getMembers();

                if ($this->emailService->sendGroupInvite(
                    $event,
                    $group,
                    $primaryInvitee,
                    $members,
                    $this->qrCodeService->imgTag($qrCode),
                    $this->qrCodeService->inviteUrl($qrCode)
                )) {
                    InvitationGroup::markInviteSent($group->id);
                    $sentCount++;
                }
            }
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'invites_sent',
            'count'       => $sentCount,
        ]) . '#eim-etab-invitees');
        exit;
    }

    /**
     * Generates QR code images for every invitation group in an event without
     * sending any emails. Useful for testing the RSVP flow before invites go out.
     */
    private function handleGenerateAllQrCodes(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_generate_all_qr_codes_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event     = Event::find($eventId);
        $generated = 0;

        if ($event) {
            foreach (InvitationGroup::forEvent($eventId) as $group) {
                $qrCode = $this->qrCodeService->getOrCreateForGroup($event, $group);
                if ($qrCode !== null) {
                    $generated++;
                }
            }
        }

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'qr_codes_generated',
            'count'       => $generated,
        ]) . '#eim-etab-invitees');
        exit;
    }

    /**
     * Deletes every QR code record and stored image file for one event.
     *
     * This lets admins regenerate QR codes after a domain change so the encoded
     * invite URLs use the current site URL.
     */
    private function handleDeleteAllQrCodes(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_all_qr_codes_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $deleted = Event::find($eventId) !== null
            ? QrCode::deleteForEvent($eventId)
            : 0;

        wp_redirect(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'qr_codes_deleted',
            'count'       => $deleted,
        ]) . '#eim-etab-invitees');
        exit;
    }

    /** Streams a CSV export of all event data to the browser as a file download. */
    private function handleExportEventCsv(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'eim_export_event_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        if ($event === null) {
            wp_die('Event not found.');
        }

        [$groups, $foodById, $bevById, $lodgingById, $purchaseMap, $gifts, $allMessages]
            = $this->collectEventExportData($event);

        $filename = sanitize_file_name('event-' . $eventId . '-' . $event->name . '-export.csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');

        // ── Event Details ──────────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'EVENT DETAILS']);
        fputcsv($out, ['Name',          $event->name]);
        fputcsv($out, ['Description',   $event->description]);
        fputcsv($out, ['Start Date',    $event->startDatetime ?? '']);
        fputcsv($out, ['End Date',      $event->endDatetime   ?? '']);
        fputcsv($out, ['Timezone',      $event->timezone]);
        fputcsv($out, ['Calendar Span Start Date', $event->calendarSpanStartDate ?? '']);
        fputcsv($out, ['Calendar Span End Date',   $event->calendarSpanEndDate   ?? '']);
        fputcsv($out, ['Calendar Span Title',      $event->calendarSpanTitle]);
        fputcsv($out, ['Calendar Span Description', $event->calendarSpanDescription]);
        fputcsv($out, ['RSVP Start',    $event->rsvpStartDatetime ?? '']);
        fputcsv($out, ['RSVP Deadline', $event->rsvpDeadline ?? '']);
        fputcsv($out, ['Before RSVP Start Page ID', $event->rsvpBeforeStartPageId !== null ? (string) $event->rsvpBeforeStartPageId : '']);
        fputcsv($out, ['Max Invitees',  $event->maxInvitees !== null ? (string) $event->maxInvitees : 'Unlimited']);
        $venue = $event->venueId ? Location::find($event->venueId) : null;
        fputcsv($out, ['Venue Name',    $venue ? $venue->name : '']);
        fputcsv($out, ['Venue Address', $venue ? $venue->formattedAddress() : '']);
        fputcsv($out, []);

        // ── Invited Invitees ───────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'INVITED INVITEES']);
        fputcsv($out, [
            'Group ID', 'Confirmation Code', 'QR Image URL', 'QR SVG URL', 'QR PNG URL', 'Is Primary',
            'First Name', 'Last Name', 'Email', 'Phone',
            'RSVP Status', 'Registered At',
            'Food Selection', 'Beverage Selection', 'Dietary Notes', 'Lodging Selection',
        ]);
        foreach ($groups as [$group, $qrCode, $members]) {
            foreach ($members as $member) {
                $isPrimary = $member->id === $group->primaryInviteeId;
                fputcsv($out, [
                    $group->id,
                    $qrCode?->confirmationCode ?? '',
                    $qrCode?->imageUrl() ?? '',
                    $qrCode?->svgUrl() ?? '',
                    $qrCode?->pngUrl() ?? '',
                    $isPrimary ? 'Yes' : 'No',
                    $member->firstName,
                    $member->lastName,
                    $member->email,
                    $member->phone,
                    $member->rsvpStatus ?: 'pending',
                    $member->registeredAt ?? '',
                    $member->foodOptionId    ? ($foodById[$member->foodOptionId]    ?? $member->foodOptionId)    : '',
                    $member->beverageOptionId ? ($bevById[$member->beverageOptionId] ?? $member->beverageOptionId) : '',
                    $member->dietaryNotes ?? '',
                    $this->lodgingLabelForMember($member, $lodgingById),
                ]);
            }
        }
        fputcsv($out, []);

        // ── Messages ───────────────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'MESSAGES']);
        fputcsv($out, ['Group ID', 'Confirmation Code', 'Direction', 'Message', 'Is Read', 'Sent At']);
        foreach ($allMessages as $msg) {
            fputcsv($out, [
                $msg['group_id'],
                $msg['confirmation_code'],
                $msg['is_admin_reply'] ? 'Admin Reply' : 'Invitee',
                $msg['message'],
                $msg['is_read'] ? 'Yes' : 'No',
                $msg['created_at'],
            ]);
        }
        fputcsv($out, []);

        // ── Registry: Claimed ─────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'REGISTRY - CLAIMED ITEMS']);
        fputcsv($out, ['Gift Name', 'Description', 'Price', 'Purchased By (Group ID)', 'Purchased By (Confirmation Code)', 'Purchased At']);
        foreach ($gifts as $gift) {
            $p = $purchaseMap[$gift->id] ?? null;
            if (empty($p['is_purchased'])) continue;
            $ownerGroupId = (int) ($p['purchased_by_group_id'] ?? 0);
            $ownerCode    = '';
            foreach ($groups as [$g, $qr, $_m]) {
                if ($g->id === $ownerGroupId) { $ownerCode = $qr?->confirmationCode ?? ''; break; }
            }
            fputcsv($out, [
                $gift->name,
                $gift->description,
                $gift->formattedPrice(),
                $ownerGroupId ?: '',
                $ownerCode,
                $p['purchased_at'] ?? '',
            ]);
        }
        fputcsv($out, []);

        // ── Registry: Available ────────────────────────────────────────────────
        fputcsv($out, ['SECTION', 'REGISTRY - AVAILABLE ITEMS']);
        fputcsv($out, ['Gift Name', 'Description', 'Price', 'Website URL']);
        foreach ($gifts as $gift) {
            $p = $purchaseMap[$gift->id] ?? null;
            if (!empty($p['is_purchased'])) continue;
            fputcsv($out, [$gift->name, $gift->description, $gift->formattedPrice(), $gift->websiteUrl]);
        }

        fclose($out);
        exit;
    }

    /** Streams a JSON export of all event data to the browser as a file download. */
    private function handleExportEventJson(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'eim_export_event_' . $eventId)) {
            wp_die('Security check failed.');
        }

        $event = Event::find($eventId);
        if ($event === null) {
            wp_die('Event not found.');
        }

        [$groups, $foodById, $bevById, $lodgingById, $purchaseMap, $gifts, $allMessages]
            = $this->collectEventExportData($event);

        $venue  = $event->venueId ? Location::find($event->venueId) : null;
        $lodgingOptions = EventLodging::forEvent($eventId);

        $messagesByGroup = [];
        foreach ($allMessages as $msg) {
            $messagesByGroup[$msg['group_id']][] = $msg;
        }

        $groupsPayload = [];
        foreach ($groups as [$group, $qrCode, $members]) {
            $membersPayload = array_map(function (Invitee $m) use ($group, $foodById, $bevById, $lodgingById): array {
                return [
                    'invitee_id'       => $m->id,
                    'is_primary'       => $m->id === $group->primaryInviteeId,
                    'first_name'       => $m->firstName,
                    'last_name'        => $m->lastName,
                    'email'            => $m->email,
                    'phone'            => $m->phone,
                    'street_address'   => $m->streetAddress,
                    'city'             => $m->city,
                    'state'            => $m->state,
                    'zip_code'         => $m->zipCode,
                    'rsvp_status'      => $m->rsvpStatus ?: 'pending',
                    'registered_at'    => $m->registeredAt,
                    'food_option_id'   => $m->foodOptionId,
                    'food_selection'   => $m->foodOptionId ? ($foodById[$m->foodOptionId] ?? null) : null,
                    'beverage_option_id'  => $m->beverageOptionId,
                    'beverage_selection'  => $m->beverageOptionId ? ($bevById[$m->beverageOptionId] ?? null) : null,
                    'dietary_notes'    => $m->dietaryNotes,
                    'lodging_selection'=> $this->lodgingLabelForMember($m, $lodgingById) ?: null,
                    'lodging_confirmed_at' => $m->lodgingConfirmedAt,
                ];
            }, $members);

            $msgs = array_map(static fn(array $m): array => [
                'direction'  => $m['is_admin_reply'] ? 'admin' : 'invitee',
                'message'    => $m['message'],
                'is_read'    => (bool) $m['is_read'],
                'sent_at'    => $m['created_at'],
            ], $messagesByGroup[$group->id] ?? []);

            $claimed = [];
            foreach ($gifts as $gift) {
                $p = $purchaseMap[$gift->id] ?? null;
                if (!empty($p['is_purchased']) && (int) ($p['purchased_by_group_id'] ?? 0) === $group->id) {
                    $claimed[] = [
                        'gift_id'      => $gift->id,
                        'gift_name'    => $gift->name,
                        'formatted_price' => $gift->formattedPrice(),
                        'website_url'  => $gift->websiteUrl,
                        'purchased_at' => $p['purchased_at'] ?? null,
                    ];
                }
            }

            $groupsPayload[] = [
                'invitation_group_id' => $group->id,
                'invite_sent_at'      => $group->inviteSentAt,
                'confirmation_code'   => $qrCode?->confirmationCode,
                'qr_image_url'        => $qrCode?->imageUrl(),
                'qr_svg_url'          => $qrCode?->svgUrl(),
                'qr_png_url'          => $qrCode?->pngUrl(),
                'rsvp_notes'          => $group->rsvpNotes,
                'lodging_booked'      => $group->lodgingBooked,
                'lodging_notes'       => $group->lodgingNotes,
                'members'             => $membersPayload,
                'messages'            => $msgs,
                'registry_claimed'    => $claimed,
            ];
        }

        $registryClaimed   = [];
        $registryAvailable = [];
        foreach ($gifts as $gift) {
            $p = $purchaseMap[$gift->id] ?? null;
            $base = [
                'gift_id'         => $gift->id,
                'name'            => $gift->name,
                'description'     => $gift->description,
                'formatted_price' => $gift->formattedPrice(),
                'price_cents'     => $gift->priceCents,
                'website_url'     => $gift->websiteUrl,
                'image_url'       => $gift->imageUrl('medium') ?: $gift->imageUrl('full'),
            ];
            if (!empty($p['is_purchased'])) {
                $registryClaimed[] = $base + [
                    'purchased_by_group_id' => $p['purchased_by_group_id'] ?? null,
                    'purchased_at'          => $p['purchased_at'] ?? null,
                ];
            } else {
                $registryAvailable[] = $base;
            }
        }

        $payload = [
            'exported_at' => current_time('mysql'),
            'event'       => [
                'id'           => $event->id,
                'name'         => $event->name,
                'description'  => $event->description,
                'start_datetime' => $event->startDatetime,
                'end_datetime'   => $event->endDatetime,
                'timezone'     => $event->timezone,
                'calendar_span_start_date'  => $event->calendarSpanStartDate,
                'calendar_span_end_date'    => $event->calendarSpanEndDate,
                'calendar_span_title'       => $event->calendarSpanTitle,
                'calendar_span_description' => $event->calendarSpanDescription,
                'rsvp_start_datetime' => $event->rsvpStartDatetime,
                'rsvp_deadline'=> $event->rsvpDeadline,
                'rsvp_before_start_page_id'  => $event->rsvpBeforeStartPageId,
                'rsvp_after_deadline_page_id' => $event->rsvpAfterDeadlinePageId,
                'max_invitees' => $event->maxInvitees,
                'venue'        => $venue ? ['name' => $venue->name, 'address' => $venue->formattedAddress(), 'booking_url' => $venue->bookingUrl] : null,
            ],
            'lodging_options' => array_map(static fn(EventLodging $l): array => [
                'id'          => $l->id,
                'name'        => $l->name,
                'address'     => $l->formattedAddress(),
                'booking_url' => $l->bookingUrl,
                'is_other'    => $l->isOther,
                'notes'       => $l->notes,
            ], $lodgingOptions),
            'menu_options' => [
                'food'     => array_map(static fn(MenuItem $i): array => ['id' => $i->id, 'label' => $i->label, 'description' => $i->description], array_values($foodById ? MenuItem::forEventByType($eventId, MenuItem::TYPE_FOOD) : [])),
                'beverage' => array_map(static fn(MenuItem $i): array => ['id' => $i->id, 'label' => $i->label, 'description' => $i->description], array_values($bevById ? MenuItem::forEventByType($eventId, MenuItem::TYPE_BEVERAGE) : [])),
            ],
            'invitation_groups' => $groupsPayload,
            'registry' => [
                'claimed'   => $registryClaimed,
                'available' => $registryAvailable,
            ],
        ];

        $filename = sanitize_file_name('event-' . $eventId . '-' . $event->name . '-export.json');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Collects all data needed for event export in one place.
     *
     * Returns: [groups[], foodById[], bevById[], lodgingById[], purchaseMap[], gifts[], allMessages[]]
     * Each entry in groups is [InvitationGroup, ?QrCode, Invitee[]].
     *
     * @return array{0:array,1:array,2:array,3:array,4:array,5:array,6:array}
     */
    private function collectEventExportData(Event $event): array
    {
        $eventId = $event->id;

        $foodById = [];
        foreach (MenuItem::forEventByType($eventId, MenuItem::TYPE_FOOD) as $item) {
            $foodById[$item->id] = $item->label;
        }
        $bevById = [];
        foreach (MenuItem::forEventByType($eventId, MenuItem::TYPE_BEVERAGE) as $item) {
            $bevById[$item->id] = $item->label;
        }
        $lodgingById = [];
        foreach (EventLodging::forEvent($eventId) as $option) {
            $lodgingById[$option->id] = $option->name;
        }

        $gifts       = Gift::forEvent($eventId, '', 'name', 'asc');
        $purchaseMap = Gift::purchaseDetailsForEvent($eventId);

        // Build per-group tuples: [InvitationGroup, ?QrCode, Invitee[]]
        $groups = [];
        foreach (InvitationGroup::forEvent($eventId) as $group) {
            $groups[] = [$group, QrCode::findForGroup($group->id), $group->getMembers()];
        }

        // Collect all messages for the event, keyed by invitation group ID (via connection group).
        global $wpdb;
        $msgTable  = \EventsInviteManager\Database\DatabaseManager::eventMessagesTable();
        $rawMsgs   = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$msgTable} WHERE event_id = %d ORDER BY created_at ASC", $eventId),
            ARRAY_A
        ) ?? [];

        // Build a map: connection_group_id → [invitation_group_id, confirmation_code]
        $cgToGroup = [];
        foreach ($groups as [$g, $qr, $_m]) {
            $cgId = $this->resolveConnectionGroupIdForExport($g);
            if ($cgId !== null) {
                $cgToGroup[$cgId] = ['group_id' => $g->id, 'confirmation_code' => $qr?->confirmationCode ?? ''];
            }
        }

        $allMessages = [];
        foreach ($rawMsgs as $row) {
            $cgId   = (int) $row['connection_group_id'];
            $lookup = $cgToGroup[$cgId] ?? ['group_id' => $cgId, 'confirmation_code' => ''];
            $allMessages[] = array_merge($row, $lookup);
        }

        return [$groups, $foodById, $bevById, $lodgingById, $purchaseMap, $gifts, $allMessages];
    }

    /** Returns a human-readable lodging label for a member's current selection. */
    private function lodgingLabelForMember(Invitee $member, array $lodgingById): string
    {
        if ($member->lodgingUndisclosed) return 'Prefer not to disclose';
        if ($member->lodgingIsOther)     return 'Other';
        if ($member->lodgingId)          return $lodgingById[$member->lodgingId] ?? ('Option #' . $member->lodgingId);
        return '';
    }

    /**
     * Resolves the most appropriate connection group ID for an invitation group.
     * Mirrors the logic in AbstractApiController::resolveConnectionGroupId().
     */
    private function resolveConnectionGroupIdForExport(InvitationGroup $group): ?int
    {
        $cgs = ConnectionGroup::forInvitee($group->primaryInviteeId);
        if (empty($cgs)) return null;
        if (count($cgs) === 1) return $cgs[0]->id;

        $memberIds = array_map(static fn(Invitee $m): int => $m->id, $group->getMembers());
        foreach ($cgs as $cg) {
            $cgIds = array_map(static fn(Invitee $m): int => $m->id, $cg->getMembers());
            if (empty(array_diff($memberIds, $cgIds))) return $cg->id;
        }
        return $cgs[0]->id;
    }

    /**
     * AJAX: sends a test invite email for an event to a single address.
     *
     * Expected POST params: nonce, event_id, test_email.
     * Returns JSON: { success: true, data: { email } }
     */
    public function handleAjaxSendInviteTest(): void
    {
        check_ajax_referer('eim_send_invite_test_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $email   = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        $event   = Event::find($eventId);

        if (!$event) {
            wp_send_json_error('Event not found.');
        }

        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address.');
        }

        if ($this->emailService->sendGroupInviteTest($event, $email)) {
            wp_send_json_success(['email' => $email]);
        } else {
            wp_send_json_error('Failed to send. Check that the email template is saved and your server mail configuration.');
        }
    }

    /**
     * AJAX: sends invite emails to all unsent invitation groups for an event.
     *
     * Expected POST params: nonce, event_id.
     * Returns JSON: { success: true, data: { sent, failed, total } }
     */
    public function handleAjaxSendAllInvites(): void
    {
        check_ajax_referer('eim_send_all_invites_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $event   = Event::find($eventId);

        if (!$event) {
            wp_send_json_error('Event not found.');
        }

        $groups = InvitationGroup::forEvent($eventId);
        $unsent = array_values(array_filter($groups, static fn(InvitationGroup $g) => $g->inviteSentAt === null));

        if (empty($unsent)) {
            wp_send_json_error('No unsent invitation groups found for this event.');
        }

        $sent   = 0;
        $failed = 0;

        foreach ($unsent as $group) {
            $primaryInvitee = Invitee::find($group->primaryInviteeId);
            if ($primaryInvitee === null || $primaryInvitee->email === '') {
                $failed++;
                continue;
            }

            $qrCode = $this->qrCodeService->getOrCreateForGroup($event, $group);
            if ($qrCode === null) {
                $failed++;
                continue;
            }

            $members = $group->getMembers();

            if ($this->emailService->sendGroupInvite(
                $event,
                $group,
                $primaryInvitee,
                $members,
                $this->qrCodeService->imgTag($qrCode),
                $this->qrCodeService->inviteUrl($qrCode)
            )) {
                InvitationGroup::markInviteSent($group->id);
                $sent++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success([
            'sent'   => $sent,
            'failed' => $failed,
            'total'  => count($unsent),
        ]);
    }

    /**
     * AJAX: returns messages for a specific connection group and event.
     *
     * Expected GET params: nonce, event_id, group_id.
     * Returns JSON: { success: true, data: { messages: [{id, message, is_read, created_at}] } }
     */
    public function handleAjaxGetGroupMessages(): void
    {
        check_ajax_referer('eim_get_group_messages_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_GET['event_id'] ?? 0);
        $groupId = (int) ($_GET['group_id'] ?? 0);

        if (!$eventId || !$groupId) {
            wp_send_json_error('Missing parameters.');
        }

        $messages = EventMessage::forEventGroup($eventId, $groupId);

        wp_send_json_success([
            'messages' => array_map(static fn(EventMessage $m) => [
                'id'             => $m->id,
                'message'        => $m->message,
                'is_read'        => $m->isRead,
                'is_admin_reply' => $m->isAdminReply,
                'created_at'     => $m->createdAt,
            ], $messages),
        ]);
    }

    /**
     * AJAX: sets the is_read flag on a message.
     *
     * Expected POST params: nonce, message_id, is_read (0 or 1).
     * Returns JSON: { success: true }
     */
    public function handleAjaxMarkMessageRead(): void
    {
        check_ajax_referer('eim_mark_message_read_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $isRead    = !empty($_POST['is_read']);

        if (!$messageId) {
            wp_send_json_error('Missing message_id.');
        }

        EventMessage::setRead($messageId, $isRead);
        wp_send_json_success();
    }

    /**
     * AJAX: deletes a single message.
     *
     * Expected POST params: nonce, message_id.
     * Returns JSON: { success: true }
     */
    public function handleAjaxDeleteMessage(): void
    {
        check_ajax_referer('eim_delete_message_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $messageId = (int) ($_POST['message_id'] ?? 0);

        if (!$messageId) {
            wp_send_json_error('Missing message_id.');
        }

        EventMessage::delete($messageId);
        wp_send_json_success();
    }

    /**
     * AJAX: posts an admin reply to a message thread.
     *
     * Marks all existing invitee messages in the thread as read, inserts the reply,
     * and returns the updated thread so the UI can refresh in place.
     *
     * Expected POST params: nonce, event_id, group_id, message.
     * Returns JSON: { success: true, data: { reply_id, messages: [{id, message, is_read, is_admin_reply, created_at}] } }
     */
    public function handleAjaxReplyToMessage(): void
    {
        check_ajax_referer('eim_reply_to_message_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_POST['event_id'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if (!$eventId || !$groupId || $message === '') {
            wp_send_json_error('Missing required fields.');
        }

        // Auto-mark all invitee messages in this thread as read before replying.
        EventMessage::markThreadRead($eventId, $groupId);

        $replyId = EventMessage::createAdminReply($eventId, $groupId, $message);
        if ($replyId === false) {
            wp_send_json_error('Failed to save reply. Please try again.');
        }

        $thread = EventMessage::forEventGroup($eventId, $groupId);

        wp_send_json_success([
            'reply_id' => $replyId,
            'messages' => array_map(static fn(EventMessage $m) => [
                'id'             => $m->id,
                'message'        => $m->message,
                'is_read'        => $m->isRead,
                'is_admin_reply' => $m->isAdminReply,
                'created_at'     => $m->createdAt,
            ], $thread),
        ]);
    }

    /**
     * AJAX handler for the events list table search.
     *
     * Expected GET params: nonce, query, sort, order, field, page, per_page.
     * Returns JSON: { success: true, data: { html, count, total } }
     */
    public function handleAjaxSearchEvents(): void
    {
        check_ajax_referer('eim_search_events_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeEventSortKey((string) ($_GET['sort']    ?? 'start_datetime'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order']  ?? 'desc'));
        $field   = $this->sanitizeEventFieldKey((string) ($_GET['field']  ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;

        $all   = Event::listForAdmin($query, $sort, $order, $field);
        $total = count($all);
        $paged = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderEventRows($paged, $query, ($page - 1) * $perPage);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * Handles wp_ajax_eim_search_event_requested_invitees.
     *
     * Returns paginated rows for the per-event Requested Invitee Add-Ons section.
     *
     * @return void
     */
    public function handleAjaxSearchEventRequests(): void
    {
        check_ajax_referer('eim_search_event_requests_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_GET['event_id'] ?? 0);
        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $sort    = $this->sanitizeRiarSortKey((string) ($_GET['sort']     ?? 'created_at'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order']   ?? 'desc'));
        $field   = $this->sanitizeRiarFieldKey((string) ($_GET['field']   ?? ''));
        $page    = max(1, (int) ($_GET['page']      ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true)
            ? (int) $_GET['per_page'] : 10;

        $all   = RequestedInviteeAddOn::listForEvent($eventId, $query, $sort, $order, $field);
        $total = count($all);
        $paged = array_slice($all, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderEventRequestRows($paged, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    private function sanitizeEventSortKey(string $key): string
    {
        return in_array($key, ['name', 'start_datetime', 'date'], true) ? $key : 'start_datetime';
    }

    private function sanitizeRiarSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['first_name', 'last_name', 'email', 'phone', 'connection_group_name', 'status', 'created_at'], true)
            ? $key : 'created_at';
    }

    private function sanitizeRiarFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['first_name', 'last_name', 'email', 'phone', 'connection_group', 'status'], true)
            ? $field : '';
    }

    private function sanitizeEventFieldKey(string $key): string
    {
        return in_array($key, ['name', 'description'], true) ? $key : '';
    }

    /** Renders the events list view including the monthly calendar grid. */
    private function renderEventsList(): void
    {
        $message      = (string) ($_GET['eim_message'] ?? '');
        $error        = (string) ($_GET['eim_error'] ?? '');
        $hasLocations = Location::count() > 0;
        $search       = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $sort         = $this->sanitizeEventSortKey((string) ($_GET['sort']  ?? 'start_datetime'));
        $order        = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'desc'));
        $field        = $this->sanitizeEventFieldKey((string) ($_GET['field'] ?? ''));
        $all          = Event::listForAdmin($search, $sort, $order, $field);
        $total        = count($all);
        $events       = array_slice($all, 0, 10);

        $calYear  = max(1970, min(2099, (int) ($_GET['cal_year']  ?? date('Y'))));
        $calMonth = max(1,    min(12,   (int) ($_GET['cal_month'] ?? date('n'))));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Events</h1>
            <?php if ($hasLocations): ?>
                <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'add'])); ?>" class="page-title-action">Add New Event</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <?php if (!$hasLocations): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>No locations found.</strong>
                        You need at least one location in the library before creating an event.
                        <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'add'])); ?>">Add a location now →</a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($total === 0 && $search === '' && $hasLocations): ?>
                <p>No events yet. <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'add'])); ?>">Create your first event.</a></p>
            <?php elseif ($hasLocations): ?>

                <?php $this->renderCalendar($calYear, $calMonth); ?>

                <?php $this->renderSearchBar(
                    'eim-event-search',
                    'eim-event-count',
                    'eim-event-loading',
                    'Search events…',
                    $total,
                    $search,
                    [
                        ['value' => 'name',        'label' => 'Name'],
                        ['value' => 'description', 'label' => 'Description'],
                    ],
                    $field
                ); ?>

                <?php $this->renderBulkActions(
                    'eim-events-bulk-form',
                    AdminMenu::tabUrl(AdminMenu::TAB_EVENTS),
                    'bulk_delete_events',
                    'eim_bulk_delete_events'
                ); ?>

                <table id="eim-events-list-table"
                       class="wp-list-table widefat fixed striped"
                       style="margin-top:12px;"
                       data-sort="<?= esc_attr($sort); ?>"
                       data-order="<?= esc_attr($order); ?>"
                       data-total="<?= esc_attr($total); ?>">
                <thead>
                    <tr>
                            <th style="width:30px;">#</th>
                            <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('events'); ?></th>
                            <th style="width:18%;"><?= $this->sortLink('Name', 'name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_EVENTS]); ?></th>
                            <th style="width:20%;"><?= $this->sortLink('Date / Time', 'start_datetime', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, $search, ['tab' => AdminMenu::TAB_EVENTS]); ?></th>
                            <th>Description</th>
                            <th style="width:14%;">Categories</th>
                            <th style="width:12%;">Invitees</th>
                            <th style="width:14%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="eim-events-list-table-body">
                        <?php $this->renderEventRows($events, $search); ?>
                    </tbody>
                </table>
                <?php $this->renderPaginationBar('eim-event-search'); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders event table rows for both initial page load and AJAX responses.
     *
     * @param Event[] $events
     */
    private function renderEventRows(array $events, string $search = '', int $offset = 0): void
    {
        if (empty($events)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No events found.';
            echo '<tr class="eim-no-results"><td colspan="8">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $catsByEvent = Category::forEntities('event', array_map(static fn(Event $e): int => $e->id, $events));
        $catEditBase = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES);

        foreach ($events as $i => $event) {
            $editUrl     = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event->id]);
            $deleteUrl   = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'delete_event', 'id' => $event->id]),
                'eim_delete_event_' . $event->id
            );
            $inviteesUrl = $editUrl . '#eim-etab-invitees';
            $venue       = $event->venueId !== null ? Location::find($event->venueId) : null;
            $total       = $event->inviteeCount();
            $registered  = $event->registeredCount();
            $cats        = $catsByEvent[$event->id] ?? [];
            ?>
            <tr>
                <td class="eim-row-num"><?= $offset + $i + 1; ?></td>
                <?= $this->renderBulkSelectCell('eim-events-bulk-form', 'events', $event->id, $event->name); ?>
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
                    <?php endif; ?>
                </td>
                <td><?= esc_html(wp_trim_words($event->description, 12, '…')); ?></td>
                <td>
                    <?php foreach ($cats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($cats)): ?><span style="color:#999;">—</span><?php endif; ?>
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
            <?php
        }
    }

    /**
     * Renders the monthly calendar grid with event links and a month/year picker.
     *
     * @param int $year  Calendar year to display.
     * @param int $month Calendar month to display (1–12).
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

        $prevUrl = esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['cal_year' => $prevYear, 'cal_month' => $prevMonth]));
        $nextUrl = esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['cal_year' => $nextYear, 'cal_month' => $nextMonth]));
        $baseUrl = AdminMenu::tabUrl(AdminMenu::TAB_EVENTS);
        $pickerId = 'eim-cal-picker-' . $year . '-' . $month;

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
        .eim-cal-picker-wrap{position:relative;display:inline-block;}
        .eim-cal-month-button{background:transparent;border:0;color:#1d2327;cursor:pointer;font:inherit;font-weight:600;margin:0;padding:2px 8px;}
        .eim-cal-month-button:hover,.eim-cal-month-button:focus{color:#135e96;}
        .eim-cal-picker{background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 14px rgba(0,0,0,.14);left:50%;margin-top:6px;padding:12px;position:absolute;top:100%;transform:translateX(-50%);width:245px;z-index:20;}
        .eim-cal-picker[hidden]{display:none;}
        .eim-cal-picker-year{align-items:center;display:flex;gap:6px;margin-bottom:10px;}
        .eim-cal-picker-year input{flex:1;min-width:0;text-align:center;}
        .eim-cal-picker-months{display:grid;gap:6px;grid-template-columns:repeat(3,1fr);}
        .eim-cal-picker-months .button{min-height:30px;text-align:center;}
        .eim-cal-picker-months .button-primary{box-shadow:none;}
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
                <div class="eim-cal-picker-wrap">
                    <h2>
                        <button type="button"
                                class="eim-cal-month-button"
                                aria-haspopup="dialog"
                                aria-expanded="false"
                                aria-controls="<?= esc_attr($pickerId); ?>">
                            <?= esc_html($monthLabel); ?>
                        </button>
                    </h2>
                    <div id="<?= esc_attr($pickerId); ?>"
                         class="eim-cal-picker"
                         data-base-url="<?= esc_url($baseUrl); ?>"
                         hidden>
                        <div class="eim-cal-picker-year">
                            <button type="button" class="button eim-cal-year-step" data-step="-1" aria-label="Previous year">&lsaquo;</button>
                            <input type="number"
                                   class="small-text eim-cal-year-input"
                                   min="1970"
                                   max="2099"
                                   value="<?= esc_attr($year); ?>"
                                   aria-label="Calendar year">
                            <button type="button" class="button eim-cal-year-step" data-step="1" aria-label="Next year">&rsaquo;</button>
                        </div>
                        <div class="eim-cal-picker-months">
                            <?php for ($pickerMonth = 1; $pickerMonth <= 12; $pickerMonth++): ?>
                                <?php $isCurrentPickerMonth = $pickerMonth === $month; ?>
                                <button type="button"
                                        class="button <?= $isCurrentPickerMonth ? 'button-primary' : ''; ?>"
                                        data-month="<?= esc_attr($pickerMonth); ?>">
                                    <?= esc_html(date_i18n('M', mktime(0, 0, 0, $pickerMonth, 1, $year))); ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <a href="<?= $nextUrl; ?>" class="button"><?= esc_html(date_i18n('M', mktime(0, 0, 0, $nextMonth, 1, $nextYear))); ?> &rsaquo;</a>

                <?php if (!empty($allDated)): ?>
                <div class="eim-jump-wrap">
                    <select onchange="if(this.value) window.location.href = this.value;" aria-label="Jump to event">
                        <option value="">— Jump to event —</option>
                        <?php foreach ($allDated as $e): ?>
                            <?php
                            $jumpUrl = esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $e->id]));
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
                                            <?php $editUrl = esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $e->id])); ?>
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
        <script>
        (() => {
            const picker = document.getElementById(<?= wp_json_encode($pickerId); ?>);
            if (!picker) return;

            const wrap = picker.closest('.eim-cal-picker-wrap');
            const toggle = wrap?.querySelector('.eim-cal-month-button');
            const yearInput = picker.querySelector('.eim-cal-year-input');
            const baseUrl = picker.dataset.baseUrl;

            const setOpen = (open) => {
                picker.hidden = !open;
                toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    yearInput?.focus();
                    yearInput?.select();
                }
            };

            toggle?.addEventListener('click', () => setOpen(picker.hidden));

            picker.addEventListener('click', (event) => {
                const yearStep = event.target.closest('.eim-cal-year-step');
                if (yearStep && yearInput) {
                    const nextYear = Number(yearInput.value || <?= (int) $year; ?>) + Number(yearStep.dataset.step || 0);
                    yearInput.value = String(Math.min(2099, Math.max(1970, nextYear)));
                    return;
                }

                const monthButton = event.target.closest('[data-month]');
                if (!monthButton || !baseUrl || !yearInput) return;

                const url = new URL(baseUrl, window.location.href);
                url.searchParams.set('cal_year', yearInput.value || <?= (int) $year; ?>);
                url.searchParams.set('cal_month', monthButton.dataset.month);
                window.location.href = url.toString();
            });

            document.addEventListener('click', (event) => {
                if (!picker.hidden && wrap && !wrap.contains(event.target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !picker.hidden) {
                    setOpen(false);
                    toggle?.focus();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Renders the tabbed event add/edit form.
     *
     * @param Event|null $event Existing event to edit, or null when creating.
     */
    private function renderEventForm(?Event $event): void
    {
        $isNew = $event === null;

        if ($isNew && Location::count() === 0) {
            ?>
            <div class="wrap">
                <h1>Add New Event</h1>
                <div class="notice notice-warning">
                    <p>
                        <strong>No locations found.</strong>
                        You must add at least one location to the library before creating an event.
                        <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_LOCATIONS, ['action' => 'add'])); ?>">Add a location now →</a>
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        $message          = (string) ($_GET['eim_message'] ?? '');
        $error            = (string) ($_GET['eim_error'] ?? '');
        $title            = $isNew ? 'Add New Event' : 'Edit Event: ' . $event->name;
        $addLodgingFormId = 'eim-add-lodging-form';
        $saveLabel        = $isNew ? 'Create Event' : 'Update Event';

        $venue           = (!$isNew && $event->venueId !== null) ? Location::find($event->venueId) : null;
        $venueLocationId = $venue ? $venue->id : 0;
        $venueAddress    = $venue ? $venue->formattedAddress() : '';
        $pages           = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);
        $selectedPageId       = $isNew ? 0 : ($event->rsvpPageId ?? 0);
        $selectedDashboard    = $isNew ? 0 : ($event->dashboardPageId ?? 0);
        $selectedBeforeStart   = $isNew ? 0 : ($event->rsvpBeforeStartPageId   ?? 0);
        $selectedAfterDeadline = $isNew ? 0 : ($event->rsvpAfterDeadlinePageId ?? 0);
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>" style="margin-top:12px;display:block;">← Back to Events</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error, (int) ($_GET['count'] ?? 0)); ?>

            <?php if (!$isNew): ?>
                <form id="<?= esc_attr($addLodgingFormId); ?>" method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>"></form>
                <?php $this->renderBulkActionFormShell(
                    'eim-event-lodging-bulk-form',
                    AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event->id]),
                    'bulk_remove_lodging_from_event',
                    'eim_bulk_remove_lodging_from_event_' . $event->id,
                    ['event_id' => $event->id]
                ); ?>
            <?php endif; ?>

            <?php if (!$isNew):
                $exportCsvUrl  = wp_nonce_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'export_event_csv',  'event_id' => $event->id]), 'eim_export_event_' . $event->id);
                $exportJsonUrl = wp_nonce_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'export_event_json', 'event_id' => $event->id]), 'eim_export_event_' . $event->id);
            ?>
            <div style="display:flex;gap:8px;align-items:center;margin:16px 0 0;">
                <a href="<?= esc_url($exportCsvUrl); ?>"  class="button button-secondary">⬇ Export to CSV</a>
                <a href="<?= esc_url($exportJsonUrl); ?>" class="button button-secondary">⬇ Export to JSON</a>
            </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper eim-event-tabs" data-event-id="<?= esc_attr($isNew ? '0' : $event->id); ?>" style="margin-top:12px;">
                <a href="#eim-etab-details"  class="nav-tab" data-etab="details">Details</a>
                <a href="#eim-etab-venue"    class="nav-tab" data-etab="venue">Venue/Location</a>
                <a href="#eim-etab-email"    class="nav-tab" data-etab="email">Invite Email</a>
                <a href="#eim-etab-qr"       class="nav-tab" data-etab="qr">QR Code &amp; RSVP</a>
	                <a href="#eim-etab-lodging"  class="nav-tab" data-etab="lodging">Lodging</a>
	                <a href="#eim-etab-food"     class="nav-tab" data-etab="food">Food &amp; Beverage</a>
	                <?php if (!$isNew): ?>
	                    <a href="#eim-etab-gifts" class="nav-tab" data-etab="gifts">Gifts &amp; Registry</a>
	                    <a href="#eim-etab-invitees" class="nav-tab" data-etab="invitees">Invited Invitees</a>
	                    <a href="#eim-etab-messages" class="nav-tab" data-etab="messages">Messages</a>
	                <?php endif; ?>
            </nav>

            <form id="eim-event-form" method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>">
                <?php wp_nonce_field('eim_save_event'); ?>
                <input type="hidden" name="eim_action" value="save_event">
                <input type="hidden" name="event_id" value="<?= esc_attr($isNew ? 0 : $event->id); ?>">

                <!-- ── Details ──────────────────────────────────────────── -->
                <div id="eim-etab-details" class="eim-etab-panel">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="eim_name">Event Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                            <td>
                                <input type="text" id="eim_name" name="name" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $event->name); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_description">Description</label></th>
                            <td>
                                <textarea id="eim_description" name="description" class="large-text" rows="4"><?= esc_textarea($isNew ? '' : $event->description); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_start_datetime">Event Start</label></th>
                            <td>
                                <?php $startVal = (!$isNew && $event->startDatetime) ? $this->utcToDatetimeLocal($event->startDatetime, $event->timezone) : ''; ?>
                                <input type="datetime-local" id="eim_start_datetime" name="start_datetime" value="<?= esc_attr($startVal); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_end_datetime">Event End</label></th>
                            <td>
                                <?php $endVal = (!$isNew && $event->endDatetime) ? $this->utcToDatetimeLocal($event->endDatetime, $event->timezone) : ''; ?>
                                <input type="datetime-local" id="eim_end_datetime" name="end_datetime" value="<?= esc_attr($endVal); ?>">
                                <p class="description">Leave blank if the event has no fixed end time.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_calendar_span_start_date">Save the Date Span Start</label></th>
                            <td>
                                <input type="date"
                                       id="eim_calendar_span_start_date"
                                       name="calendar_span_start_date"
                                       value="<?= esc_attr($isNew ? '' : ($event->calendarSpanStartDate ?? '')); ?>">
                                <p class="description">Optional all-day calendar span for destination weekends or travel holds.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_calendar_span_end_date">Save the Date Span End</label></th>
                            <td>
                                <input type="date"
                                       id="eim_calendar_span_end_date"
                                       name="calendar_span_end_date"
                                       value="<?= esc_attr($isNew ? '' : ($event->calendarSpanEndDate ?? '')); ?>">
                                <p class="description">Inclusive end date. Leave blank to use a single all-day date.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_calendar_span_title">Save the Date Title</label></th>
                            <td>
                                <input type="text"
                                       id="eim_calendar_span_title"
                                       name="calendar_span_title"
                                       class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $event->calendarSpanTitle); ?>">
                                <p class="description">Optional. Defaults to the event name.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_calendar_span_description">Save the Date Description</label></th>
                            <td>
                                <textarea id="eim_calendar_span_description"
                                          name="calendar_span_description"
                                          class="large-text"
                                          rows="3"><?= esc_textarea($isNew ? '' : $event->calendarSpanDescription); ?></textarea>
                                <p class="description">Optional. Defaults to the event description.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_timezone">Timezone</label></th>
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
                            <th scope="row"><label for="eim_max_invitees">Max Invitees</label></th>
                            <td>
                                <input type="number" id="eim_max_invitees" name="max_invitees" min="1" step="1"
                                       class="small-text"
                                       value="<?= esc_attr($isNew ? '' : ($event->maxInvitees ?? '')); ?>">
                                <p class="description">
                                    Leave blank for no limit. Counts individual people, not invitation groups.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_rsvp_start_datetime">RSVP Start</label></th>
                            <td>
                                <?php $rsvpStartVal = (!$isNew && $event->rsvpStartDatetime) ? $this->utcToDatetimeLocal($event->rsvpStartDatetime, $event->timezone) : ''; ?>
                                <input type="datetime-local" id="eim_rsvp_start_datetime" name="rsvp_start_datetime" value="<?= esc_attr($rsvpStartVal); ?>">
                                <p class="description">Invitees cannot submit RSVP flow changes before this date and time. Leave blank to open RSVPs immediately.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_rsvp_before_start_page_id">Before RSVP Start Page</label></th>
                            <td>
                                <select id="eim_rsvp_before_start_page_id" name="rsvp_before_start_page_id">
                                    <option value="0">— No before-start page selected —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?= esc_attr($page->ID); ?>" <?php selected($selectedBeforeStart, $page->ID); ?>>
                                            <?= esc_html($page->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Guests are sent here when they scan or attempt to RSVP before the RSVP Start date and time.
                                    <?php if ($selectedBeforeStart > 0): ?>
                                        <br><a href="<?= esc_url(get_permalink($selectedBeforeStart)); ?>" target="_blank"><?= esc_html(get_the_title($selectedBeforeStart)); ?> ↗</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_rsvp_deadline">RSVP Deadline</label></th>
                            <td>
                                <?php $rsvpDeadlineVal = (!$isNew && $event->rsvpDeadline) ? $this->utcToDatetimeLocal($event->rsvpDeadline, $event->timezone) : ''; ?>
                                <input type="datetime-local" id="eim_rsvp_deadline" name="rsvp_deadline" value="<?= esc_attr($rsvpDeadlineVal); ?>">
                                <p class="description">Invitees must submit their initial RSVP (attending / not attending) by this date and time. Leave blank for no deadline.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_rsvp_after_deadline_page_id">After RSVP Deadline Page</label></th>
                            <td>
                                <select id="eim_rsvp_after_deadline_page_id" name="rsvp_after_deadline_page_id">
                                    <option value="0">— No after-deadline page selected —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?= esc_attr($page->ID); ?>" <?php selected($selectedAfterDeadline, $page->ID); ?>>
                                            <?= esc_html($page->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Guests who attempt to RSVP for the first time after the RSVP Deadline are sent here instead.
                                    <?php if ($selectedAfterDeadline > 0): ?>
                                        <br><a href="<?= esc_url(get_permalink($selectedAfterDeadline)); ?>" target="_blank"><?= esc_html(get_the_title($selectedAfterDeadline)); ?> ↗</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Categories</label></th>
                            <td>
                                <?php
                                $selCats  = [];
                                $catNonce = wp_create_nonce('eim_suggest_categories_nonce');
                                if (!$isNew) {
                                    foreach (Category::forEntity('event', $event->id) as $cat) {
                                        $selCats[] = [
                                            'id'          => $cat->id,
                                            'name'        => $cat->name,
                                            'parent_name' => $cat->parentName,
                                            'label'       => $cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name,
                                        ];
                                    }
                                }
                                $this->renderCategoryPicker('eim-event-cat-picker', $selCats, $catNonce);
                                ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button($saveLabel, 'primary', 'submit', false); ?>
                </div>

                <!-- ── Venue / Location ─────────────────────────────────── -->
                <div id="eim-etab-venue" class="eim-etab-panel">
                    <input type="hidden" id="eim_venue_library_id" name="venue_library_id"     value="<?= esc_attr($venueLocationId); ?>">
                    <input type="hidden" id="eim_venue_street"     name="venue_street_address" value="<?= esc_attr($venue ? $venue->streetAddress : ''); ?>">
                    <input type="hidden" id="eim_venue_city"       name="venue_city"           value="<?= esc_attr($venue ? $venue->city : ''); ?>">
                    <input type="hidden" id="eim_venue_state"      name="venue_state"          value="<?= esc_attr($venue ? $venue->state : ''); ?>">
                    <input type="hidden" id="eim_venue_zip"        name="venue_zip_code"       value="<?= esc_attr($venue ? $venue->zipCode : ''); ?>">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="eim_venue_name">Venue Name</label></th>
                            <td>
                                <input type="text" id="eim_venue_name" name="venue_name" class="regular-text"
                                       value="<?= esc_attr($venue ? $venue->name : ''); ?>" autocomplete="off">
                                <?php if ($venue && $venue->imageAttachmentId > 0): ?>
                                    <p style="margin-top:6px;"><?= $this->locationImageThumbnailMarkup($venue->imageAttachmentId, $venue->name); ?></p>
                                <?php endif; ?>
                                <p id="eim_venue_address_display" style="margin-top:6px;color:#3c434a;<?= $venueAddress ? '' : 'display:none;'; ?>">
                                    <?= esc_html($venueAddress); ?>
                                </p>
                                <p class="description" style="margin-top:4px;">
                                    Start typing to search the locations catalogue.
                                    <?php if (!$isNew): ?>Clear this field and save to remove the venue.<?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button($saveLabel, 'primary', 'submit', false); ?>
                </div>

                <!-- ── Invite Email ──────────────────────────────────────── -->
                <div id="eim-etab-email" class="eim-etab-panel">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="eim_from_name">From Name</label></th>
                            <td>
                                <input type="text" id="eim_from_name" name="from_name" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $event->fromName); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_from_email">From Email</label></th>
                            <td>
                                <input type="text" id="eim_from_email" name="from_email" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $event->fromEmail); ?>">
                                <p class="description">
                                    Optional. Supports <code>{{ current_domain }}</code>, e.g. <code>noreply@{{current_domain}}</code>.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_invite_email_subject">Subject Line</label></th>
                            <td>
                                <input type="text" id="eim_invite_email_subject" name="invite_email_subject" class="regular-text"
                                       value="<?= esc_attr($isNew ? '' : $event->inviteEmailSubject); ?>">
                                <p class="description">
                                    Tags: <code>{{ event_name }}</code> <code>{{ first_name }}</code> <code>{{ last_name }}</code>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2 class="title">Email Body</h2>
                    <p class="description" style="margin-bottom:4px;">
                        Invitee tags:
                        <code>{{ event_name }}</code> <code>{{ first_name }}</code> <code>{{ last_name }}</code>
                        <code>{{ full_name }}</code> <code>{{ email }}</code> <code>{{ qr_code }}</code> <code>{{ invite_url }}</code>
                    </p>
                    <p class="description" style="margin-bottom:12px;">
                        Group tags:
                        <code>{{ group_names }}</code> — all members' names ·
                        <code>{{ invitee_names }}</code> — same as group_names ·
                        <code>{{ invitee_count }}</code> — number of people in the group
                    </p>

                    <style>
                    #eim-invite-email-layout {
                        display: flex;
                        gap: 20px;
                        align-items: stretch;
                    }
                    #eim-invite-editor-col  { flex: 1 1 0; min-width: 0; }
                    #eim-invite-preview-col { flex: 1 1 0; min-width: 0; display: none; }
                    @media (max-width: 1024px) {
                        #eim-invite-email-layout { flex-direction: column; }
                        #eim-invite-editor-col,
                        #eim-invite-preview-col  { flex: none; width: 100%; }
                    }
                    </style>

                    <div id="eim-invite-email-layout">
                        <div id="eim-invite-editor-col">
                            <?php
                            wp_editor(
                                $isNew ? '' : $event->inviteEmailTemplate,
                                'invite_email_template',
                                ['textarea_name' => 'invite_email_template', 'media_buttons' => false, 'textarea_rows' => 15]
                            );
                            ?>
                            <p style="margin-top:10px;">
                                <button type="button" id="eim-invite-preview-btn" class="button">Preview Email</button>
                            </p>
                        </div>
                        <div id="eim-invite-preview-col">
                            <div style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
                                <div style="background:#f6f7f7;padding:8px 12px;border-bottom:1px solid #dcdcde;display:flex;justify-content:space-between;align-items:center;">
                                    <strong style="font-size:13px;">Email Preview</strong>
                                    <button type="button" id="eim-invite-preview-close"
                                            class="button-link" style="color:#d63638;">Close Preview</button>
                                </div>
                                <iframe id="eim-invite-preview-frame"
                                        style="width:100%;min-height:480px;border:none;display:block;background:#fff;"
                                        title="Invite Email Preview"></iframe>
                            </div>
                        </div>
                    </div>

                    <?php submit_button($saveLabel, 'primary', 'submit', false); ?>
                </div>

                <!-- ── QR Code & RSVP ────────────────────────────────────── -->
                <div id="eim-etab-qr" class="eim-etab-panel">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="eim_rsvp_page_id">QR Code RSVP Page</label></th>
                            <td>
                                <select id="eim_rsvp_page_id" name="rsvp_page_id">
                                    <option value="0">— No redirect page selected —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?= esc_attr($page->ID); ?>" <?php selected($selectedPageId, $page->ID); ?>>
                                            <?= esc_html($page->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    The page guests land on when they scan the QR code from their invitation. This page should contain the RSVP shortcode so guests can confirm their attendance and food/beverage preferences.
                                    <?php if ($selectedPageId > 0): ?>
                                        <br><a href="<?= esc_url(get_permalink($selectedPageId)); ?>" target="_blank"><?= esc_html(get_the_title($selectedPageId)); ?> ↗</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="eim_dashboard_page_id">Invitee Dashboard Page</label></th>
                            <td>
                                <select id="eim_dashboard_page_id" name="dashboard_page_id">
                                    <option value="0">— No dashboard page selected —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?= esc_attr($page->ID); ?>" <?php selected($selectedDashboard, $page->ID); ?>>
                                            <?= esc_html($page->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    The page guests are redirected to after completing their RSVP. This will serve as the invitee dashboard — showing upcoming events they've registered for, allowing them to update their RSVP, and displaying newsletters relevant to their events.
                                    <?php if ($selectedDashboard > 0): ?>
                                        <br><a href="<?= esc_url(get_permalink($selectedDashboard)); ?>" target="_blank"><?= esc_html(get_the_title($selectedDashboard)); ?> ↗</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button($saveLabel, 'primary', 'submit', false); ?>
                </div>

                <!-- ── Lodging ───────────────────────────────────────────── -->
                <div id="eim-etab-lodging" class="eim-etab-panel">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Lodging Options</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="lodging_enabled" value="1"
                                           <?php checked($isNew ? false : $event->lodgingEnabled); ?>>
                                    Enable lodging options for this event
                                </label>
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
	                                            <button type="button" class="button eim-remove-lodging-row">Remove</button>
	                                        </div>
                                        <p class="eim-lodging-init-display" style="margin:0;color:#3c434a;font-size:13px;display:none;"></p>
                                    </div>
                                </div>
                                <button type="button" id="eim-add-lodging-row" class="button" style="margin-bottom:8px;">+ Add Another Location</button>
                                <p class="description">Optional. Select locations from the library.</p>

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
	                                <?php $lodgingLocations = EventLodging::forEvent($event->id); ?>
	                                <?php if (!empty($lodgingLocations)): ?>
	                                    <?php $canSortLodging = count($lodgingLocations) > 1; ?>
	                                    <?php if ($canSortLodging): ?>
	                                        <p class="description eim-sortable-hint">Drag rows by the handle to set their order. Order numbers update automatically.</p>
	                                        <p class="description eim-sort-status" aria-live="polite"></p>
	                                    <?php endif; ?>
                                        <?php $this->renderBulkActionControls('eim-event-lodging-bulk-form'); ?>
	                                    <table id="eim-event-lodging-table"
                                               class="wp-list-table widefat fixed striped eim-sortable-assignment-list"
                                               data-kind="lodging"
                                               data-sort="order"
                                               data-order="asc"
                                               style="margin-bottom:12px;">
	                                        <thead>
	                                            <tr>
	                                                <?php if ($canSortLodging): ?>
	                                                    <th class="eim-drag-column"><span class="screen-reader-text">Move</span></th>
	                                                <?php endif; ?>
                                                    <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('event-lodging-' . $event->id); ?></th>
	                                                <th class="eim-li-image-column">Image</th>
	                                                <th style="width:8%;"><?= $this->clientSortLink('Order', 'order', 'order', 'asc'); ?></th>
	                                                <th><?= $this->clientSortLink('Name / Address', 'name', 'order', 'asc'); ?></th>
	                                                <th style="width:12%;">Actions</th>
	                                            </tr>
	                                        </thead>
	                                        <tbody>
	                                            <?php foreach ($lodgingLocations as $position => $loc): ?>
	                                                <?php
	                                                $removeUrl = wp_nonce_url(
	                                                    AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'remove_lodging_from_event', 'id' => $loc->id, 'event_id' => $event->id]),
	                                                    'eim_remove_lodging_' . $loc->id
	                                                );
	                                                $displayOrder = $position + 1;
	                                                ?>
	                                                <tr class="eim-sortable-row"
                                                        data-id="<?= esc_attr($loc->id); ?>"
                                                        data-order="<?= esc_attr($displayOrder); ?>"
                                                        data-name="<?= esc_attr(strtolower($loc->name)); ?>"
                                                        data-address="<?= esc_attr(strtolower($loc->formattedAddress())); ?>">
	                                                    <?php if ($canSortLodging): ?>
	                                                        <td class="eim-drag-column">
	                                                            <button type="button" class="button-link eim-drag-handle" aria-label="Drag to reorder <?= esc_attr($loc->name); ?>">
	                                                                <span class="dashicons dashicons-menu" aria-hidden="true"></span>
	                                                            </button>
	                                                        </td>
	                                                    <?php endif; ?>
                                                        <?= $this->renderBulkSelectCell('eim-event-lodging-bulk-form', 'event-lodging-' . $event->id, $loc->id, $loc->name); ?>
	                                                    <td><?= $this->locationImageThumbnailMarkup($loc->imageAttachmentId, $loc->name); ?></td>
	                                                    <td class="eim-order-cell"><?= esc_html($displayOrder); ?></td>
	                                                    <td>
	                                                        <strong><?= esc_html($loc->name); ?></strong>
	                                                        <?php if ($loc->isOther): ?>
                                                            <span style="background:#f0f0f1;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;">Other</span>
                                                        <?php elseif ($loc->formattedAddress()): ?>
                                                            <br><span style="color:#666;font-size:12px;"><?= esc_html($loc->formattedAddress()); ?></span>
                                                        <?php endif; ?>
                                                        <div style="margin-top:6px;">
                                                            <textarea class="eim-lodging-notes"
                                                                      data-lodging-id="<?= esc_attr($loc->id); ?>"
                                                                      placeholder="Event-specific notes…"
                                                                      rows="2"
                                                                      style="width:100%;font-size:12px;resize:vertical;"><?= esc_textarea($loc->notes); ?></textarea>
                                                            <span class="eim-lodging-notes-status" style="font-size:11px;color:#888;"></span>
                                                        </div>
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

	                                    <div>
	                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" name="_wpnonce"          value="<?= esc_attr(wp_create_nonce('eim_add_lodging_to_event')); ?>">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" name="eim_action"        value="add_lodging_to_event">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" name="event_id"          value="<?= esc_attr($event->id); ?>">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" id="eim_lodging_add_library_id" name="lodging_add_library_id" value="">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" id="eim_lodging_add_street"     name="street_address">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" id="eim_lodging_add_city"       name="city">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" id="eim_lodging_add_state"      name="state">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" id="eim_lodging_add_zip"        name="zip_code">
                                    <input form="<?= esc_attr($addLodgingFormId); ?>" type="hidden" id="eim_lodging_add_is_other"   name="is_other" value="">
	                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
	                                        <input form="<?= esc_attr($addLodgingFormId); ?>" type="text" id="eim_lodging_add_name" name="name" class="regular-text"
	                                               placeholder="Search locations library…" autocomplete="off">
	                                        <button form="<?= esc_attr($addLodgingFormId); ?>" type="submit" class="button">Add Location</button>
	                                    </div>
                                    <p id="eim_lodging_add_display" style="margin:4px 0 0;color:#3c434a;font-size:13px;display:none;"></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </table>
                    <?php submit_button($saveLabel, 'primary', 'submit', false); ?>
                </div><!-- /eim-etab-lodging -->

                <!-- ── Food & Beverage ──────────────────────────────────── -->
                <div id="eim-etab-food" class="eim-etab-panel">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Menu Options</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="food_options_enabled" value="1"
                                           <?php checked(!$isNew && $event->foodOptionsEnabled); ?>>
                                    Enable food options for this event
                                </label>
                                <br>
                                <label style="margin-top:6px;display:block;">
                                    <input type="checkbox" name="beverage_options_enabled" value="1"
                                           <?php checked(!$isNew && $event->beverageOptionsEnabled); ?>>
                                    Enable beverage options for this event
                                </label>
                                <p class="description">When enabled, options appear below and are returned by the RSVP API so invitees can choose when registering.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button($saveLabel, 'primary', 'submit', false); ?>
                </div>

            </form>

            <?php if (!$isNew): ?>
                <div id="eim-etab-email-ext" class="eim-etab-ext-panel">
                    <?php $this->renderInviteEmailSendPanel($event); ?>
                </div>
	                <div id="eim-etab-food-ext" class="eim-etab-ext-panel">
	                    <?php $this->renderRsvpOptionsSection($event); ?>
	                </div>
	                <div id="eim-etab-gifts" class="eim-etab-ext-panel">
	                    <?php $this->renderEventGiftsSection($event); ?>
	                </div>
	                <div id="eim-etab-invitees" class="eim-etab-ext-panel">
	                    <?php $this->renderEventInviteesSection($event); ?>
	                </div>
                <div id="eim-etab-messages" class="eim-etab-ext-panel">
                    <?php $this->renderEventMessagesSection($event); ?>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .eim-etab-panel    { display: none; }
        .eim-etab-ext-panel { display: none; }
        .eim-etab-panel.eim-etab-active     { display: block; }
        .eim-etab-ext-panel.eim-etab-active { display: block; }
        </style>
        <script>
        (() => {
            'use strict';
            const nav = document.querySelector('.eim-event-tabs');
            if (!nav) return;

            const eventId    = nav.dataset.eventId || '0';
            const storageKey = 'eim_event_tab_' + eventId;

	            const panelIds = ['details', 'venue', 'email', 'qr', 'lodging', 'food', 'gifts', 'invitees', 'messages'];
            const extMap   = { food: 'food-ext', email: 'email-ext' };

            const getEl = (id) => document.getElementById('eim-etab-' + id);

            const activateTab = (slug) => {
                panelIds.forEach(s => getEl(s)?.classList.remove('eim-etab-active'));
                Object.values(extMap).forEach(s => getEl(s)?.classList.remove('eim-etab-active'));
                nav.querySelectorAll('[data-etab]').forEach(l => l.classList.remove('nav-tab-active'));

                const panel = getEl(slug);
                if (!panel) return;

                panel.classList.add('eim-etab-active');
                if (extMap[slug]) getEl(extMap[slug])?.classList.add('eim-etab-active');
                nav.querySelector('[data-etab="' + slug + '"]')?.classList.add('nav-tab-active');

                try { localStorage.setItem(storageKey, slug); } catch (e) {}

                if (slug === 'email') {
                    window.dispatchEvent(new Event('resize'));
                    if (window.tinyMCE) {
                        setTimeout(() => tinyMCE.get('invite_email_template')?.execCommand('mceAutoResize'), 50);
                    }
                }
            };

            nav.addEventListener('click', (e) => {
                const link = e.target.closest('[data-etab]');
                if (!link) return;
                e.preventDefault();
                if (window.tinyMCE) tinyMCE.triggerSave();
                activateTab(link.dataset.etab);
            });

            document.getElementById('eim-event-form')?.addEventListener('submit', () => {
                if (window.tinyMCE) tinyMCE.triggerSave();
            });

            const hash = window.location.hash.slice(1);
            let initialTab = 'details';
            if (hash.startsWith('eim-etab-')) {
                const slug = hash.slice('eim-etab-'.length);
                if (getEl(slug)) initialTab = slug;
            } else {
                try {
                    const saved = localStorage.getItem(storageKey);
                    if (saved && getEl(saved)) initialTab = saved;
                } catch (e) {}
            }
            activateTab(initialTab);
        })();
        </script>
        <?php if (!$isNew): ?>
        <script>
        (() => {
            'use strict';
            const btn        = document.getElementById('eim-invite-preview-btn');
            const previewCol = document.getElementById('eim-invite-preview-col');
            const frame      = document.getElementById('eim-invite-preview-frame');
            const close      = document.getElementById('eim-invite-preview-close');

            if (!btn || !previewCol || !frame) return;

            const debounce = (fn, ms) => {
                let t;
                return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
            };

            const isOpen = () => previewCol.style.display !== 'none';

            const getContent = () => {
                if (window.tinyMCE) {
                    const ed = tinyMCE.get('invite_email_template');
                    if (ed && !ed.isHidden()) return ed.getContent();
                }
                return document.querySelector('textarea[name="invite_email_template"]')?.value ?? '';
            };

            const refreshFrame = () => {
                frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                    + '<style>body{font-family:sans-serif;font-size:15px;line-height:1.7;'
                    + 'padding:24px 28px;color:#1d1d1d;}'
                    + 'img{max-width:100%;height:auto;}a{color:#0073aa;}'
                    + 'p{margin:0 0 1em;}h1,h2,h3{line-height:1.3;}</style>'
                    + '</head><body>' + getContent() + '</body></html>';
            };

            const liveRefresh = debounce(() => { if (isOpen()) refreshFrame(); }, 1000);

            const hookEditor = (editor) => {
                if (editor.id !== 'invite_email_template') return;
                editor.on('KeyUp Change', liveRefresh);
            };

            if (window.tinyMCE) {
                const existing = tinyMCE.get('invite_email_template');
                if (existing) hookEditor(existing);
                tinyMCE.on('AddEditor', (e) => hookEditor(e.editor));
            }

            document.querySelector('textarea[name="invite_email_template"]')
                    ?.addEventListener('input', liveRefresh);

            btn.addEventListener('click', () => {
                refreshFrame();
                previewCol.style.display = 'block';
                window.dispatchEvent(new Event('resize'));
                if (window.innerWidth <= 1024) {
                    previewCol.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });

            close.addEventListener('click', () => {
                previewCol.style.display = 'none';
                window.dispatchEvent(new Event('resize'));
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Renders the Food &amp; Beverage options section for an existing event.
     *
     * Only renders when food or beverage options are enabled for the event.
     *
     * @param Event $event The event whose options are being configured.
     */
    private function renderRsvpOptionsSection(Event $event): void
    {
        if (!$event->foodOptionsEnabled && !$event->beverageOptionsEnabled) {
            return;
        }

        $allItems  = MenuItem::forEvent($event->id);
        $foodItems = array_values(array_filter($allItems, static fn(MenuItem $i) => $i->type === MenuItem::TYPE_FOOD));
        $bevItems  = array_values(array_filter($allItems, static fn(MenuItem $i) => $i->type === MenuItem::TYPE_BEVERAGE));
        $menuItemsUrl = AdminMenu::tabUrl(AdminMenu::TAB_MENU_ITEMS);
        ?>
        <h2 style="margin-top:20px;">Food &amp; Beverage Options</h2>
        <p class="description">
            Select items from the
            <a href="<?= esc_url($menuItemsUrl); ?>">Food &amp; Beverages library</a>
            to offer at this event. These options are presented to invitees during RSVP.
        </p>

        <div style="display:grid;grid-template-columns:<?= ($event->foodOptionsEnabled && $event->beverageOptionsEnabled) ? '1fr 1fr' : '1fr'; ?>;gap:32px;align-items:start;">
            <?php if ($event->foodOptionsEnabled): ?>
                <div><?php $this->renderEventMenuItemsSubsection($event, MenuItem::TYPE_FOOD, 'Food Options', $foodItems); ?></div>
            <?php endif; ?>
            <?php if ($event->beverageOptionsEnabled): ?>
                <div><?php $this->renderEventMenuItemsSubsection($event, MenuItem::TYPE_BEVERAGE, 'Beverage Options', $bevItems); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderEventGiftsSection(Event $event): void
    {
        $sort    = $this->sanitizeEventGiftSortKey(sanitize_key($_GET['gift_sort'] ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['gift_order'] ?? 'asc'));
        $field   = $this->sanitizeEventGiftFieldKey(sanitize_key($_GET['gift_field'] ?? ''));
        $search  = sanitize_text_field(wp_unslash($_GET['gift_s'] ?? ''));
        $all     = Gift::forEvent($event->id, $search, $sort, $order, $field);
        $total   = count($all);
        $gifts   = array_slice($all, 0, 10);
        $addGiftUrl = AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['action' => 'add']);
        ?>
        <h2 style="margin-top:20px;">Gifts &amp; Registry</h2>
        <p class="description" style="margin-bottom:12px;">
            Registry gifts linked to this event. Guests who have completed RSVP can view these from their dashboard and mark a gift as purchased.
        </p>

        <p style="margin-bottom:12px;">
            <a href="<?= esc_url($addGiftUrl); ?>" class="button">Add Gift to Library</a>
        </p>

        <?php $this->renderSearchBar(
            'eim-event-gifts-search',
            'eim-event-gifts-count',
            'eim-event-gifts-loading',
            'Search event gifts…',
            $total,
            $search,
            [
                ['value' => 'name',        'label' => 'Name'],
                ['value' => 'description', 'label' => 'Description'],
                ['value' => 'website_url', 'label' => 'Website'],
                ['value' => 'purchased',   'label' => 'Purchased'],
            ],
            $field
        ); ?>

        <?php $this->renderBulkActions(
            'eim-event-gifts-bulk-form',
            AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event->id]),
            'bulk_remove_gifts_from_event',
            'eim_bulk_remove_gifts_from_event_' . $event->id,
            ['event_id' => $event->id]
        ); ?>

        <table id="eim-event-gifts-table"
               class="wp-list-table widefat fixed striped"
               style="margin-top:8px;"
               data-event-id="<?= esc_attr($event->id); ?>"
               data-sort="<?= esc_attr($sort); ?>"
               data-order="<?= esc_attr($order); ?>"
               data-total="<?= esc_attr($total); ?>">
            <thead>
                <tr>
                    <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('event-gifts-' . $event->id); ?></th>
                    <th class="eim-gift-image-column">Image</th>
                    <th style="width:22%;"><?= $this->clientSortLink('Name', 'name', $sort, $order); ?></th>
                    <th style="width:9%;"><?= $this->clientSortLink('Price', 'price_cents', $sort, $order); ?></th>
                    <th style="width:16%;">Categories</th>
                    <th style="width:14%;"><?= $this->clientSortLink('Purchased', 'purchased', $sort, $order); ?></th>
                    <th style="width:14%;"><?= $this->clientSortLink('Website', 'website_url', $sort, $order); ?></th>
                    <th style="width:10%;">Actions</th>
                </tr>
            </thead>
            <tbody id="eim-event-gifts-table-body">
                <?php $this->renderEventGiftRows($event, $gifts, $search); ?>
            </tbody>
        </table>
        <?php $this->renderPaginationBar('eim-event-gifts-search'); ?>

        <div style="border:1px solid #dcdcde;border-radius:4px;padding:14px;background:#f6f7f7;margin-top:16px;">
            <h3 style="margin:0 0 8px;font-size:14px;">Add Existing Gift</h3>
            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>">
                <?php wp_nonce_field('eim_add_gift_to_event'); ?>
                <input type="hidden" name="eim_action" value="add_gift_to_event">
                <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">
                <input type="hidden" id="eim_event_gift_id" name="gift_id" value="">

                <div class="eim-invitee-picker-wrap" style="margin-bottom:8px;">
                    <label class="screen-reader-text" for="eim_event_gift_search">Search gifts</label>
                    <input type="text"
                           id="eim_event_gift_search"
                           class="regular-text"
                           placeholder="Search gifts…"
                           autocomplete="off"
                           data-event-id="<?= esc_attr($event->id); ?>">
                    <button type="submit" class="button">Add to Event</button>
                </div>
                <p id="eim_event_gift_selected" class="description"></p>
            </form>
        </div>
        <?php
    }

    /** @param Gift[] $gifts */
    private function renderEventGiftRows(Event $event, array $gifts, string $search = ''): void
    {
        if (empty($gifts)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No gifts linked to this event yet.';
            echo '<tr class="eim-no-results"><td colspan="8">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $giftIds       = array_map(static fn(Gift $gift): int => $gift->id, $gifts);
        $catsByGift    = Category::forEntities('gift', $giftIds);
        $purchaseByGift = Gift::purchaseDetailsForEvent($event->id);

        foreach ($gifts as $gift) {
            $editUrl = AdminMenu::tabUrl(AdminMenu::TAB_GIFTS, ['action' => 'edit', 'id' => $gift->id]);
            $removeUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, [
                    'action'   => 'remove_gift_from_event',
                    'event_id' => $event->id,
                    'gift_id'  => $gift->id,
                ]),
                'eim_remove_gift_' . $event->id . '_' . $gift->id
            );
            $cats = $catsByGift[$gift->id] ?? [];
            ?>
            <tr>
                <?= $this->renderBulkSelectCell('eim-event-gifts-bulk-form', 'event-gifts-' . $event->id, $gift->id, $gift->name); ?>
                <td><?= $this->giftImageThumbnailMarkup($gift->imageAttachmentId, $gift->name); ?></td>
                <td>
                    <strong><a href="<?= esc_url($editUrl); ?>"><?= esc_html($gift->name); ?></a></strong>
                    <?php if ($gift->description): ?>
                        <br><span style="color:#646970;font-size:12px;"><?= esc_html(wp_trim_words($gift->description, 10)); ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $gift->priceCents > 0 ? esc_html($gift->formattedPrice()) : '<span style="color:#999;">—</span>'; ?></td>
                <td>
                    <?php foreach ($cats as $cat): ?>
                        <?php $catEditUrl = AdminMenu::tabUrl(AdminMenu::TAB_CATEGORIES, ['action' => 'edit', 'id' => $cat->id]); ?>
                        <a href="<?= esc_url($catEditUrl); ?>" class="eim-cat-chip"><?= esc_html($cat->parentName ? $cat->parentName . ' › ' . $cat->name : $cat->name); ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($cats)): ?><span style="color:#999;">—</span><?php endif; ?>
                </td>
                <td><?= $this->eventGiftPurchaseMarkup($purchaseByGift[$gift->id] ?? null); ?></td>
                <td>
                    <?php if ($gift->websiteUrl): ?>
                        <a href="<?= esc_url($gift->websiteUrl); ?>" target="_blank" rel="noopener" style="font-size:12px;">Visit</a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                    <a href="<?= esc_url($removeUrl); ?>"
                       onclick="return confirm('Remove &ldquo;<?= esc_js($gift->name); ?>&rdquo; from this event? The gift will remain in the registry library.');">Remove</a>
                </td>
            </tr>
            <?php
        }
    }

    /** @param array<string,mixed>|null $purchase */
    private function eventGiftPurchaseMarkup(?array $purchase): string
    {
        if (empty($purchase['is_purchased'])) {
            return '<span style="color:#d63638;">Not purchased</span>';
        }

        $details = [];
        $purchaser = $this->giftPurchaserLabel($purchase);
        if ($purchaser !== '') {
            $details[] = 'by ' . $purchaser;
        }
        if (!empty($purchase['purchased_at'])) {
            $details[] = $this->formatAdminDateTime((string) $purchase['purchased_at']);
        }

        $html = '<span style="color:#00a32a;">Purchased</span>';
        if (!empty($details)) {
            $html .= '<br><span style="color:#646970;font-size:12px;">' . esc_html(implode(' · ', $details)) . '</span>';
        }

        return $html;
    }

    /** @param array<string,mixed> $purchase */
    private function giftPurchaserLabel(array $purchase): string
    {
        $inviteeId = isset($purchase['purchased_by_invitee_id']) ? (int) $purchase['purchased_by_invitee_id'] : 0;
        if ($inviteeId > 0) {
            $invitee = Invitee::find($inviteeId);
            if ($invitee !== null) {
                return $invitee->fullName() !== '' ? $invitee->fullName() : $invitee->email;
            }
        }

        $groupId = isset($purchase['purchased_by_group_id']) ? (int) $purchase['purchased_by_group_id'] : 0;
        if ($groupId > 0) {
            $group = InvitationGroup::find($groupId);
            if ($group !== null) {
                $primary = Invitee::find($group->primaryInviteeId);
                if ($primary !== null) {
                    return $primary->fullName() !== '' ? $primary->fullName() : $primary->email;
                }
            }
        }

        return '';
    }

    /**
     * @param MenuItem[] $assignedItems Items already linked to this event for the given type.
     */
    private function renderEventMenuItemsSubsection(Event $event, string $type, string $heading, array $assignedItems): void
    {
	        $label    = $type === MenuItem::TYPE_BEVERAGE ? 'beverage' : 'food';
	        $searchId = 'eim-event-' . $type . '-item-search';
	        $countId  = 'eim-event-' . $type . '-item-count';
	        $tbodyId  = 'eim-event-' . $type . '-items-body';
	        $canReorder = count($assignedItems) > 1;
	        $columnCount = $canReorder ? 6 : 5;
	        ?>
	        <h3><?= esc_html($heading); ?></h3>

	        <div>
            <?php $this->renderSearchBar(
                $searchId,
                $countId,
                'eim-event-' . $type . '-item-loading',
                'Search ' . $label . ' items…',
                count($assignedItems),
                '',
                [
                    ['value' => 'label',       'label' => 'Label'],
                    ['value' => 'description', 'label' => 'Description'],
	                ]
	            ); ?>

	            <?php if ($canReorder): ?>
	                <p class="description eim-sortable-hint">Drag rows by the handle to set their order. Order numbers update automatically.</p>
	                <p class="description eim-sort-status" aria-live="polite"></p>
	            <?php endif; ?>
                <?php $this->renderBulkActions(
                    'eim-event-' . $type . '-items-bulk-form',
                    AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event->id]),
                    'bulk_remove_menu_items_from_event',
                    'eim_bulk_remove_menu_items_from_event_' . $event->id . '_' . $type,
                    ['event_id' => $event->id, 'type' => $type]
                ); ?>

	            <table class="wp-list-table widefat fixed striped eim-sortable-assignment-list"
                       data-kind="menu"
                       data-type="<?= esc_attr($type); ?>"
                       data-sort="order"
                       data-order="asc"
                       style="margin-bottom:12px;">
	                <thead>
	                    <tr>
	                        <?php if ($canReorder): ?>
	                            <th class="eim-drag-column"><span class="screen-reader-text">Move</span></th>
	                        <?php endif; ?>
                            <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('event-' . $type . '-items-' . $event->id); ?></th>
	                        <th style="width:10%;"><?= $this->clientSortLink('Order', 'order', 'order', 'asc'); ?></th>
	                        <th><?= $this->clientSortLink('Label', 'label', 'order', 'asc'); ?></th>
	                        <th style="width:40%;"><?= $this->clientSortLink('Description', 'description', 'order', 'asc'); ?></th>
	                        <th style="width:10%;">Actions</th>
	                    </tr>
	                </thead>
	                <tbody id="<?= esc_attr($tbodyId); ?>">
	                    <?php if (empty($assignedItems)): ?>
	                        <tr class="eim-no-results"><td colspan="<?= esc_attr($columnCount); ?>">No <?= esc_html($label); ?> items added yet.</td></tr>
	                    <?php else: ?>
	                        <?php foreach ($assignedItems as $position => $item): ?>
	                            <?php
	                            $removeUrl = wp_nonce_url(
	                                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'remove_menu_item_from_event', 'event_id' => $event->id, 'menu_item_id' => $item->id]),
	                                'eim_remove_menu_item_' . $event->id . '_' . $item->id
	                            );
	                            $displayOrder = $position + 1;
	                            ?>
	                            <tr class="eim-sortable-row"
                                    data-id="<?= esc_attr($item->id); ?>"
                                    data-order="<?= esc_attr($displayOrder); ?>"
                                    data-label="<?= esc_attr(strtolower($item->label)); ?>"
	                                data-description="<?= esc_attr(strtolower($item->description)); ?>">
	                                <?php if ($canReorder): ?>
	                                    <td class="eim-drag-column">
	                                        <button type="button" class="button-link eim-drag-handle" aria-label="Drag to reorder <?= esc_attr($item->label); ?>">
	                                            <span class="dashicons dashicons-menu" aria-hidden="true"></span>
	                                        </button>
	                                    </td>
	                                <?php endif; ?>
                                    <?= $this->renderBulkSelectCell('eim-event-' . $type . '-items-bulk-form', 'event-' . $type . '-items-' . $event->id, $item->id, $item->label); ?>
	                                <td class="eim-order-cell"><?= esc_html($displayOrder); ?></td>
	                                <td><strong><?= esc_html($item->label); ?></strong></td>
	                                <td><?= esc_html($item->description ?: '—'); ?></td>
	                                <td>
	                                    <a href="<?= esc_url($removeUrl); ?>"
                                       onclick="return confirm('Remove &ldquo;<?= esc_js($item->label); ?>&rdquo; from this event?');">Remove</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="border:1px solid #dcdcde;border-radius:4px;padding:14px;background:#f6f7f7;">
            <h4 style="margin:0 0 8px;">Add <?= esc_html(ucfirst($label)); ?> Item</h4>
            <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>">
                <?php wp_nonce_field('eim_add_menu_item_to_event'); ?>
                <input type="hidden" name="eim_action" value="add_menu_item_to_event">
                <input type="hidden" name="event_id"   value="<?= esc_attr($event->id); ?>">
                <input type="hidden" id="eim_<?= esc_attr($type); ?>_item_id"
                       name="menu_item_id" value="">

                <div class="eim-invitee-picker-wrap" style="margin-bottom:8px;">
                    <label class="screen-reader-text" for="eim_<?= esc_attr($type); ?>_item_search">
                        Search <?= esc_html($label); ?> items
                    </label>
                    <input type="text"
                           id="eim_<?= esc_attr($type); ?>_item_search"
                           class="regular-text eim-menu-item-search"
                           placeholder="Search <?= esc_html($label); ?> items…"
                           autocomplete="off"
                           data-type="<?= esc_attr($type); ?>">
                    <button type="submit" class="button">Add to Event</button>
                </div>
                <p id="eim_<?= esc_attr($type); ?>_item_selected" class="description"></p>
            </form>
	        </div>
	        <?php
	    }

    /**
     * Generates a client-side sort link for assignment list columns (lodging, menu items).
     *
     * @param string $label        Visible column header text.
     * @param string $key          Column sort key.
     * @param string $currentSort  Currently active sort column.
     * @param string $currentOrder Currently active sort direction ('asc' or 'desc').
     * @return string HTML anchor element with data-sort and data-order attributes.
     */
    private function clientSortLink(string $label, string $key, string $currentSort, string $currentOrder): string
    {
        $isCurrent = $currentSort === $key;
        $nextOrder = $isCurrent && $currentOrder === 'asc' ? 'desc' : 'asc';
        $indicator = $isCurrent ? ($currentOrder === 'asc' ? '^' : 'v') : '';

        return sprintf(
            '<a href="#" class="eim-sort-link" data-sort="%s" data-order="%s">%s <span aria-hidden="true">%s</span></a>',
            esc_attr($key),
            esc_attr($nextOrder),
            esc_html($label),
            esc_html($indicator)
        );
    }

    /**
     * Sanitizes an invitation group sort key against the allowed column list.
     *
     * @param string $key Raw sort key.
     * @return string Validated key, defaulting to 'name'.
     */
    private function sanitizeEventGroupSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['name', 'email', 'members', 'invite_sent', 'attending', 'rsvp_notes'], true) ? $key : 'name';
    }

    private function sanitizeEventGiftSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['name', 'price_cents', 'website_url', 'purchased'], true) ? $key : 'name';
    }

    /**
     * Sanitizes an invitation group search field key against the allowed column list.
     *
     * @param string $field Raw field key.
     * @return string Validated key, or '' for any-column search.
     */
    private function sanitizeEventGroupFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['name', 'email', 'invite_sent', 'attending', 'rsvp_notes'], true) ? $field : '';
    }

    private function sanitizeEventGiftFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['name', 'description', 'website_url', 'purchased'], true) ? $field : '';
    }

    /**
     * Returns a display label for a stored food or beverage selection.
     *
     * @param int|null              $id
     * @param array<int, MenuItem>  $optionMap
     * @return string
     */
    private function menuSelectionLabel(?int $id, array $optionMap): string
    {
        if ($id === null || $id <= 0) {
            return '';
        }

        if (isset($optionMap[$id])) {
            return $optionMap[$id]->label;
        }

        $item = MenuItem::find($id);

        if ($item === null) {
            return 'Unavailable option';
        }

        return $item->label . ' (not assigned to this event)';
    }

    /**
     * Formats a stored RSVP status for admin display.
     *
     * @param string $status
     * @return string
     */
    private function rsvpStatusLabel(string $status): string
    {
        return match ($status) {
            InvitationGroup::RSVP_ATTENDING => 'Attending',
            InvitationGroup::RSVP_DECLINED  => 'Declined',
            default                         => 'Pending',
        };
    }

    /**
     * Formats a MySQL datetime in the site's admin date/time format.
     *
     * @param string|null $datetime
     * @return string
     */
    private function formatAdminDateTime(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }

        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return '';
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Encodes a Details modal payload for use in a data attribute.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    private function rsvpDetailsAttribute(array $payload): string
    {
        return esc_attr((string) wp_json_encode($payload));
    }

    /**
     * Returns a human-readable label for an invitee's lodging selection.
     *
     * @param Invitee          $member
     * @param array<int,string> $lodgingById  Map of EventLodging ID → name.
     * @return string  Empty string when no lodging has been confirmed.
     */
    private function lodgingSelectionLabel(Invitee $member, array $lodgingById): string
    {
        if ($member->lodgingUndisclosed) {
            return 'Prefer not to disclose';
        }
        if ($member->lodgingIsOther) {
            return 'Other (Booked Separately)';
        }
        if ($member->lodgingId !== null && isset($lodgingById[$member->lodgingId])) {
            return $lodgingById[$member->lodgingId];
        }
        return '';
    }

    /**
     * Builds the Details modal payload for an invitee on an event invitation row.
     *
     * @param Invitee     $member
     * @param string|null $foodLabel
     * @param string|null $beverageLabel
     * @param string      $lodgingLabel         Group-level lodging selection label.
     * @param string|null $lodgingConfirmedAt   Group-level lodging confirmed datetime.
     * @param string      $lodgingNotes         Optional notes left by the primary invitee.
     * @return array<string,mixed>
     */
    private function memberDetailsPayload(
        Invitee $member,
        ?string $foodLabel,
        ?string $beverageLabel,
        string $lodgingLabel = '',
        ?string $lodgingConfirmedAt = null,
        string $lodgingNotes = '',
    ): array {
        $fullName = $member->fullName();

        $sections = [
            [
                'heading' => 'Invitee Information',
                'rows'    => [
                    ['label' => 'Name',    'value' => $fullName],
                    ['label' => 'Email',   'value' => $member->email],
                    ['label' => 'Phone',   'value' => $member->phone],
                    ['label' => 'Address', 'value' => $member->formattedAddress()],
                ],
            ],
            [
                'heading' => 'RSVP Response',
                'rows'    => [
                    ['label' => 'Status',        'value' => $this->rsvpStatusLabel($member->rsvpStatus)],
                    ['label' => 'Registered',    'value' => $this->formatAdminDateTime($member->registeredAt)],
                    ['label' => 'Food',          'value' => $foodLabel ?: ''],
                    ['label' => 'Beverage',      'value' => $beverageLabel ?: ''],
                    ['label' => 'Dietary Notes', 'value' => $member->dietaryNotes],
                ],
            ],
        ];

        if ($lodgingLabel !== '' || $lodgingConfirmedAt !== null) {
            $lodgingRows = [
                ['label' => 'Selection', 'value' => $lodgingLabel],
                ['label' => 'Confirmed', 'value' => $this->formatAdminDateTime($lodgingConfirmedAt)],
            ];
            if ($lodgingNotes !== '') {
                $lodgingRows[] = ['label' => 'Notes', 'value' => $lodgingNotes];
            }
            $sections[] = ['heading' => 'Lodging', 'rows' => $lodgingRows];
        }

        return [
            'title'    => $fullName !== '' ? $fullName : $member->email,
            'sections' => $sections,
        ];
    }

    /**
     * Filters invitation groups by a search string, optionally restricted to one column.
     *
     * Matching is done against the values as rendered in the table so that users can type
     * exactly what they see (e.g. "Not sent", a date, "pending").
     *
     * @param InvitationGroup[] $groups
     * @param string            $search
     * @param string            $field      Column key, or '' for Any.
     * @param string            $dateFormat WordPress date format for invite-sent display.
     * @return InvitationGroup[]
     */
    private function filterEventGroups(array $groups, string $search, string $field, string $dateFormat = ''): array
    {
        if ($search === '') {
            return $groups;
        }

        $needle = strtolower($search);

        return array_values(array_filter(
            $groups,
            function (InvitationGroup $group) use ($needle, $field, $dateFormat): bool {
                $members = $group->getMembers();

                switch ($field) {
                    case 'name':
                        foreach ($members as $m) {
                            if (str_contains(strtolower($m->firstName . ' ' . $m->lastName), $needle)) {
                                return true;
                            }
                        }
                        return false;

                    case 'email':
                        foreach ($members as $m) {
                            if (str_contains(strtolower($m->email), $needle)) {
                                return true;
                            }
                        }
                        return false;

                    case 'invite_sent':
                        $label = $group->inviteSentAt
                            ? strtolower(date_i18n($dateFormat, strtotime($group->inviteSentAt)))
                            : 'not sent';
                        return str_contains($label, $needle);

                    case 'attending':
                        $attendingCount = $group->attendingCount();
                        $memberCount    = $group->memberCount();
                        $pending        = count(array_filter(
                            $members,
                            static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_PENDING
                        ));
                        $declined       = count(array_filter(
                            $members,
                            static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_DECLINED
                        ));
                        if ($memberCount === 0) {
                            $label = '—';
                        } elseif ($attendingCount === $memberCount) {
                            $label = 'all attending' . ($memberCount > 1 ? ' ' . $memberCount : '');
                        } else {
                            $parts = [$attendingCount . ' attending'];
                            if ($declined > 0) $parts[] = $declined . ' declined';
                            if ($pending  > 0) $parts[] = $pending  . ' pending';
                            $label = strtolower(implode(', ', $parts));
                        }
                        return str_contains($label, $needle);

                    case 'rsvp_notes':
                        return str_contains(strtolower($group->rsvpNotes), $needle);

                    default:
                        // Any: search member names, emails, and group RSVP notes.
                        if (str_contains(strtolower($group->rsvpNotes), $needle)) {
                            return true;
                        }
                        foreach ($members as $m) {
                            if (str_contains(strtolower($m->firstName . ' ' . $m->lastName), $needle)
                                || str_contains(strtolower($m->email), $needle)) {
                                return true;
                            }
                        }
                        return false;
                }
            }
        ));
    }

    /**
     * Sorts invitation groups by the specified column using PHP usort.
     *
     * @param InvitationGroup[] $groups
     * @param string            $sort  Column key.
     * @param string            $order 'asc' or 'desc'.
     * @return InvitationGroup[]
     */
    private function sortEventGroups(array $groups, string $sort, string $order): array
    {
        $primaryInviteeMap = [];
        if (in_array($sort, ['name', 'email'], true) && !empty($groups)) {
            foreach (array_unique(array_map(fn($g) => $g->primaryInviteeId, $groups)) as $pid) {
                $inv = Invitee::find($pid);
                if ($inv) {
                    $primaryInviteeMap[$pid] = $inv;
                }
            }
        }

        $mul = $order === 'desc' ? -1 : 1;
        usort($groups, function (InvitationGroup $a, InvitationGroup $b) use ($sort, $mul, $primaryInviteeMap): int {
            if ($sort === 'name') {
                $aInv = $primaryInviteeMap[$a->primaryInviteeId] ?? null;
                $bInv = $primaryInviteeMap[$b->primaryInviteeId] ?? null;
                return $mul * strcasecmp(
                    ($aInv ? $aInv->lastName . ' ' . $aInv->firstName : ''),
                    ($bInv ? $bInv->lastName . ' ' . $bInv->firstName : '')
                );
            }
            if ($sort === 'email') {
                $aInv = $primaryInviteeMap[$a->primaryInviteeId] ?? null;
                $bInv = $primaryInviteeMap[$b->primaryInviteeId] ?? null;
                return $mul * strcasecmp($aInv?->email ?? '', $bInv?->email ?? '');
            }
            if ($sort === 'members') {
                return $mul * ($a->memberCount() <=> $b->memberCount());
            }
            if ($sort === 'invite_sent') {
                if ($a->inviteSentAt === null && $b->inviteSentAt === null) return 0;
                if ($a->inviteSentAt === null) return 1;
                if ($b->inviteSentAt === null) return -1;
                return $mul * strcmp($a->inviteSentAt, $b->inviteSentAt);
            }
            if ($sort === 'attending') {
                return $mul * ($a->attendingCount() <=> $b->attendingCount());
            }
            if ($sort === 'rsvp_notes') {
                return $mul * strcasecmp($a->rsvpNotes, $b->rsvpNotes);
            }
            return 0;
        });

        return $groups;
    }

    /**
     * AJAX: returns filtered and sorted invitation group rows for the event invitees table.
     *
     * Expected GET params: nonce, event_id, sort, order, query, field.
     * Returns JSON: { success: true, data: { html, count } }
     */
    public function handleAjaxSortGroups(): void
    {
        check_ajax_referer('eim_event_groups_sort_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_GET['event_id'] ?? 0);
        $sort    = $this->sanitizeEventGroupSortKey((string) ($_GET['sort']    ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order']   ?? 'asc'));
        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $field   = $this->sanitizeEventGroupFieldKey((string) ($_GET['field']   ?? ''));
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 10), [5, 10, 25, 50, 100], true) ? (int) $_GET['per_page'] : 10;

        $event = $eventId > 0 ? Event::find($eventId) : null;
        if (!$event) {
            wp_send_json_error('Event not found.', 404);
        }

        $dateFormat  = get_option('date_format');
        $groups      = $this->sortEventGroups(
            $this->filterEventGroups(InvitationGroup::forEvent($eventId), $query, $field, $dateFormat),
            $sort,
            $order
        );
        $total       = count($groups);
        $pagedGroups = array_slice($groups, ($page - 1) * $perPage, $perPage);

        ob_start();
        $this->renderEventGroupRows($event, $pagedGroups, $dateFormat, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => $total, 'total' => $total]);
    }

    /**
     * AJAX: saves a seat assignment for a group member.
     *
     * Expected POST params: nonce, group_id, invitee_id, seat_assignment.
     */
    public function handleAjaxSaveSeating(): void
    {
        check_ajax_referer('eim_save_seat_assignment_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $groupId   = (int) ($_POST['group_id']        ?? 0);
        $inviteeId = (int) ($_POST['invitee_id']       ?? 0);
        $seat      = sanitize_text_field(wp_unslash($_POST['seat_assignment'] ?? ''));

        if (!$groupId || !$inviteeId) {
            wp_send_json_error('Missing required parameters.');
        }

        $success = InvitationGroup::updateMemberSeatAssignment($groupId, $inviteeId, $seat);

        if (!$success) {
            wp_send_json_error('Could not save seat assignment.');
        }

        wp_send_json_success(['seat_assignment' => $seat]);
    }

    /**
     * Renders invitation group table rows for both the initial page and AJAX responses.
     *
     * @param Event             $event      The event whose groups are being rendered.
     * @param InvitationGroup[] $groups     Groups to render.
     * @param string            $dateFormat WordPress date format for the invite-sent column.
     * @param string            $search     Active search query (for the empty-state message).
     */
    private function renderEventGroupRows(Event $event, array $groups, string $dateFormat, string $search = ''): void
    {
        if (empty($groups)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No invitees have been added to this event yet.';
            echo '<tr><td colspan="10">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $rsvpOptionMap = [];
        if ($event->foodOptionsEnabled || $event->beverageOptionsEnabled) {
            foreach (MenuItem::forEvent($event->id) as $item) {
                $rsvpOptionMap[$item->id] = $item;
            }
        }

        // Build lodging name map once for all groups on this event.
        $lodgingById = [];
        foreach (EventLodging::forEvent($event->id) as $opt) {
            $lodgingById[$opt->id] = $opt->name;
        }

        // Pre-fetch QR codes for all groups in one query.
        $groupIds   = array_map(static fn(InvitationGroup $g): int => $g->id, $groups);
        $qrByGroup  = QrCode::mapByGroupIds($groupIds);

        foreach ($groups as $group) {
            $members              = $group->getMembers();
            $primaryInvitee       = Invitee::find($group->primaryInviteeId);

            // Identify the primary group member for group-level lodging display.
            $primaryMember = null;
            foreach ($members as $m) {
                if ($m->id === $group->primaryInviteeId) {
                    $primaryMember = $m;
                    break;
                }
            }
            $groupLodgingLabel       = $primaryMember ? $this->lodgingSelectionLabel($primaryMember, $lodgingById) : '';
            $groupLodgingConfirmedAt = $primaryMember?->lodgingConfirmedAt;
            $attendingCount       = $group->attendingCount();
            $memberCount          = $group->memberCount();
            $pending              = count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_PENDING));
            $declined             = count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_DECLINED));
            $sendUrl              = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'send_event_invite', 'event_id' => $event->id, 'group_id' => $group->id]),
                'eim_send_event_invite_' . $event->id . '_' . $group->id
            );
            $removeGroupUrl       = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'remove_group_from_event', 'event_id' => $event->id, 'group_id' => $group->id]),
                'eim_remove_group_' . $event->id . '_' . $group->id
            );
            $allConnections       = ConnectionGroup::connectedInviteesForEvent($group->primaryInviteeId, $event->id);
            $uninvitedConnections = array_values(array_filter($allConnections, static fn(array $c) => !$c['already_invited']));
            ?>
            <tr>
                <?= $this->renderBulkSelectCell('eim-event-groups-bulk-form', 'event-groups-' . $event->id, $group->id, 'invitation group ' . (string) $group->id); ?>
                <td style="width:30px;vertical-align:middle;text-align:center;">
                    <button type="button"
                            class="button-link eim-seat-accordion-toggle"
                            data-group-id="<?= esc_attr($group->id); ?>"
                            aria-expanded="false"
                            title="Show/hide seating assignments">▶</button>
                </td>
                <td>
                    <span class="eim-tag-list">
                        <?php foreach ($members as $member): ?>
                            <?php
                            $removeUrl      = wp_nonce_url(
                                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'remove_invitee_from_event', 'event_id' => $event->id, 'invitee_id' => $member->id]),
                                'eim_remove_invitee_' . $event->id . '_' . $member->id
                            );
                            $editInvUrl     = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $member->id]);
                            $makePrimaryUrl = wp_nonce_url(
                                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'set_group_primary', 'event_id' => $event->id, 'group_id' => $group->id, 'invitee_id' => $member->id]),
                                'eim_set_primary_' . $event->id . '_' . $group->id . '_' . $member->id
                            );
                            $isPrimary      = $member->id === $group->primaryInviteeId;
                            ?>
                            <?php
                            $foodLabel      = $this->menuSelectionLabel($member->foodOptionId, $rsvpOptionMap);
                            $bevLabel       = $this->menuSelectionLabel($member->beverageOptionId, $rsvpOptionMap);
                            $detailsPayload = $this->memberDetailsPayload($member, $foodLabel ?: null, $bevLabel ?: null, $groupLodgingLabel, $groupLodgingConfirmedAt, $group->lodgingNotes);
                            ?>
                            <span class="eim-group-member-tag<?= $isPrimary ? ' eim-group-member-primary' : ''; ?>">
                                <span class="eim-member-dropdown">
                                    <button type="button"
                                            class="eim-member-dropdown-trigger"
                                            aria-haspopup="true"
                                            aria-expanded="false"><?= esc_html($member->fullName()); ?><?= $isPrimary ? ' <span class="eim-event-tag-role" title="Primary recipient">✉</span>' : ''; ?></button>
                                    <div class="eim-member-dropdown-menu" role="menu" hidden>
                                        <a href="<?= esc_url($editInvUrl); ?>" role="menuitem">Edit Invitee</a>
                                        <button type="button"
                                                class="eim-rsvp-details-trigger"
                                                role="menuitem"
                                                data-eim-rsvp-details="<?= $this->rsvpDetailsAttribute($detailsPayload); ?>">Details</button>
                                        <?php if (!$isPrimary): ?>
                                        <a href="<?= esc_url($makePrimaryUrl); ?>"
                                           role="menuitem"
                                           onclick="return confirm('Make <?= esc_js($member->fullName()); ?> the primary recipient for this group?');">Make Primary</a>
                                        <?php endif; ?>
                                    </div>
                                </span>
                                <?php if ($foodLabel || $bevLabel || $member->dietaryNotes): ?>
                                <span style="display:block;font-size:10px;color:#666;line-height:1.5;padding:1px 2px 2px;">
                                    <?php if ($foodLabel): ?><span>Food: <?= esc_html($foodLabel); ?></span><?php endif; ?>
                                    <?php if ($foodLabel && $bevLabel): ?> &middot; <?php endif; ?>
                                    <?php if ($bevLabel): ?><span>Drink: <?= esc_html($bevLabel); ?></span><?php endif; ?>
                                    <?php if ($member->dietaryNotes): ?><span style="display:block;font-style:italic;"><?= esc_html($member->dietaryNotes); ?></span><?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <a href="<?= esc_url($removeUrl); ?>"
                                   class="eim-member-remove-link"
                                   onclick="return confirm('Remove <?= esc_js($member->fullName()); ?> from this event? Their profile will remain.');"
                                   title="Remove">×</a>
                            </span>
                        <?php endforeach; ?>
                    </span>
                </td>
                <td>
                    <?php if ($primaryInvitee): ?>
                        <a href="mailto:<?= esc_attr($primaryInvitee->email); ?>"><?= esc_html($primaryInvitee->email); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($group->inviteSentAt): ?>
                        <?= esc_html(date_i18n($dateFormat, strtotime($group->inviteSentAt))); ?>
                    <?php else: ?>
                        <span style="color:#999;">Not sent</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($memberCount === 0): ?>
                        <span style="color:#999;">—</span>
                    <?php elseif ($attendingCount === $memberCount): ?>
                        <span style="color:#00a32a;font-weight:600;">
                            All attending<?= $memberCount > 1 ? ' (' . $memberCount . ')' : ''; ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#3c434a;">
                            <?= esc_html($attendingCount); ?> attending
                            <?php if ($declined > 0): ?>, <?= esc_html($declined); ?> declined<?php endif; ?>
                            <?php if ($pending > 0): ?>, <?= esc_html($pending); ?> pending<?php endif; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $groupQrCode = $qrByGroup[$group->id] ?? null; ?>
                    <?php if ($groupQrCode): ?>
                        <code style="font-size:11px;word-break:break-all;"><?= esc_html($groupQrCode->confirmationCode); ?></code>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($groupQrCode): ?>
                        <a href="<?= esc_url($groupQrCode->svgUrl()); ?>" download style="font-size:12px;">SVG</a>
                        <span class="eim-action-sep">|</span>
                        <a href="<?= esc_url($groupQrCode->pngUrl()); ?>" download style="font-size:12px;">PNG</a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td class="eim-rsvp-notes-cell">
                    <?php if (trim($group->rsvpNotes) !== ''): ?>
                        <div class="eim-rsvp-notes-preview"><?= esc_html($group->rsvpNotes); ?></div>
                        <?php if ($group->rsvpNotesUpdatedAt): ?>
                            <span class="description">Updated <?= esc_html($this->formatAdminDateTime($group->rsvpNotesUpdatedAt)); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td class="eim-group-actions">
                    <a href="<?= esc_url($sendUrl); ?>">Send Invite</a>
                    <span class="eim-action-sep">|</span>
                    <button type="button"
                            class="button-link eim-add-member-toggle"
                            data-group-id="<?= esc_attr($group->id); ?>">Add Any Invitee</button>
                    <?php if (!empty($uninvitedConnections)): ?>
                    <span class="eim-action-sep">|</span>
                    <button type="button"
                            class="button-link eim-add-connection-toggle"
                            data-group-id="<?= esc_attr($group->id); ?>">Add Connection Invitee</button>
                    <?php endif; ?>
                    <span class="eim-action-sep">|</span>
                    <a href="<?= esc_url($removeGroupUrl); ?>"
                       class="eim-remove-group-link"
                       onclick="return confirm('Remove this entire group from the event? All group members will be removed.');">Remove Group</a>
                </td>
            </tr>
            <?php $accordionGroupLabel = $primaryInvitee ? $primaryInvitee->fullName() : 'Group ' . $group->id; ?>
            <tr class="eim-seat-accordion-row" id="eim-seat-accordion-row-<?= esc_attr($group->id); ?>" style="display:none;" data-group-id="<?= esc_attr($group->id); ?>" data-group-label="<?= esc_attr($accordionGroupLabel); ?>">
                <td colspan="9" style="background:#f6f7f7;padding:10px 16px;">
                    <table class="wp-list-table widefat fixed striped eim-accordion-sortable">
                        <thead>
                            <tr>
                                <th class="eim-invitee-image-column">Image</th>
                                <th data-sort="1" style="width:15%;cursor:pointer;">First Name <span class="eim-sort-indicator"></span></th>
                                <th data-sort="2" style="width:15%;cursor:pointer;">Last Name <span class="eim-sort-indicator"></span></th>
                                <th data-sort="3" style="width:24%;cursor:pointer;">Email <span class="eim-sort-indicator"></span></th>
                                <th data-sort="4" style="cursor:pointer;">Seat Assignment <span class="eim-sort-indicator"></span></th>
                                <th style="width:80px;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member):
                                $accFoodLabel = $this->menuSelectionLabel($member->foodOptionId, $rsvpOptionMap);
                                $accBevLabel  = $this->menuSelectionLabel($member->beverageOptionId, $rsvpOptionMap);
                                $accDetails   = $this->memberDetailsPayload($member, $accFoodLabel ?: null, $accBevLabel ?: null, $groupLodgingLabel, $groupLodgingConfirmedAt, $group->lodgingNotes);
                            ?>
                            <tr data-invitee-id="<?= esc_attr($member->id); ?>"
                                data-group-id="<?= esc_attr($group->id); ?>">
                                <td><?= $this->inviteeImageThumbnailMarkup($member->imageAttachmentId, $member->fullName()); ?></td>
                                <td data-val="<?= esc_attr(strtolower($member->firstName)); ?>">
                                    <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $member->id])); ?>"><?= esc_html($member->firstName); ?></a>
                                </td>
                                <td data-val="<?= esc_attr(strtolower($member->lastName)); ?>">
                                    <a href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $member->id])); ?>"><?= esc_html($member->lastName); ?></a>
                                </td>
                                <td data-val="<?= esc_attr(strtolower($member->email)); ?>"><?= esc_html($member->email); ?></td>
                                <td data-val="<?= esc_attr(strtolower($member->seatAssignment)); ?>">
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="text"
                                               class="regular-text eim-seat-input"
                                               value="<?= esc_attr($member->seatAssignment); ?>"
                                               placeholder="e.g. Table 5, Seat 2"
                                               data-invitee-id="<?= esc_attr($member->id); ?>"
                                               data-group-id="<?= esc_attr($group->id); ?>"
                                               data-original="<?= esc_attr($member->seatAssignment); ?>"
                                               style="max-width:200px;">
                                        <button type="button"
                                                class="button button-small eim-save-seat"
                                                data-invitee-id="<?= esc_attr($member->id); ?>"
                                                data-group-id="<?= esc_attr($group->id); ?>">Save</button>
                                        <span class="eim-seat-save-status" style="font-size:12px;"></span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button"
                                            class="button button-small eim-rsvp-details-trigger"
                                            data-eim-rsvp-details="<?= $this->rsvpDetailsAttribute($accDetails); ?>">Details</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </td>
            </tr>
            <tr class="eim-add-member-row" id="eim-add-member-row-<?= esc_attr($group->id); ?>" style="display:none;">
                <td colspan="9" class="eim-add-member-cell">
                    <form method="post"
                          action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'add_member_to_group'])); ?>"
                          class="eim-add-member-form">
                        <?php wp_nonce_field('eim_add_member_to_group_' . $group->id); ?>
                        <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">
                        <input type="hidden" name="group_id" value="<?= esc_attr($group->id); ?>">
                        <input type="hidden"
                               name="invitee_id"
                               class="eim-add-member-invitee-id"
                               id="eim-add-member-invitee-id-<?= esc_attr($group->id); ?>"
                               value="">
                        <div class="eim-invitee-picker-positioner">
                            <input type="text"
                                   class="regular-text eim-group-member-search"
                                   id="eim-add-member-search-<?= esc_attr($group->id); ?>"
                                   placeholder="Search for an invitee…"
                                   data-event-id="<?= esc_attr($event->id); ?>"
                                   data-group-id="<?= esc_attr($group->id); ?>"
                                   autocomplete="off">
                        </div>
                        <button type="submit" class="button button-primary">Add to Group</button>
                        <button type="button"
                                class="button eim-add-member-cancel"
                                data-group-id="<?= esc_attr($group->id); ?>">Cancel</button>
                    </form>
                </td>
            </tr>
            <?php if (!empty($uninvitedConnections)): ?>
            <tr class="eim-add-connection-row" id="eim-add-connection-row-<?= esc_attr($group->id); ?>" style="display:none;">
                <td colspan="9" class="eim-add-member-cell">
                    <form method="post"
                          action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'add_member_to_group'])); ?>"
                          class="eim-add-member-form">
                        <?php wp_nonce_field('eim_add_member_to_group_' . $group->id); ?>
                        <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">
                        <input type="hidden" name="group_id" value="<?= esc_attr($group->id); ?>">
                        <select name="invitee_id" class="eim-connection-select">
                            <option value="">Select a connection…</option>
                            <?php foreach ($uninvitedConnections as $conn): ?>
                            <option value="<?= esc_attr($conn['id']); ?>">
                                <?= esc_html($conn['name']); ?><?= $conn['group_name'] ? ' (' . esc_html($conn['group_name']) . ')' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary">Add to Group</button>
                        <button type="button"
                                class="button eim-add-connection-cancel"
                                data-group-id="<?= esc_attr($group->id); ?>">Cancel</button>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
            <?php
        }
    }

    /**
     * Renders the send panel for the Invite Email tab (test send + send all).
     *
     * @param Event $event The event being edited.
     */
    private function renderInviteEmailSendPanel(Event $event): void
    {
        $groups       = InvitationGroup::forEvent($event->id);
        $totalGroups  = count($groups);
        $unsentGroups = count(array_filter($groups, static fn(InvitationGroup $g) => $g->inviteSentAt === null));
        $hasTemplate  = !empty($event->inviteEmailTemplate);
        ?>
        <div style="max-width:900px;margin-top:32px;border-top:2px solid #dcdcde;padding-top:24px;">
            <h2 class="title">Send Invite Emails</h2>

            <?php if (!$hasTemplate): ?>
                <p class="description">No email template has been saved yet. Fill in the Email Body above and save the event before sending.</p>
            <?php elseif ($totalGroups === 0): ?>
                <p class="description">No invitation groups yet. Add invitees to this event first.</p>
            <?php else: ?>
                <p class="description" style="margin-bottom:16px;">
                    This event has <strong><?= esc_html($totalGroups); ?></strong> invitation group<?= $totalGroups === 1 ? '' : 's'; ?>
                    &mdash; <strong><?= esc_html($unsentGroups); ?></strong> not yet sent.
                </p>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
                    <button type="button"
                            id="eim-invite-send-all"
                            class="button button-primary"
                            data-event-id="<?= esc_attr($event->id); ?>"
                            <?= $unsentGroups === 0 ? 'disabled' : ''; ?>>
                        Send to All Unsent (<?= esc_html($unsentGroups); ?>)
                    </button>
                    <span id="eim-invite-send-all-result" style="display:none;font-size:13px;"></span>
                </div>
            <?php endif; ?>

            <h3 style="margin:0 0 6px;font-size:14px;">Send Test Email</h3>
            <p class="description" style="margin-bottom:8px;">
                Send the email template to a single address for review. Template tags like
                <code>{{ first_name }}</code> will be replaced with placeholder values.
            </p>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="email"
                       id="eim-invite-test-email"
                       class="regular-text"
                       placeholder="test@example.com"
                       style="max-width:260px;"
                       <?= !$hasTemplate ? 'disabled' : ''; ?>>
                <button type="button"
                        id="eim-invite-send-test"
                        class="button"
                        data-event-id="<?= esc_attr($event->id); ?>"
                        <?= !$hasTemplate ? 'disabled' : ''; ?>>
                    Send Test
                </button>
                <span id="eim-invite-send-test-result" style="display:none;font-size:13px;"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the Messages tab section: a table of connection groups that have
     * submitted messages about this event, with per-row counts and popup access.
     *
     * @param Event $event The event being edited.
     */
    private function renderEventMessagesSection(Event $event): void
    {
        $summary  = EventMessage::summaryForEvent($event->id);
        $groupIds = array_keys($summary);

        // Load connection groups that have messages for this event.
        $groups = [];
        if (!empty($groupIds)) {
            $groups = ConnectionGroup::findMany($groupIds);
        }

        // Batch-load categories for all groups.
        $catsByGroup = empty($groupIds) ? [] : Category::forEntities('connection_group', $groupIds);
        ?>
        <h2 style="margin-top:20px;">Messages</h2>
        <p class="description" style="margin-bottom:12px;">
            Messages sent by invitation groups about this event. Use the counts to open a popup and review, mark as read, or delete individual messages.
        </p>

        <?php if (empty($groups)): ?>
            <p style="color:#999;margin-top:8px;">No messages have been received for this event yet.</p>
        <?php else: ?>
            <div style="margin-bottom:8px;">
                <input type="search"
                       id="eim-messages-filter"
                       class="regular-text"
                       placeholder="Filter by group name…"
                       style="max-width:280px;">
            </div>
            <table id="eim-messages-table" class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:22%;">Name</th>
                        <th style="width:28%;">Members</th>
                        <th style="width:22%;">Categories</th>
                        <th style="width:14%;text-align:center;">Messages</th>
                        <th style="width:14%;text-align:center;">Unread</th>
                    </tr>
                </thead>
                <tbody id="eim-messages-tbody">
                    <?php foreach ($groups as $group):
                        $counts     = $summary[$group->id] ?? ['total' => 0, 'unread' => 0];
                        $total      = $counts['total'];
                        $unread     = $counts['unread'];
                        $members    = $group->getMembers();
                        $memberList = implode(', ', array_map(static fn($m) => esc_html($m->fullName()), $members));
                        $cats       = $catsByGroup[$group->id] ?? [];
                        $cgUrl      = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $group->id]);
                    ?>
                    <tr data-group-id="<?= esc_attr($group->id); ?>"
                        data-group-name="<?= esc_attr($group->name); ?>"
                        data-name-lower="<?= esc_attr(strtolower($group->name)); ?>">
                        <td>
                            <a href="<?= esc_url($cgUrl); ?>">
                                <strong><?= esc_html($group->name); ?></strong>
                            </a>
                        </td>
                        <td><?= esc_html($memberList ?: '—'); ?></td>
                        <td>
                            <?php if (empty($cats)): ?>
                                <span style="color:#999;">—</span>
                            <?php else: ?>
                                <span class="eim-tag-list">
                                    <?php foreach ($cats as $cat): ?>
                                        <span class="eim-connection-tag"><?= esc_html($cat->name); ?></span>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button"
                                    class="button button-small eim-messages-open"
                                    data-group-id="<?= esc_attr($group->id); ?>"
                                    data-group-name="<?= esc_attr($group->name); ?>"
                                    data-unread-only="0">
                                <?= esc_html($total); ?>
                            </button>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($unread > 0): ?>
                                <button type="button"
                                        class="button button-small eim-messages-open"
                                        style="background:#d63638;border-color:#b32d2e;color:#fff;"
                                        data-group-id="<?= esc_attr($group->id); ?>"
                                        data-group-name="<?= esc_attr($group->name); ?>"
                                        data-unread-only="1">
                                    <?= esc_html($unread); ?>
                                </button>
                            <?php else: ?>
                                <span style="color:#999;">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Messages modal (shared, populated via AJAX) -->
        <div id="eim-messages-modal" style="display:none;position:fixed;inset:0;z-index:100000;">
            <div id="eim-messages-modal-backdrop"
                 style="position:absolute;inset:0;background:rgba(0,0,0,.5);"></div>
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
                        background:#fff;border-radius:6px;width:min(640px,92vw);max-height:80vh;
                        display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;
                            padding:14px 18px;border-bottom:1px solid #dcdcde;flex-shrink:0;">
                    <strong id="eim-messages-modal-title" style="font-size:14px;"></strong>
                    <button type="button" id="eim-messages-modal-close"
                            class="button-link" style="font-size:20px;line-height:1;color:#3c434a;">×</button>
                </div>
                <div id="eim-messages-modal-body"
                     style="overflow-y:auto;padding:16px 18px;flex:1;min-height:120px;">
                    <p style="color:#999;">Loading…</p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the Invited Invitees section on the event edit screen.
     *
     * Includes the add-invitee picker, the Send All button, the search bar,
     * and the sortable invitation-group table.
     *
     * @param Event $event The event being edited.
     */
    private function renderEventInviteesSection(Event $event): void
    {
        $sort        = $this->sanitizeEventGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order       = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $groups      = $this->sortEventGroups(InvitationGroup::forEvent($event->id), $sort, $order);
        $groupTotal  = count($groups);
        $pagedGroups = array_slice($groups, 0, 10);
        $memberCount = $event->inviteeCount();
        $dateFormat  = get_option('date_format');
        $sendAllUrl   = wp_nonce_url(
            AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'send_all_event_invites', 'event_id' => $event->id]),
            'eim_send_all_event_invites_' . $event->id
        );
        $addInviteeUrl = AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'add']);
        $maxInvitees   = $event->maxInvitees;
        $atLimit       = $maxInvitees !== null && $memberCount >= $maxInvitees;
        ?>
        <h2 style="margin-top:20px;">
            Invited Invitees
            <?php if ($maxInvitees !== null): ?>
                <span style="font-size:14px;font-weight:normal;color:<?= $atLimit ? '#d63638' : '#3c434a'; ?>;">
                    (<?= esc_html($memberCount); ?> / <?= esc_html($maxInvitees); ?> people)
                </span>
            <?php else: ?>
                <span style="font-size:14px;font-weight:normal;color:#3c434a;">(<?= esc_html($memberCount); ?> people, <?= count($groups); ?> group<?= count($groups) === 1 ? '' : 's'; ?>)</span>
            <?php endif; ?>
        </h2>
        <p class="description">
            Invitees are organised into invitation groups — one email is sent per group.
            <?php if ($maxInvitees !== null): ?>Maximum of <?= esc_html($maxInvitees); ?> individual people.<?php endif; ?>
        </p>
        <?php if ($atLimit): ?>
            <div class="notice notice-warning inline" style="margin:8px 0;"><p>This event has reached its maximum of <?= esc_html($maxInvitees); ?> invitees.</p></div>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_EVENTS)); ?>"
              class="eim-event-invitee-add-form"
              id="eim-add-invitee-form">
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

            <?php /* Connected invitees checkboxes rendered by JS after an invitee is selected */ ?>
            <div id="eim-connected-invitees-wrap" style="display:none;margin-top:10px;">
                <p style="margin:0 0 6px;font-weight:600;">Connected people — include in this invitation group?</p>
                <div id="eim-connected-invitees-list"></div>
            </div>
        </form>

        <?php if (!empty($groups)): ?>
            <p style="margin-top:14px;">
                <a href="<?= esc_url($sendAllUrl); ?>" class="button"
                   onclick="return confirm('Send invite emails to all groups that have not yet received one?');">
                    Send All Unsent Invites
                </a>
            </p>
        <?php endif; ?>

        <?php $this->renderSearchBar(
            'eim-event-groups-search',
            'eim-event-groups-count',
            'eim-event-groups-loading',
            'Search group members, email, RSVP notes...',
            count($groups),
            '',
            [
                ['value' => 'name',        'label' => 'Group Members'],
                ['value' => 'email',       'label' => 'Email'],
                ['value' => 'invite_sent', 'label' => 'Invite Sent'],
                ['value' => 'attending',   'label' => 'Registered'],
                ['value' => 'rsvp_notes',  'label' => 'RSVP Notes'],
            ]
        ); ?>

        <?php $this->renderBulkActions(
            'eim-event-groups-bulk-form',
            AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'edit', 'id' => $event->id]),
            'bulk_remove_groups_from_event',
            'eim_bulk_remove_groups_from_event_' . $event->id,
            ['event_id' => $event->id]
        ); ?>

        <?php $sortArgs = ['action' => 'edit', 'id' => $event->id, 'tab' => AdminMenu::TAB_EVENTS]; ?>
        <table id="eim-event-groups-table"
               class="wp-list-table widefat fixed striped"
               style="margin-top:12px;"
               data-sort="<?= esc_attr($sort); ?>"
               data-order="<?= esc_attr($order); ?>"
               data-total="<?= esc_attr($groupTotal); ?>">
            <thead>
                <tr>
                    <th class="eim-bulk-select-column" style="width:36px;"><?= $this->renderBulkSelectHeader('event-groups-' . $event->id); ?></th>
                    <th style="width:30px;" title="Seating"></th>
                    <th style="width:20%;"><?= $this->sortLink('Group Members',   'name',        AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:14%;"><?= $this->sortLink('Email (Primary)', 'email',       AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:10%;"><?= $this->sortLink('Invite Sent',     'invite_sent', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:9%;"><?= $this->sortLink('Registered',       'attending',   AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:10%;">Confirmation Code</th>
                    <th style="width:8%;">QR Codes</th>
                    <th style="width:10%;"><?= $this->sortLink('RSVP Notes',      'rsvp_notes',  AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:15%;">Actions</th>
                </tr>
            </thead>
            <tbody id="eim-event-groups-table-body">
                <?php $this->renderEventGroupRows($event, $pagedGroups, $dateFormat); ?>
            </tbody>
        </table>
        <?php $this->renderPaginationBar('eim-event-groups-search'); ?>

        <?php if (!empty($groups)):
            $generateQrUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'generate_all_qr_codes', 'event_id' => $event->id]),
                'eim_generate_all_qr_codes_' . $event->id
            );
            $deleteQrUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_EVENTS, ['action' => 'delete_all_qr_codes', 'event_id' => $event->id]),
                'eim_delete_all_qr_codes_' . $event->id
            );
        ?>
        <h2 style="margin-top:32px;">
            QR Code Generation/Deletion
        </h2>
        <p style="margin-top:20px;margin-bottom:0;">
            <a href="<?= esc_url($generateQrUrl); ?>" class="button"
               onclick="return confirm('Generate QR codes for all invitation groups? No emails will be sent.');">
                Generate All QR Codes (No Email)
            </a>
            <span style="margin-left:10px;color:#646970;font-size:12px;">
                Creates or restores QR code images for every group — useful for testing the RSVP flow before sending invites.
            </span>
        </p>
        <p style="margin-top:8px;margin-bottom:0;">
            <a href="<?= esc_url($deleteQrUrl); ?>" class="button button-link-delete"
               onclick="return confirm('Delete all QR code files and confirmation-code records for this event? Existing QR links for this event will stop working until QR codes are regenerated.');">
                Delete All QR Codes
            </a>
            <span style="margin-left:10px;color:#646970;font-size:12px;">
                Removes this event's stored QR files and database rows so new codes can be generated for the current website domain.
            </span>
        </p>
        <?php endif; ?>

        <?php $this->renderEventRequestedInviteesSection($event); ?>

        <?php $this->renderSeatingAssignmentsSection($groups); ?>
        <?php
    }

    /**
     * Renders the Requested Invitee Add-Ons section for a specific event.
     *
     * Shows only requests whose event_id matches this event. Placed between the
     * Invited Invitees list and the Seating Assignments section.
     *
     * @param Event $event
     * @return void
     */
    private function renderEventRequestedInviteesSection(Event $event): void
    {
        RequestedInviteeAddOn::maybeCreateTable();

        $sort     = $this->sanitizeRiarSortKey((string) ($_GET['riar_sort']  ?? 'created_at'));
        $order    = $this->sanitizeSortOrder((string) ($_GET['riar_order'] ?? 'desc'));
        $field    = $this->sanitizeRiarFieldKey((string) ($_GET['riar_field'] ?? ''));
        $all      = RequestedInviteeAddOn::listForEvent($event->id, '', $sort, $order, $field);
        $total    = count($all);
        $requests = array_slice($all, 0, 10);

        $sortArgs = ['action' => 'edit', 'id' => $event->id, 'tab' => AdminMenu::TAB_EVENTS];
        ?>
        <h2 style="margin-top:32px;">
            Requested Invitee Add-Ons
            <span style="font-size:14px;font-weight:normal;color:#3c434a;">(<?= esc_html($total); ?> request<?= $total === 1 ? '' : 's'; ?>)</span>
        </h2>
        <p class="description" style="margin-bottom:12px;">
            Add-on requests submitted by invitees for this event via the front-end RSVP form.
            Approve to add the person to the connection group and auto-RSVP them, or deny to keep on record.
        </p>

        <?php $this->renderSearchBar(
            'eim-event-riar-search',
            'eim-event-riar-count',
            'eim-event-riar-loading',
            'Search requests…',
            $total,
            '',
            [
                ['value' => 'first_name',       'label' => 'First Name'],
                ['value' => 'last_name',         'label' => 'Last Name'],
                ['value' => 'email',             'label' => 'Email'],
                ['value' => 'phone',             'label' => 'Phone'],
                ['value' => 'connection_group',  'label' => 'Connection Group'],
                ['value' => 'status',            'label' => 'Status'],
            ],
            $field
        ); ?>

        <table id="eim-event-riars-table"
               class="wp-list-table widefat fixed striped"
               style="margin-top:12px;"
               data-sort="<?= esc_attr($sort); ?>"
               data-order="<?= esc_attr($order); ?>"
               data-total="<?= esc_attr($total); ?>"
               data-event-id="<?= esc_attr($event->id); ?>">
            <thead>
                <tr>
                    <th style="width:10%;"><?= $this->sortLink('First Name', 'first_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:10%;"><?= $this->sortLink('Last Name', 'last_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:17%;"><?= $this->sortLink('Email', 'email', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:10%;"><?= $this->sortLink('Phone', 'phone', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:18%;"><?= $this->sortLink('Connection Group', 'connection_group_name', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:8%;">Details</th>
                    <th style="width:10%;"><?= $this->sortLink('Status', 'status', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:9%;"><?= $this->sortLink('Requested', 'created_at', AdminMenu::PAGE_EVENTS_MANAGER, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:8%;">Actions</th>
                </tr>
            </thead>
            <tbody id="eim-event-riars-table-body">
                <?php $this->renderEventRequestRows($requests); ?>
            </tbody>
        </table>
        <?php $this->renderPaginationBar('eim-event-riar-search'); ?>

        <?php if (empty($requests)): ?>
            <p style="margin-top:10px;color:#666;font-size:13px;">No add-on requests for this event yet.</p>
        <?php endif; ?>

        <?php $this->renderEventRiarModal(); ?>
        <?php
    }

    /**
     * Renders table rows for the per-event requested invitees section.
     *
     * Called by both the initial render and the AJAX search handler.
     *
     * @param RequestedInviteeAddOn[] $requests
     * @param string                  $search
     * @return void
     */
    private function renderEventRequestRows(array $requests, string $search = ''): void
    {
        if (empty($requests)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No requests found.';
            echo '<tr class="eim-no-results"><td colspan="9">' . esc_html($msg) . '</td></tr>';
            return;
        }

        foreach ($requests as $req) {
            $cgUrl     = AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $req->connectionGroupId]);
            $deleteUrl = wp_nonce_url(
                AdminMenu::tabUrl(AdminMenu::TAB_REQUESTED_INVITEES, ['action' => 'delete_riar', 'id' => $req->id]),
                'eim_delete_riar_' . $req->id
            );

            $thumbUrl = $req->imageAttachmentId > 0
                ? (string) wp_get_attachment_image_url($req->imageAttachmentId, 'thumbnail')
                : '';
            $fullUrl = $req->imageAttachmentId > 0
                ? (string) wp_get_attachment_image_url($req->imageAttachmentId, 'full')
                : '';

            $inviteeUrl = $req->approvedInviteeId
                ? AdminMenu::tabUrl(AdminMenu::TAB_INVITEES, ['action' => 'edit', 'id' => $req->approvedInviteeId])
                : null;

            $requestData = wp_json_encode([
                'id'                  => $req->id,
                'firstName'           => $req->firstName,
                'lastName'            => $req->lastName,
                'email'               => $req->email,
                'phone'               => $req->phone,
                'streetAddress'       => $req->streetAddress,
                'city'                => $req->city,
                'state'               => $req->state,
                'zipCode'             => $req->zipCode,
                'imageThumbUrl'       => $thumbUrl,
                'imageFullUrl'        => $fullUrl,
                'notes'               => $req->notes,
                'status'              => $req->status,
                'connectionGroupId'   => $req->connectionGroupId,
                'connectionGroupName' => $req->connectionGroupName,
                'connectionGroupUrl'  => $cgUrl,
                'eventId'             => $req->eventId,
                'eventName'           => $req->eventName,
                'eventUrl'            => null,
                'approvedInviteeId'   => $req->approvedInviteeId,
                'approvedInviteeUrl'  => $inviteeUrl,
                'createdAt'           => $req->createdAt,
            ]);

            [$bg, $color, $label] = match ($req->status) {
                'approved' => ['#dff0d8', '#3c763d', 'Approved'],
                'denied'   => ['#f2dede', '#a94442', 'Denied'],
                default    => ['#fcf8e3', '#8a6d3b', 'Pending'],
            };
            ?>
            <tr data-riar-id="<?= esc_attr($req->id); ?>" data-request="<?= esc_attr($requestData); ?>">
                <td><?= esc_html($req->firstName); ?></td>
                <td><?= esc_html($req->lastName); ?></td>
                <td><?= esc_html($req->email); ?></td>
                <td><?= esc_html($req->phone ?: '—'); ?></td>
                <td>
                    <?php if ($req->connectionGroupName): ?>
                        <a href="<?= esc_url($cgUrl); ?>"><?= esc_html($req->connectionGroupName); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="button button-small eim-riar-details-btn"
                            aria-label="<?= esc_attr('View details for ' . $req->fullName()); ?>">
                        Details
                    </button>
                </td>
                <td class="eim-riar-status-cell">
                    <span style="background:<?= esc_attr($bg); ?>;color:<?= esc_attr($color); ?>;padding:2px 8px;border-radius:3px;font-size:12px;white-space:nowrap;">
                        <?= esc_html($label); ?>
                    </span>
                </td>
                <td>
                    <span style="white-space:nowrap;"><?= esc_html(date('M j, Y', strtotime($req->createdAt))); ?></span>
                </td>
                <td>
                    <a href="<?= esc_url($deleteUrl); ?>"
                       onclick="return confirm('Delete this request from <?= esc_js($req->fullName()); ?>?');">Delete</a>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Outputs the shared request details/edit/approve/deny modal markup for the event edit page.
     *
     * Uses the same IDs as the global Requested Add-Ons tab so the single RiarModal JS
     * instance works on both pages without any changes.
     *
     * @return void
     */
    private function renderEventRiarModal(): void
    {
        ?>
        <div id="eim-riar-modal-overlay" class="eim-modal-overlay" hidden aria-hidden="true">
            <div id="eim-riar-modal"
                 class="eim-modal-dialog"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="eim-riar-modal-title">

                <div class="eim-modal-header">
                    <h2 id="eim-riar-modal-title" style="margin:0;">Request Details</h2>
                    <button type="button"
                            id="eim-riar-modal-close"
                            class="eim-modal-close button-link"
                            aria-label="Close">&#x2715;</button>
                </div>

                <div class="eim-modal-body">
                    <div id="eim-riar-modal-image" style="margin-bottom:16px;display:none;">
                        <img id="eim-riar-modal-img" src="" alt="" style="max-width:80px;max-height:80px;border-radius:4px;object-fit:cover;">
                    </div>

                    <form id="eim-riar-edit-form" novalidate>
                        <input type="hidden" id="eim-riar-edit-id" name="id" value="">

                        <div class="eim-modal-field-grid">
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-first-name">First Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                                <input type="text" id="eim-riar-edit-first-name" name="first_name" class="regular-text" required>
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-last-name">Last Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                                <input type="text" id="eim-riar-edit-last-name" name="last_name" class="regular-text" required>
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-email">Email <span aria-hidden="true" style="color:#d63638;">*</span></label>
                                <input type="email" id="eim-riar-edit-email" name="email" class="regular-text" required>
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-phone">Phone</label>
                                <input type="text" id="eim-riar-edit-phone" name="phone" class="regular-text">
                            </div>
                            <div class="eim-modal-field eim-modal-field--full">
                                <label for="eim-riar-edit-street">Street Address</label>
                                <input type="text" id="eim-riar-edit-street" name="street_address" class="regular-text">
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-city">City</label>
                                <input type="text" id="eim-riar-edit-city" name="city" class="regular-text">
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-state">State</label>
                                <input type="text" id="eim-riar-edit-state" name="state" class="regular-text">
                            </div>
                            <div class="eim-modal-field">
                                <label for="eim-riar-edit-zip">ZIP Code</label>
                                <input type="text" id="eim-riar-edit-zip" name="zip_code" class="regular-text">
                            </div>
                            <div class="eim-modal-field eim-modal-field--full">
                                <label for="eim-riar-edit-notes">Notes about this person</label>
                                <textarea id="eim-riar-edit-notes" name="notes" rows="3" class="large-text"></textarea>
                            </div>
                        </div>

                        <div id="eim-riar-cg-info" style="margin-top:12px;padding:10px 12px;background:#f6f7f7;border-radius:4px;font-size:13px;">
                            <strong>Connection Group:</strong>
                            <a id="eim-riar-cg-link" href="#"></a>
                        </div>

                        <div id="eim-riar-approved-info" style="margin-top:10px;display:none;padding:10px 12px;background:#edfaef;border-radius:4px;font-size:13px;">
                            <strong>Approved &mdash; Invitee created:</strong>
                            <a id="eim-riar-invitee-link" href="#">View Invitee</a>
                        </div>
                    </form>
                </div>

                <div class="eim-modal-footer">
                    <div class="eim-modal-footer-left">
                        <button type="button" id="eim-riar-save-btn" class="button button-secondary">Save Changes</button>
                        <span id="eim-riar-save-notice" style="margin-left:8px;font-size:13px;display:none;"></span>
                    </div>
                    <div class="eim-modal-footer-right">
                        <button type="button" id="eim-riar-deny-btn" class="button" style="margin-right:6px;">Deny</button>
                        <button type="button" id="eim-riar-approve-btn" class="button button-primary">Approve</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the Seating Assignments section below the invitation groups table.
     *
     * @param InvitationGroup[] $groups Loaded invitation groups (already have members populated).
     */
    private function renderSeatingAssignmentsSection(array $groups): void
    {
        $seated = [];
        foreach ($groups as $group) {
            $primary    = Invitee::find($group->primaryInviteeId);
            $groupLabel = $primary ? $primary->fullName() : 'Group ' . $group->id;
            foreach ($group->getMembers() as $member) {
                if ($member->seatAssignment !== '') {
                    $seated[] = [
                        'member'     => $member,
                        'group'      => $group,
                        'groupLabel' => $groupLabel,
                    ];
                }
            }
        }

        $memberIds   = array_map(fn($item) => $item['member']->id, $seated);
        $cgsByMember = empty($memberIds) ? [] : ConnectionGroup::forInvitees($memberIds);
        ?>
        <h2 style="margin-top:32px;">Seating Assignments</h2>
        <p class="description" style="margin-bottom:12px;">
            Invitees with an assigned seat. Use the group accordion toggles above to assign or update seats.
        </p>

        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <input type="search"
                   id="eim-seating-search"
                   class="regular-text"
                   placeholder="Filter seated invitees…"
                   style="max-width:300px;"
                   autocomplete="off">
            <select id="eim-seating-field" style="max-width:200px;">
                <option value="">All columns</option>
                <option value="first_name">First Name</option>
                <option value="last_name">Last Name</option>
                <option value="email">Email</option>
                <option value="phone">Phone</option>
                <option value="group_name">Connection Group</option>
                <option value="seat">Seat</option>
            </select>
            <span id="eim-seating-count" class="description"><?= count($seated); ?> assignment<?= count($seated) === 1 ? '' : 's'; ?></span>
        </div>

        <table id="eim-seating-assignments-table"
               class="wp-list-table widefat fixed striped"
               data-sort="lastName"
               data-order="asc">
            <thead>
                <tr>
                    <th style="width:14%;"><a href="#" class="eim-seating-sort" data-sort="firstName" data-order="asc">First Name <span aria-hidden="true"></span></a></th>
                    <th style="width:14%;"><a href="#" class="eim-seating-sort" data-sort="lastName"  data-order="asc">Last Name <span aria-hidden="true"></span></a></th>
                    <th style="width:20%;"><a href="#" class="eim-seating-sort" data-sort="email"     data-order="asc">Email <span aria-hidden="true"></span></a></th>
                    <th style="width:12%;"><a href="#" class="eim-seating-sort" data-sort="phone"     data-order="asc">Phone <span aria-hidden="true"></span></a></th>
                    <th style="width:22%;"><a href="#" class="eim-seating-sort" data-sort="groupName" data-order="asc">Connection Group <span aria-hidden="true"></span></a></th>
                    <th style="width:18%;"><a href="#" class="eim-seating-sort" data-sort="seat"      data-order="asc">Seat <span aria-hidden="true"></span></a></th>
                </tr>
            </thead>
            <tbody id="eim-seating-assignments-tbody">
                <?php $this->renderSeatingAssignmentRows($seated, $cgsByMember); ?>
            </tbody>
        </table>
        <?php $this->renderPaginationBar('eim-seating-search'); ?>
        <?php
    }

    /**
     * @param array<int, array{member: \EventsInviteManager\Models\Invitee, group: InvitationGroup, groupLabel: string}> $seated
     * @param array<int, \EventsInviteManager\Models\ConnectionGroup[]> $cgsByMember
     */
    private function renderSeatingAssignmentRows(array $seated, array $cgsByMember = []): void
    {
        if (empty($seated)) {
            echo '<tr id="eim-seating-empty-row"><td colspan="6" style="color:#999;">No seating assignments yet. Use the group accordions above to assign seats.</td></tr>';
            return;
        }

        foreach ($seated as $item) {
            $member     = $item['member'];
            $group      = $item['group'];
            $groupLabel = $item['groupLabel'];
            $firstCg    = ($cgsByMember[$member->id] ?? [])[0] ?? null;
            $cgLabel    = $firstCg ? $firstCg->name : $groupLabel;
            ?>
            <tr data-invitee-id="<?= esc_attr($member->id); ?>"
                data-group-id="<?= esc_attr($group->id); ?>"
                data-first-name="<?= esc_attr(strtolower($member->firstName)); ?>"
                data-last-name="<?= esc_attr(strtolower($member->lastName)); ?>"
                data-email="<?= esc_attr(strtolower($member->email)); ?>"
                data-phone="<?= esc_attr(strtolower($member->phone)); ?>"
                data-group-name="<?= esc_attr(strtolower($cgLabel)); ?>"
                data-seat="<?= esc_attr(strtolower($member->seatAssignment)); ?>">
                <td><?= esc_html($member->firstName); ?></td>
                <td><?= esc_html($member->lastName); ?></td>
                <td><?= esc_html($member->email); ?></td>
                <td><?= esc_html($member->phone ?: '—'); ?></td>
                <td>
                    <?php if ($firstCg): ?>
                        <span class="eim-tag-list">
                            <a class="eim-connection-tag"
                               href="<?= esc_url(AdminMenu::tabUrl(AdminMenu::TAB_CONNECTION_GROUPS, ['action' => 'edit', 'id' => $firstCg->id])); ?>">
                                <?= esc_html($firstCg->name); ?>
                            </a>
                        </span>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td><?= esc_html($member->seatAssignment); ?></td>
            </tr>
            <?php
        }
    }
}
