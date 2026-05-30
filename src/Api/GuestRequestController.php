<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Models\RequestedInviteeAddOn;
use WP_REST_Request;
use WP_REST_Response;

class GuestRequestController extends AbstractApiController
{
    /**
     * Handles POST /eim/v1/request-guest.
     *
     * Allows an authenticated invitee (via QR code) to request that an additional
     * guest be added to their invitation group. The request is stored as a pending
     * RequestedInviteeAddOn for admin review and approval.
     */
    public function handleRequestGuest(WP_REST_Request $request): WP_REST_Response
    {
        $code = trim((string) $request->get_param('confirmation_code'));

        $qrCode = QrCode::findByCode($code);
        if ($qrCode === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Invalid or unrecognised confirmation code.'],
                404
            );
        }

        $group = InvitationGroup::find($qrCode->groupId);
        if ($group === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No invitation group was found for this confirmation code.'],
                404
            );
        }

        $email = strtolower(trim((string) $request->get_param('email')));
        if ($email === '' || !is_email($email)) {
            return $this->validationErrorResponse(['email' => 'Enter a valid email address.']);
        }

        $connectionGroupId = $this->resolveConnectionGroupId($group);
        if ($connectionGroupId === null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'No connection group found for this invitation.'],
                422
            );
        }

        // Duplicate-pending check: same email + invitation group.
        global $wpdb;
        $riarTable = DatabaseManager::requestedInviteeAddOnsTable();
        $existing  = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$riarTable} WHERE invitation_group_id = %d AND email = %s AND status = 'pending' LIMIT 1",
            $group->id,
            $email
        ));
        if ($existing !== null) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'A pending request for this email already exists for your invitation.'],
                409
            );
        }

        $id = RequestedInviteeAddOn::create([
            'connection_group_id' => $connectionGroupId,
            'event_id'            => $qrCode->eventId,
            'invitation_group_id' => $group->id,
            'first_name'          => (string) $request->get_param('first_name'),
            'last_name'           => (string) $request->get_param('last_name'),
            'email'               => $email,
            'phone'               => (string) ($request->get_param('phone') ?? ''),
            'street_address'      => (string) ($request->get_param('street_address') ?? ''),
            'city'                => (string) ($request->get_param('city') ?? ''),
            'state'               => (string) ($request->get_param('state') ?? ''),
            'zip_code'            => (string) ($request->get_param('zip_code') ?? ''),
            'notes'               => (string) ($request->get_param('notes') ?? ''),
        ]);

        if ($id === false) {
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Failed to submit guest request. Please try again.'],
                500
            );
        }

        return new WP_REST_Response(['success' => true, 'request_id' => $id], 201);
    }
}
