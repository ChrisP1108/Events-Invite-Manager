<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Services\QrCodeService;

/**
 * Handles event-related admin actions and rendering.
 */
final class EventsPage extends AbstractAdminPage
{
    private EmailService  $emailService;
    private QrCodeService $qrCodeService;

    public function __construct(EmailService $emailService, QrCodeService $qrCodeService)
    {
        $this->emailService  = $emailService;
        $this->qrCodeService = $qrCodeService;
    }

    public function handleAction(string $action): void
    {
        match ($action) {
            'save_event'                => $this->handleSaveEvent(),
            'delete_event'              => $this->handleDeleteEvent(),
            'add_lodging_to_event'      => $this->handleAddLodgingToEvent(),
            'remove_lodging_from_event' => $this->handleRemoveLodgingFromEvent(),
            'add_invitee_to_event'      => $this->handleAddInviteeToEvent(),
            'remove_invitee_from_event' => $this->handleRemoveInviteeFromEvent(),
            'set_group_primary'         => $this->handleSetGroupPrimary(),
            'add_member_to_group'       => $this->handleAddMemberToGroup(),
            'remove_group_from_event'   => $this->handleRemoveGroupFromEvent(),
            'send_event_invite'         => $this->handleSendEventInvite(),
            'send_all_event_invites'    => $this->handleSendAllEventInvites(),
            'add_menu_item_to_event'     => $this->handleAddMenuItemToEvent(),
            'remove_menu_item_from_event' => $this->handleRemoveMenuItemFromEvent(),
            default                     => null,
        };
    }

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        match ($action) {
            'add'   => $this->renderEventForm(null),
            'edit'  => $this->renderEventForm(Event::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderEventsList(),
        };
    }

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
            'invite_email_template' => wp_kses_post(wp_unslash($_POST['invite_email_template'] ?? '')),
            'rsvp_page_id'          => (int) ($_POST['rsvp_page_id'] ?? 0),
            'venue_id'              => (int) ($_POST['venue_library_id'] ?? 0),
            'start_datetime'        => $this->sanitizeDatetimeLocal($_POST['start_datetime'] ?? '', $timezone),
            'end_datetime'          => $this->sanitizeDatetimeLocal($_POST['end_datetime']   ?? '', $timezone),
            'timezone'              => $timezone,
            'lodging_enabled'          => !empty($_POST['lodging_enabled']) ? 1 : 0,
            'food_options_enabled'     => !empty($_POST['food_options_enabled']) ? 1 : 0,
            'beverage_options_enabled' => !empty($_POST['beverage_options_enabled']) ? 1 : 0,
            'max_invitees'             => (int) ($_POST['max_invitees'] ?? 0),
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

