<?php

declare(strict_types=1);

namespace EventsInviteManager\Admin\Pages;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Admin\AbstractAdminPage;
use EventsInviteManager\Admin\AdminMenu;
use EventsInviteManager\Email\EmailService;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;

/**
 * Handles invitee-related admin actions and rendering.
 */
final class InviteesPage extends AbstractAdminPage
{
    /** @var EmailService Used when sending invite emails from the admin. */
    private EmailService $emailService;

    /**
     * @param EmailService $emailService
     */
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Dispatches invitee-page form submissions and GET actions.
     *
     * @param string $action
     * @return void
     */
    public function handleAction(string $action): void
    {
        match ($action) {
            'save_invitee'   => $this->handleSaveInvitee(),
            'delete_invitee' => $this->handleDeleteInvitee(),
            'send_invite'    => $this->handleSendInvite(),
            'send_all'       => $this->handleSendAllInvites(),
            default          => null,
        };
    }

    /**
     * Renders the Invitees admin page, dispatching to the list or add/edit form.
     *
     * Requires a valid event_id in the URL; shows an event picker when absent.
     *
     * @return void
     */
    public function renderPage(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $action  = $_GET['action'] ?? 'list';

        if ($eventId === 0) {
            $this->renderEventPickerFor();
            return;
        }

        $event = Event::find($eventId);
        if (!$event) {
            $this->renderError('Event not found.', admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS));
            return;
        }

