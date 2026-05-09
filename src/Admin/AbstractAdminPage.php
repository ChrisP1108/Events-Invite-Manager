<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Shared rendering helpers for the plugin's admin page classes.
 */
abstract class AbstractAdminPage
{
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
            'event_invitee_added' => 'Invitee added to event successfully.',
            'event_invitee_removed' => 'Invitee removed from event.',
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
            'invitee_required'        => 'Please select an existing invitee before adding them to this event.',
            'invitee_limit_reached'   => 'This event has reached its maximum invitee limit. Increase or remove the limit to add more invitees.',
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