        $venueLocationId = (int) ($_POST['venue_library_id'] ?? 0);
        if ($venueLocationId > 0 && Location::find($venueLocationId) === null) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'venue_invalid_location',
            ], admin_url('admin.php')));
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
                        wp_redirect(add_query_arg([
                            'page'      => AdminMenu::PAGE_EVENTS,
                            'action'    => 'add',
                            'eim_error' => 'lodging_duplicate_location',
                        ], admin_url('admin.php')));
                        exit;
                    }
                    $seenLodgingIds[$locId] = true;
                    $loc = Location::find($locId);
                    if ($loc === null || !$loc->hasLodging) {
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
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_EVENTS,
                'eim_message' => 'event_updated',
            ], admin_url('admin.php')));
        } else {
            $newId = Event::create($data);
            if ($newId && !empty($data['lodging_enabled'])) {
                $this->saveInitialLodgingLocation($newId);
            }
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_EVENTS,
                'action'      => 'edit',
                'id'          => $newId ?: 0,
                'eim_message' => 'event_created',
            ], admin_url('admin.php')));
        }
        exit;
    }

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

    private function handleAddLodgingToEvent(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_add_lodging_to_event')) {
            wp_die('Security check failed.');
        }

        $eventId    = (int) ($_POST['event_id'] ?? 0);
        $locationId = (int) ($_POST['lodging_add_library_id'] ?? 0);
        $loc        = $locationId > 0 ? Location::find($locationId) : null;

        if ($eventId === 0 || $loc === null || !$loc->hasLodging) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'lodging_invalid_location',
            ], admin_url('admin.php')));
            exit;
        }

        $created = EventLodging::create($eventId, $locationId);
        $args    = ['page' => AdminMenu::PAGE_EVENTS, 'action' => 'edit', 'id' => $eventId];

        if ($created) {
            $args['eim_message'] = 'lodging_created';
        } else {
            $args['eim_error'] = 'lodging_create_failed';
        }

        wp_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function handleRemoveLodgingFromEvent(): void
    {
        $id      = (int) ($_GET['id']       ?? 0);
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_lodging_' . $id)) {
            wp_die('Security check failed.');
        }

        EventLodging::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'lodging_deleted',
        ], admin_url('admin.php')));
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
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invitee_required',
            ], admin_url('admin.php')) . '#eim-event-invitees');
            exit;
        }

        if (Invitee::findForEvent($inviteeId, $eventId) !== null) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_already_invited',
            ], admin_url('admin.php')) . '#eim-event-invitees');
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
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_limit_reached',
            ], admin_url('admin.php')) . '#eim-event-invitees');
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

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_added',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

    private function handleRemoveInviteeFromEvent(): void
    {
        $eventId   = (int) ($_GET['event_id']   ?? 0);
        $inviteeId = (int) ($_GET['invitee_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_invitee_' . $eventId . '_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        InvitationGroup::removeMemberFromEvent($inviteeId, $eventId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_removed',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

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

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'primary_updated',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

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
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invalid_request',
            ], admin_url('admin.php')) . '#eim-event-invitees');
            exit;
        }

        if (Invitee::findForEvent($inviteeId, $eventId) !== null) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_already_invited',
            ], admin_url('admin.php')) . '#eim-event-invitees');
            exit;
        }

        if ($event->maxInvitees !== null && ($event->inviteeCount() + 1) > $event->maxInvitees) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId,
                'eim_error' => 'invitee_limit_reached',
            ], admin_url('admin.php')) . '#eim-event-invitees');
            exit;
        }

        InvitationGroup::addMemberToGroup($groupId, $inviteeId, $eventId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_added',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

    private function handleRemoveGroupFromEvent(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $groupId = (int) ($_GET['group_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_group_' . $eventId . '_' . $groupId)) {
            wp_die('Security check failed.');
        }

        InvitationGroup::deleteGroup($groupId, $eventId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'event_invitee_removed',
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

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
            wp_redirect(add_query_arg([
                'page'        => AdminMenu::PAGE_EVENTS,
                'action'      => 'edit',
                'id'          => $eventId,
                'eim_message' => 'menu_item_added_to_event',
            ], admin_url('admin.php')) . '#eim-rsvp-options');
        } else {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_EVENTS,
                'action'    => 'edit',
                'id'        => $eventId ?: null,
                'eim_error' => 'invalid_request',
            ], admin_url('admin.php')) . '#eim-rsvp-options');
        }
        exit;
    }

    private function handleRemoveMenuItemFromEvent(): void
    {
        $eventId    = (int) ($_GET['event_id']    ?? 0);
        $menuItemId = (int) ($_GET['menu_item_id'] ?? 0);
        $nonce      = (string) ($_GET['_wpnonce']  ?? '');

        if (!wp_verify_nonce($nonce, 'eim_remove_menu_item_' . $eventId . '_' . $menuItemId)) {
            wp_die('Security check failed.');
        }

        MenuItem::removeFromEvent($eventId, $menuItemId);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'menu_item_removed_from_event',
        ], admin_url('admin.php')) . '#eim-rsvp-options');
        exit;
    }

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
                $qrCode = $this->qrCodeService->getOrCreateForGroup($event, $group);

                if ($qrCode === null) {
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

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => $message,
        ], admin_url('admin.php')) . '#eim-event-invitees');
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
                if ($primaryInvitee === null) {
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

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_EVENTS,
            'action'      => 'edit',
            'id'          => $eventId,
            'eim_message' => 'invites_sent',
            'count'       => $sentCount,
        ], admin_url('admin.php')) . '#eim-event-invitees');
        exit;
    }

    private function renderEventsList(): void
    {
        $events       = Event::all();
        $message      = (string) ($_GET['eim_message'] ?? '');
        $error        = (string) ($_GET['eim_error'] ?? '');
        $hasLocations = Location::count() > 0;

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
                            <th style="width:12%;">Invitees</th>
                            <th style="width:14%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $total       = $event->inviteeCount();
                            $registered  = $event->registeredCount();
                            $venue       = $event->venueId !== null ? Location::find($event->venueId) : null;
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
                            $jumpUrl = esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=edit&id=' . $e->id));
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
                        <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_LOCATIONS . '&action=add')); ?>">Add a location now →</a>
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error'] ?? '');
        $title   = $isNew ? 'Add New Event' : 'Edit Event: ' . $event->name;
        $addLodgingFormId = 'eim-add-lodging-form';
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?></h1>
            <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>" style="margin-top: 12px; display: block;">← Back to Events</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error, (int) ($_GET['count'] ?? 0)); ?>

            <?php if (!$isNew): ?>
                <form id="<?= esc_attr($addLodgingFormId); ?>" method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>"></form>
            <?php endif; ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>">
                <?php wp_nonce_field('eim_save_event'); ?>
                <input type="hidden" name="eim_action" value="save_event">
                <input type="hidden" name="event_id" value="<?= esc_attr($isNew ? 0 : $event->id); ?>">

                <table class="form-table" role="presentation">
                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Details</h2></td></tr>
                    <tr>
                        <th scope="row"><label for="eim_name">Event Name <span aria-hidden="true" style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" id="eim_name" name="name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $event->name); ?>" required>
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
                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Venue / Location</h2></td></tr>

                    <?php
                    $venue           = (!$isNew && $event->venueId !== null) ? Location::find($event->venueId) : null;
                    $venueLocationId = $venue ? $venue->id : 0;
                    $venueAddress    = $venue ? $venue->formattedAddress() : '';
                    ?>
                    <input type="hidden" id="eim_venue_library_id" name="venue_library_id"     value="<?= esc_attr($venueLocationId); ?>">
                    <input type="hidden" id="eim_venue_street"     name="venue_street_address" value="<?= esc_attr($venue ? $venue->streetAddress : ''); ?>">
                    <input type="hidden" id="eim_venue_city"       name="venue_city"           value="<?= esc_attr($venue ? $venue->city : ''); ?>">
                    <input type="hidden" id="eim_venue_state"      name="venue_state"          value="<?= esc_attr($venue ? $venue->state : ''); ?>">
                    <input type="hidden" id="eim_venue_zip"        name="venue_zip_code"       value="<?= esc_attr($venue ? $venue->zipCode : ''); ?>">
                    <tr>
                        <th scope="row"><label for="eim_venue_name">Venue Name</label></th>
                        <td>
                            <input type="text" id="eim_venue_name" name="venue_name" class="regular-text"
                                   value="<?= esc_attr($venue ? $venue->name : ''); ?>" autocomplete="off">
                            <p id="eim_venue_address_display" style="margin-top:6px;color:#3c434a;<?= $venueAddress ? '' : 'display:none;'; ?>">
                                <?= esc_html($venueAddress); ?>
                            </p>
                            <p class="description" style="margin-top:4px;">
                                Start typing to search the locations catalogue.
                                <?php if (!$isNew): ?>Clear this field and save to remove the venue.<?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Invite Email</h2></td></tr>

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
                    <tr>
                        <th scope="row"><label for="invite_email_template">Email Body</label></th>
                        <td>
                            <?php
                            wp_editor(
                                $isNew ? '' : $event->inviteEmailTemplate,
                                'invite_email_template',
                                ['textarea_name' => 'invite_email_template', 'media_buttons' => false, 'textarea_rows' => 15]
                            );
                            ?>
                            <p class="description">
                                Invitee tags:
                                <code>{{ event_name }}</code> <code>{{ first_name }}</code> <code>{{ last_name }}</code>
                                <code>{{ full_name }}</code> <code>{{ email }}</code> <code>{{ qr_code }}</code> <code>{{ invite_url }}</code>
                            </p>
                            <p class="description">
                                Group tags:
                                <code>{{ group_names }}</code> — all members' names ·
                                <code>{{ invitee_names }}</code> — same as group_names ·
                                <code>{{ invitee_count }}</code> — number of people in the group
                            </p>
                        </td>
                    </tr>

                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">QR Code &amp; RSVP</h2></td></tr>

                    <tr>
                        <th scope="row"><label for="eim_rsvp_page_id">QR Code RSVP Page Redirect</label></th>
                        <td>
                            <?php
                            $pages          = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);
                            $selectedPageId = $isNew ? 0 : ($event->rsvpPageId ?? 0);
                            ?>
                            <select id="eim_rsvp_page_id" name="rsvp_page_id">
                                <option value="0">— No redirect page selected —</option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?= esc_attr($page->ID); ?>" <?php selected($selectedPageId, $page->ID); ?>>
                                        <?= esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selectedPageId > 0): ?>
                                <p class="description">
                                    Current RSVP page: <a href="<?= esc_url(get_permalink($selectedPageId)); ?>" target="_blank"><?= esc_html(get_the_title($selectedPageId)); ?> ↗</a>
                                </p>
                            <?php endif; ?>
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
	                                                <th style="width:8%;"><?= $this->clientSortLink('Order', 'order', 'order', 'asc'); ?></th>
	                                                <th><?= $this->clientSortLink('Name / Address', 'name', 'order', 'asc'); ?></th>
	                                                <th style="width:12%;">Actions</th>
	                                            </tr>
	                                        </thead>
	                                        <tbody>
	                                            <?php foreach ($lodgingLocations as $position => $loc): ?>
	                                                <?php
	                                                $removeUrl = wp_nonce_url(
	                                                    admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=remove_lodging_from_event&id=' . $loc->id . '&event_id=' . $event->id),
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
	                                                    <td class="eim-order-cell"><?= esc_html($displayOrder); ?></td>
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
                    <tr><td colspan="2" class="sub-heading"><h2 class="title" style="margin-top:0;">Food &amp; Beverage</h2></td></tr>
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

                <?php submit_button($isNew ? 'Create Event' : 'Update Event'); ?>
            </form>

            <?php if (!$isNew): ?>
                <?php $this->renderRsvpOptionsSection($event); ?>
                <?php $this->renderEventInviteesSection($event); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderRsvpOptionsSection(Event $event): void
    {
        if (!$event->foodOptionsEnabled && !$event->beverageOptionsEnabled) {
            return;
        }

        $allItems  = MenuItem::forEvent($event->id);
        $foodItems = array_values(array_filter($allItems, static fn(MenuItem $i) => $i->type === MenuItem::TYPE_FOOD));
        $bevItems  = array_values(array_filter($allItems, static fn(MenuItem $i) => $i->type === MenuItem::TYPE_BEVERAGE));
        $menuItemsUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_MENU_ITEMS);
        ?>
        <hr id="eim-rsvp-options" style="margin:32px 0 20px;">
        <h2>Food &amp; Beverage Options</h2>
        <p class="description">
            Select items from the
            <a href="<?= esc_url($menuItemsUrl); ?>">Food &amp; Beverages library</a>
            to offer at this event. These options are presented to invitees during RSVP.
        </p>

        <?php if ($event->foodOptionsEnabled): ?>
            <?php $this->renderEventMenuItemsSubsection($event, MenuItem::TYPE_FOOD, 'Food Options', $foodItems); ?>
        <?php endif; ?>

        <?php if ($event->beverageOptionsEnabled): ?>
            <div style="margin-top:20px;">
                <?php $this->renderEventMenuItemsSubsection($event, MenuItem::TYPE_BEVERAGE, 'Beverage Options', $bevItems); ?>
            </div>
        <?php endif; ?>
        <?php
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
	        $columnCount = $canReorder ? 5 : 4;
	        ?>
	        <h3><?= esc_html($heading); ?></h3>

	        <div style="max-width:680px;">
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
	                                admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS
	                                    . '&action=remove_menu_item_from_event'
                                    . '&event_id=' . $event->id
	                                    . '&menu_item_id=' . $item->id),
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

        <div style="border:1px solid #dcdcde;border-radius:4px;padding:14px;background:#f6f7f7;max-width:680px;">
            <h4 style="margin:0 0 8px;">Add <?= esc_html(ucfirst($label)); ?> Item</h4>
            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>">
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
	     * Renders the event-specific invitee section, grouped by invitation group.
	     */
    private function sanitizeEventGroupSortKey(string $key): string
    {
        $key = sanitize_key($key);
        return in_array($key, ['name', 'email', 'members', 'invite_sent', 'attending'], true) ? $key : 'name';
    }

    private function sanitizeEventGroupFieldKey(string $field): string
    {
        $field = sanitize_key($field);
        return in_array($field, ['name', 'email', 'invite_sent', 'attending'], true) ? $field : '';
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

                    default:
                        // Any: search member names + emails
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
            return 0;
        });

        return $groups;
    }

    public function handleAjaxSortGroups(): void
    {
        check_ajax_referer('eim_event_groups_sort_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $eventId = (int) ($_GET['event_id'] ?? 0);
        $sort    = $this->sanitizeEventGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order   = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $query   = sanitize_text_field(wp_unslash($_GET['query'] ?? ''));
        $field   = $this->sanitizeEventGroupFieldKey((string) ($_GET['field'] ?? ''));

        $event = $eventId > 0 ? Event::find($eventId) : null;
        if (!$event) {
            wp_send_json_error('Event not found.', 404);
        }

        $dateFormat = get_option('date_format');
        $groups     = $this->sortEventGroups(
            $this->filterEventGroups(InvitationGroup::forEvent($eventId), $query, $field, $dateFormat),
            $sort,
            $order
        );

        ob_start();
        $this->renderEventGroupRows($event, $groups, $dateFormat, $query);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => count($groups)]);
    }

    private function renderEventGroupRows(Event $event, array $groups, string $dateFormat, string $search = ''): void
    {
        if (empty($groups)) {
            $msg = $search !== '' ? 'No results found based upon search criteria.' : 'No invitees have been added to this event yet.';
            echo '<tr><td colspan="5">' . esc_html($msg) . '</td></tr>';
            return;
        }

        $rsvpOptionMap = [];
        if ($event->foodOptionsEnabled || $event->beverageOptionsEnabled) {
            foreach (MenuItem::forEvent($event->id) as $item) {
                $rsvpOptionMap[$item->id] = $item;
            }
        }

        foreach ($groups as $group) {
            $members              = $group->getMembers();
            $primaryInvitee       = Invitee::find($group->primaryInviteeId);
            $attendingCount       = $group->attendingCount();
            $memberCount          = $group->memberCount();
            $pending              = count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_PENDING));
            $declined             = count(array_filter($members, static fn(Invitee $m) => $m->rsvpStatus === InvitationGroup::RSVP_DECLINED));
            $sendUrl              = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=send_event_invite&event_id=' . $event->id . '&group_id=' . $group->id),
                'eim_send_event_invite_' . $event->id . '_' . $group->id
            );
            $removeGroupUrl       = wp_nonce_url(
                admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=remove_group_from_event&event_id=' . $event->id . '&group_id=' . $group->id),
                'eim_remove_group_' . $event->id . '_' . $group->id
            );
            $allConnections       = ConnectionGroup::connectedInviteesForEvent($group->primaryInviteeId, $event->id);
            $uninvitedConnections = array_values(array_filter($allConnections, static fn(array $c) => !$c['already_invited']));
            ?>
            <tr>
                <td>
                    <span class="eim-tag-list">
                        <?php foreach ($members as $member): ?>
                            <?php
                            $removeUrl      = wp_nonce_url(
                                admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=remove_invitee_from_event&event_id=' . $event->id . '&invitee_id=' . $member->id),
                                'eim_remove_invitee_' . $event->id . '_' . $member->id
                            );
                            $editInvUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=edit&id=' . $member->id);
                            $makePrimaryUrl = wp_nonce_url(
                                admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=set_group_primary&event_id=' . $event->id . '&group_id=' . $group->id . '&invitee_id=' . $member->id),
                                'eim_set_primary_' . $event->id . '_' . $group->id . '_' . $member->id
                            );
                            $isPrimary      = $member->id === $group->primaryInviteeId;
                            ?>
                            <?php
                            $foodLabel = ($member->foodOptionId && isset($rsvpOptionMap[$member->foodOptionId]))
                                ? $rsvpOptionMap[$member->foodOptionId]->label : null;
                            $bevLabel  = ($member->beverageOptionId && isset($rsvpOptionMap[$member->beverageOptionId]))
                                ? $rsvpOptionMap[$member->beverageOptionId]->label : null;
                            ?>
                            <span class="eim-group-member-tag<?= $isPrimary ? ' eim-group-member-primary' : ''; ?>">
                                <span class="eim-member-dropdown">
                                    <button type="button"
                                            class="eim-member-dropdown-trigger"
                                            aria-haspopup="true"
                                            aria-expanded="false"><?= esc_html($member->fullName()); ?><?= $isPrimary ? ' <span class="eim-event-tag-role" title="Primary recipient">✉</span>' : ''; ?></button>
                                    <div class="eim-member-dropdown-menu" role="menu" hidden>
                                        <a href="<?= esc_url($editInvUrl); ?>" role="menuitem">Edit Invitee</a>
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
            <tr class="eim-add-member-row" id="eim-add-member-row-<?= esc_attr($group->id); ?>" style="display:none;">
                <td colspan="5" class="eim-add-member-cell">
                    <form method="post"
                          action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add_member_to_group')); ?>"
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
                <td colspan="5" class="eim-add-member-cell">
                    <form method="post"
                          action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add_member_to_group')); ?>"
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

    private function renderEventInviteesSection(Event $event): void
    {
        $sort        = $this->sanitizeEventGroupSortKey((string) ($_GET['sort']  ?? 'name'));
        $order       = $this->sanitizeSortOrder((string) ($_GET['order'] ?? 'asc'));
        $groups      = $this->sortEventGroups(InvitationGroup::forEvent($event->id), $sort, $order);
        $memberCount = $event->inviteeCount();
        $dateFormat  = get_option('date_format');
        $sendAllUrl   = wp_nonce_url(
            admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=send_all_event_invites&event_id=' . $event->id),
            'eim_send_all_event_invites_' . $event->id
        );
        $addInviteeUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&action=add');
        $maxInvitees   = $event->maxInvitees;
        $atLimit       = $maxInvitees !== null && $memberCount >= $maxInvitees;
        ?>
        <hr id="eim-event-invitees" style="margin:32px 0 20px;">
        <h2>
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

        <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>"
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
            'Search group members, email...',
            count($groups),
            '',
            [
                ['value' => 'name',        'label' => 'Group Members'],
                ['value' => 'email',       'label' => 'Email'],
                ['value' => 'invite_sent', 'label' => 'Invite Sent'],
                ['value' => 'attending',   'label' => 'Registered'],
            ]
        ); ?>

        <?php $sortArgs = ['action' => 'edit', 'id' => $event->id]; ?>
        <table id="eim-event-groups-table"
               class="wp-list-table widefat fixed striped"
               style="margin-top:12px;"
               data-sort="<?= esc_attr($sort); ?>"
               data-order="<?= esc_attr($order); ?>">
            <thead>
                <tr>
                    <th style="width:28%;"><?= $this->sortLink('Group Members',   'name',        AdminMenu::PAGE_EVENTS, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:20%;"><?= $this->sortLink('Email (Primary)', 'email',       AdminMenu::PAGE_EVENTS, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:13%;"><?= $this->sortLink('Invite Sent',     'invite_sent', AdminMenu::PAGE_EVENTS, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:12%;"><?= $this->sortLink('Registered',      'attending',   AdminMenu::PAGE_EVENTS, $sort, $order, '', $sortArgs); ?></th>
                    <th style="width:27%;">Actions</th>
                </tr>
            </thead>
            <tbody id="eim-event-groups-table-body">
                <?php $this->renderEventGroupRows($event, $groups, $dateFormat); ?>
            </tbody>
        </table>
        <?php
    }
}
