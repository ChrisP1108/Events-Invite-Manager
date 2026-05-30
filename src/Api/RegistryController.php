<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Gift;
use WP_REST_Request;
use WP_REST_Response;

class RegistryController extends AbstractApiController
{
    /**
     * Handles GET /eim/v1/registry.
     *
     * Returns registry gifts for complete, upcoming events accessible from the
     * provided QR confirmation code. Pass event_id to fetch one event only.
     */
    public function handleRegistry(WP_REST_Request $request): WP_REST_Response
    {
        $code   = trim((string) $request->get_param('confirmation_code'));
        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if (!$result->isComplete() || $result->event === null || $result->group === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before viewing the registry.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        $targetEventId = (int) ($request->get_param('event_id') ?? 0);
        $entries       = $this->registeredDashboardEntries($result, $code, true);
        $events        = [];

        foreach ($entries as $entry) {
            if ($targetEventId > 0 && $entry['event']->id !== $targetEventId) {
                continue;
            }

            $events[] = [
                'event_id'      => $entry['event']->id,
                'invitation_group_id' => $entry['group']->id,
                'edit_rsvp_url' => $this->buildRsvpEditUrl($entry['event'], $entry['code']),
                'event'         => $this->dashboardEventPayload($entry['event']),
                'registry'      => $this->registryPayloadForEvent($entry['event'], $entry['group']),
            ];
        }

        if ($targetEventId > 0 && empty($events)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Registry not found for this confirmation code and event.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'count'   => count($events),
            'events'  => $events,
        ], 200);
    }

    /**
     * Handles POST /eim/v1/registry/purchase.
     *
     * Marks an event registry item as purchased by the invitation group tied to
     * the QR code. A group can only unmark a gift it previously marked itself.
     */
    public function handleRegistryPurchase(WP_REST_Request $request): WP_REST_Response
    {
        $code       = trim((string) $request->get_param('confirmation_code'));
        $eventId    = (int) $request->get_param('event_id');
        $giftId     = (int) $request->get_param('gift_id');
        $markBought = $request->get_param('is_purchased') === null
            ? true
            : $this->toBool($request->get_param('is_purchased'));

        $result = $this->resolver->resolve($code);

        if (!$result->success) {
            return new WP_REST_Response(
                ['success' => false, 'message' => $result->message],
                404
            );
        }

        if (!$result->isComplete() || $result->event === null || $result->group === null) {
            return new WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => 'Please complete the RSVP flow before updating the registry.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        $entry = $this->dashboardEntryForEvent($this->registeredDashboardEntries($result, $code, true), $eventId);
        if ($entry === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'This event registry is not available for this confirmation code.',
            ], 403);
        }

        $gift = Gift::find($giftId);
        if ($gift === null || !Gift::isLinkedToEvent($giftId, $eventId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Gift not found for this event.',
            ], 404);
        }

        $currentGroupId = $entry['group']->id;

        // Lock the purchase row for the duration of the check+write so two simultaneous
        // clicks cannot both pass the ownership check and overwrite each other.
        global $wpdb;
        $purchasesTable = DatabaseManager::giftPurchasesTable();
        $wpdb->query('START TRANSACTION');

        $lockedRow     = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT is_purchased, purchased_by_group_id FROM {$purchasesTable}
                 WHERE gift_id = %d AND event_id = %d FOR UPDATE",
                $giftId,
                $eventId
            ),
            ARRAY_A
        );
        $alreadyBought = !empty($lockedRow['is_purchased']);
        $ownerGroupId  = isset($lockedRow['purchased_by_group_id']) ? (int) $lockedRow['purchased_by_group_id'] : null;

        if ($markBought && $alreadyBought && $ownerGroupId !== $currentGroupId) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response([
                'success' => false,
                'message' => 'This gift is already marked as purchased.',
            ], 409);
        }

        if (!$markBought && $alreadyBought && $ownerGroupId !== $currentGroupId) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Only the purchasing invitation group can unmark this gift.',
            ], 403);
        }

        Gift::setPurchaseStatus(
            $giftId,
            $eventId,
            $markBought,
            $markBought ? $currentGroupId : null,
            $markBought ? $entry['group']->primaryInviteeId : null
        );

        $wpdb->query('COMMIT');

        $purchase = Gift::purchaseDetailsForGiftEvent($giftId, $eventId);

        return new WP_REST_Response([
            'success'             => true,
            'event_id'            => $eventId,
            'invitation_group_id' => $currentGroupId,
            'gift'                => $this->giftRegistryItemPayload($gift, $eventId, $purchase, $entry['group']),
        ], 200);
    }
}
