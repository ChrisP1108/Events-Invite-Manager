<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\EventMessage;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\QrCode;
use WP_REST_Request;
use WP_REST_Response;

class MessagesController extends AbstractApiController
{
    /**
     * Handles GET /eim/v1/messages.
     *
     * Returns all messages for the invitation group's connection group scoped to
     * the QR code's event. The event_id must match the QR code's event so an
     * invitee cannot read messages for events they are not invited to.
     */
    public function handleGetMessages(WP_REST_Request $request): WP_REST_Response
    {
        $code    = trim((string) $request->get_param('confirmation_code'));
        $eventId = (int) $request->get_param('event_id');

        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        if ($qrCode->eventId !== $eventId) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'This event is not accessible via this confirmation code.'],
                403
            );
        }

        $group = InvitationGroup::find($qrCode->groupId);
        if ($group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation group was found for this confirmation code.'],
                404
            );
        }

        $connectionGroupId = $this->resolveConnectionGroupId($group);
        if ($connectionGroupId === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No connection group found for this invitation.'],
                422
            );
        }

        $messages = EventMessage::forEventGroup($eventId, $connectionGroupId);

        return new WP_REST_Response([
            'success'             => true,
            'event_id'            => $eventId,
            'connection_group_id' => $connectionGroupId,
            'count'               => count($messages),
            'messages' => array_map(static fn(EventMessage $msg): array => [
                'id'             => $msg->id,
                'message'        => $msg->message,
                'is_read'        => (bool) $msg->isRead,
                'is_admin_reply' => (bool) $msg->isAdminReply,
                'created_at'     => $msg->createdAt,
            ], $messages),
        ], 200);
    }

    /**
     * Handles POST /eim/v1/messages.
     *
     * Creates a new message from the invitee's connection group for the QR code's
     * event. The event_id must match the QR code's event.
     */
    public function handlePostMessage(WP_REST_Request $request): WP_REST_Response
    {
        $code    = trim((string) $request->get_param('confirmation_code'));
        $eventId = (int) $request->get_param('event_id');
        $message = trim((string) $request->get_param('message'));

        if ($message === '') {
            return $this->validationErrorResponse(['message' => 'Message cannot be empty.']);
        }

        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        if ($qrCode->eventId !== $eventId) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'This event is not accessible via this confirmation code.'],
                403
            );
        }

        $group = InvitationGroup::find($qrCode->groupId);
        if ($group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation group was found for this confirmation code.'],
                404
            );
        }

        if (($throttled = $this->throttleGuestWrite($code, 'messages', 20)) !== null) {
            return $throttled;
        }

        $connectionGroupId = $this->resolveConnectionGroupId($group);
        if ($connectionGroupId === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No connection group found for this invitation.'],
                422
            );
        }

        // Cap message length server-side rather than relying on the TEXT column.
        $message = mb_substr($message, 0, 5000);

        $id = EventMessage::create($eventId, $connectionGroupId, $message);

        if ($id === false) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Failed to send message. Please try again.'],
                500
            );
        }

        return new WP_REST_Response(['success' => true, 'message_id' => $id], 201);
    }
}
