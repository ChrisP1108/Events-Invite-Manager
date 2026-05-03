<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;

/**
 * Shared rendering helpers for the plugin's admin page classes.
 */
abstract class AbstractAdminPage
{
    /**
     * Renders a prompt to select an event when a page requires an event_id but none was supplied.
     *
     * @return void
     */
    protected function renderEventPickerFor(): void
    {
        $events  = Event::all();
        $page    = AdminMenu::PAGE_INVITEES;
        $heading = 'Invitees';
        ?>
        <div class="wrap">
            <h1><?= esc_html($heading); ?></h1>
            <p>Select an event to manage its <?= esc_html(strtolower($heading)); ?>:</p>
            <?php if (empty($events)): ?>
                <p>No events exist yet. <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS . '&action=add')); ?>">Create an event first.</a></p>
            <?php else: ?>
                <ul>
                    <?php foreach ($events as $event): ?>
                        <li>
                            <a href="<?= esc_url(admin_url('admin.php?page=' . $page . '&event_id=' . $event->id)); ?>">
                                <?= esc_html($event->name); ?>
                            </a>
                            <?php if ($event->eventDate): ?>
                                &mdash; <?= esc_html($event->formattedDate()); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders a generic error message with a back link.
     *
     * @param string $message Human-readable error text.
     * @param string $backUrl URL for the back link.
     * @return void
     */
    protected function renderError(string $message, string $backUrl): void
    {
        ?>
        <div class="wrap">
            <div class="notice notice-error">
                <p><?= esc_html($message); ?> <a href="<?= esc_url($backUrl); ?>">Go back.</a></p>
            </div>
        </div>
        <?php
    }

    /**
     * Renders an admin notice based on the eim_message / eim_error query params.
     *
     * @param string $messageKey Message key from the redirect URL.
     * @param string $errorKey   Error key from the redirect URL.
     * @param int    $count      Optional count used in bulk-operation messages.
     * @return void
     */
    protected function renderNotice(string $messageKey, string $errorKey = '', int $count = 0): void
    {
        $successes = [
            'event_created'    => 'Event created successfully.',
            'event_updated'    => 'Event updated successfully.',
            'event_deleted'    => 'Event deleted.',
            'invitee_created'  => 'Invitee added successfully.',
            'invitee_updated'  => 'Invitee updated successfully.',
            'invitee_deleted'  => 'Invitee deleted.',
            'invite_sent'      => 'Invite email sent successfully.',
            'invites_sent'     => "Sent {$count} invite email(s) to unsent invitees.",
            'lodging_created'  => 'Lodging location added successfully.',
            'lodging_updated'  => 'Lodging location updated successfully.',
            'lodging_deleted'  => 'Lodging location deleted.',
            'location_created' => 'Location added successfully.',
            'location_updated' => 'Location updated successfully.',
            'location_deleted' => 'Location deleted.',
        ];

        $errors = [
            'name_required'           => 'Event name is required.',
            'lodging_name_required'   => 'Location name is required.',
            'location_name_required'  => 'Location name is required.',
            'venue_invalid_location'  => 'Please select the venue from the locations library — free-text entries are not allowed.',
            'lodging_invalid_location' => 'Please select each lodging location from the locations library — free-text entries are not allowed.',
            'required_fields'         => 'First name, last name, and email address are all required.',
            'invite_failed'           => 'Failed to send the invite email. Please check your email configuration.',
            'not_found'               => 'Invitee or event not found.',
        ];

        if (isset($successes[$messageKey])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($successes[$messageKey]) . '</p></div>';
        }

        if (isset($errors[$errorKey])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($errors[$errorKey]) . '</p></div>';
        }
    }
}