        match ($action) {
            'add'   => $this->renderInviteeForm($event, null),
            'edit'  => $this->renderInviteeForm($event, Invitee::find((int) ($_GET['id'] ?? 0))),
            default => $this->renderInviteesList($event),
        };
    }

    /**
     * Processes creating or updating an invitee from the admin form.
     *
     * @return void
     */
    private function handleSaveInvitee(): void
    {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'eim_save_invitee')) {
            wp_die('Security check failed.');
        }

        $id      = (int) ($_POST['invitee_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);

        $data = [
            'event_id'       => $eventId,
            'first_name'     => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name'      => sanitize_text_field($_POST['last_name'] ?? ''),
            'email'          => sanitize_email($_POST['email'] ?? ''),
            'street_address' => sanitize_text_field($_POST['street_address'] ?? ''),
            'city'           => sanitize_text_field($_POST['city'] ?? ''),
            'state'          => sanitize_text_field($_POST['state'] ?? ''),
            'zip_code'       => sanitize_text_field($_POST['zip_code'] ?? ''),
        ];

        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
            wp_redirect(add_query_arg([
                'page'      => AdminMenu::PAGE_INVITEES,
                'event_id'  => $eventId,
                'action'    => $id ? 'edit' : 'add',
                'id'        => $id ?: null,
                'eim_error' => 'required_fields',
            ], admin_url('admin.php')));
            exit;
        }

        if ($id > 0) {
            Invitee::update($id, $data);
            $message = 'invitee_updated';
        } else {
            Invitee::create($data);
            $message = 'invitee_created';
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_INVITEES,
            'event_id'    => $eventId,
            'eim_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Processes deleting a single invitee via a GET request with a nonce.
     *
     * @return void
     */
    private function handleDeleteInvitee(): void
    {
        $id      = (int) ($_GET['id'] ?? 0);
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_delete_invitee_' . $id)) {
            wp_die('Security check failed.');
        }

        Invitee::delete($id);

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_INVITEES,
            'event_id'    => $eventId,
            'eim_message' => 'invitee_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Sends an invite email to a single invitee via a GET request with a nonce.
     *
     * @return void
     */
    private function handleSendInvite(): void
    {
        $inviteeId = (int) ($_GET['id'] ?? 0);
        $eventId   = (int) ($_GET['event_id'] ?? 0);
        $nonce     = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_send_invite_' . $inviteeId)) {
            wp_die('Security check failed.');
        }

        $invitee = Invitee::find($inviteeId);
        $event   = Event::find($eventId);

        if ($invitee && $event) {
            $sent    = $this->emailService->sendInvite($event, $invitee);
            $message = $sent ? 'invite_sent' : 'invite_failed';
            if ($sent) {
                Invitee::markInviteSent($inviteeId);
            }
        } else {
            $message = 'not_found';
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_INVITEES,
            'event_id'    => $eventId,
            'eim_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Sends invite emails to all invitees who have not yet received one.
     *
     * @return void
     */
    private function handleSendAllInvites(): void
    {
        $eventId = (int) ($_GET['event_id'] ?? 0);
        $nonce   = (string) ($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'eim_send_all_' . $eventId)) {
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
                    Invitee::markInviteSent($invitee->id);
                    $sentCount++;
                }
            }
        }

        wp_redirect(add_query_arg([
            'page'        => AdminMenu::PAGE_INVITEES,
            'event_id'    => $eventId,
            'eim_message' => 'invites_sent',
            'count'       => $sentCount,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the invitees list table for a specific event.
     *
     * @param Event $event
     * @return void
     */
    private function renderInviteesList(Event $event): void
    {
        $invitees   = Invitee::forEvent($event->id);
        $message    = (string) ($_GET['eim_message'] ?? '');
        $error      = (string) ($_GET['eim_error'] ?? '');
        $sentCount  = (int) ($_GET['count'] ?? 0);
        $addUrl     = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id . '&action=add');
        $sendAllUrl = wp_nonce_url(
            admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id . '&action=send_all'),
            'eim_send_all_' . $event->id
        );
        $dateFormat = get_option('date_format');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                Invitees &mdash; <?= esc_html($event->name); ?>
            </h1>
            <a href="<?= esc_url($addUrl); ?>" class="page-title-action">Add Invitee</a>
            <a href="<?= esc_url($sendAllUrl); ?>" class="page-title-action"
               onclick="return confirm('Send invite emails to all invitees who have not yet received one?');">
               Send All Unsent Invites
            </a>
            <a href="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_EVENTS)); ?>" class="page-title-action">← All Events</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error, $sentCount); ?>

            <?php if (empty($invitees)): ?>
                <p>No invitees yet. <a href="<?= esc_url($addUrl); ?>">Add the first invitee.</a></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:12%;">Name</th>
                            <th style="width:18%;">Email</th>
                            <th>Address</th>
                            <th style="width:14%;">Invite Code</th>
                            <th style="width:10%;">Invite Sent</th>
                            <th style="width:10%;">Registered</th>
                            <th style="width:14%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invitees as $invitee): ?>
                            <?php
                            $editUrl   = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id . '&action=edit&id=' . $invitee->id);
                            $deleteUrl = wp_nonce_url(
                                admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id . '&action=delete_invitee&id=' . $invitee->id),
                                'eim_delete_invitee_' . $invitee->id
                            );
                            $sendUrl   = wp_nonce_url(
                                admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id . '&action=send_invite&id=' . $invitee->id),
                                'eim_send_invite_' . $invitee->id
                            );
                            ?>
                            <tr>
                                <td><strong><?= esc_html($invitee->fullName()); ?></strong></td>
                                <td><?= esc_html($invitee->email); ?></td>
                                <td><?= esc_html($invitee->formattedAddress() ?: '—'); ?></td>
                                <td><code style="font-size:11px;word-break:break-all;"><?= esc_html($invitee->inviteCode); ?></code></td>
                                <td>
                                    <?php if ($invitee->inviteSentAt): ?>
                                        <?= esc_html(date_i18n($dateFormat, strtotime($invitee->inviteSentAt))); ?>
                                    <?php else: ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($invitee->isRegistered): ?>
                                        <span style="color:#00a32a;font-weight:600;">
                                            ✓ <?= esc_html(date_i18n($dateFormat, strtotime($invitee->registeredAt ?? ''))); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#999;">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= esc_url($editUrl); ?>">Edit</a> |
                                    <a href="<?= esc_url($sendUrl); ?>">Send Invite</a> |
                                    <a href="<?= esc_url($deleteUrl); ?>"
                                       onclick="return confirm('Delete <?= esc_js($invitee->fullName()); ?>?');">Delete</a>
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
     * Renders the add/edit invitee form for a specific event.
     *
     * @param Event        $event
     * @param Invitee|null $invitee Existing invitee to edit, or null when adding a new one.
     * @return void
     */
    private function renderInviteeForm(Event $event, ?Invitee $invitee): void
    {
        $isNew   = $invitee === null;
        $message = (string) ($_GET['eim_message'] ?? '');
        $error   = (string) ($_GET['eim_error'] ?? '');
        $backUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES . '&event_id=' . $event->id);
        $title   = $isNew ? 'Add Invitee' : 'Edit Invitee';
        ?>
        <div class="wrap">
            <h1><?= esc_html($title); ?> &mdash; <?= esc_html($event->name); ?></h1>
            <a href="<?= esc_url($backUrl); ?>">← Back to Invitees</a>
            <hr class="wp-header-end">

            <?php $this->renderNotice($message, $error); ?>

            <form method="post" action="<?= esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_INVITEES)); ?>">
                <?php wp_nonce_field('eim_save_invitee'); ?>
                <input type="hidden" name="eim_action" value="save_invitee">
                <input type="hidden" name="invitee_id" value="<?= esc_attr($isNew ? 0 : $invitee->id); ?>">
                <input type="hidden" name="event_id" value="<?= esc_attr($event->id); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="eim_first_name">First Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_first_name" name="first_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->firstName); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_last_name">Last Name <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="eim_last_name" name="last_name" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->lastName); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_email">Email Address <span aria-hidden="true" style="color:#d63638;">*</span></label>
                        </th>
                        <td>
                            <input type="email" id="eim_email" name="email" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->email); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_street_address">Street Address</label>
                        </th>
                        <td>
                            <input type="text" id="eim_street_address" name="street_address" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->streetAddress); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_city">City</label>
                        </th>
                        <td>
                            <input type="text" id="eim_city" name="city" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->city); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_state">State</label>
                        </th>
                        <td>
                            <input type="text" id="eim_state" name="state" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->state); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="eim_zip_code">ZIP Code</label>
                        </th>
                        <td>
                            <input type="text" id="eim_zip_code" name="zip_code" class="regular-text"
                                   value="<?= esc_attr($isNew ? '' : $invitee->zipCode); ?>">
                        </td>
                    </tr>
                    <?php if (!$isNew): ?>
                        <tr>
                            <th scope="row">Invite Code</th>
                            <td>
                                <code><?= esc_html($invitee->inviteCode); ?></code>
                                <p class="description">Generated automatically at creation; cannot be changed.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button($isNew ? 'Add Invitee' : 'Update Invitee'); ?>
            </form>
        </div>
        <?php
    }
}
