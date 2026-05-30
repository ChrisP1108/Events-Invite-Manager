<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Newsletter;
use EventsInviteManager\Services\RsvpFlowResult;
use WP_REST_Request;
use WP_REST_Response;

class DashboardController extends AbstractApiController
{
    /**
     * Handles GET /eim/v1/dashboard.
     *
     * Returns all upcoming events the invitation group is registered for, along
     * with RSVP details and published newsletters for each. Requires the RSVP
     * flow to be fully complete (next_action === dashboard_redirect or declined).
     */
    public function handleDashboard(WP_REST_Request $request): WP_REST_Response
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
                    'message'     => 'Please complete the RSVP flow before accessing the dashboard.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        // Pass requireAttendingMembers: false so every invited event appears on the
        // dashboard, not just ones with at least one attending member. This covers
        // pending invitations, fully declined events, and incomplete RSVP flows.
        $entries = $this->registeredDashboardEntries($result, $code, false, false);
        $events  = [];

        foreach ($entries as $entry) {
            $flow        = $entry['flow'];
            $event       = $entry['event'];
            $targetCode  = $entry['code'];
            $newsletters = $flow->nextAction === RsvpFlowResult::ACTION_DASHBOARD_REDIRECT
                ? Newsletter::publishedForEvent($event->id)
                : [];

            $events[] = [
                'event_id'            => $event->id,
                'confirmation_code'   => $targetCode,
                'invitation_group_id' => $entry['group']->id,
                'next_action'       => $flow->nextAction,
                'is_complete'       => $flow->nextAction === RsvpFlowResult::ACTION_DASHBOARD_REDIRECT,
                'requires_lodging'  => $flow->requiresLodging,
                'requires_food'     => $flow->requiresFood,
                'requires_beverage' => $flow->requiresBeverage,
                'dashboard_url'     => $flow->dashboardUrl,
                'edit_rsvp_url'     => $this->buildRsvpEditUrl($event, $targetCode),
                'event'             => $this->dashboardEventPayload($event),
                'rsvp'              => $this->rsvpSummaryPayload($flow, $targetCode),
                'registry'          => $flow->nextAction === RsvpFlowResult::ACTION_DASHBOARD_REDIRECT
                    ? $this->registryPayloadForEvent($event, $entry['group'])
                    : $this->emptyRegistryPayload(),
                'newsletters'       => array_map(
                    fn(Newsletter $nl): array => $this->newsletterSummaryPayload($nl),
                    $newsletters
                ),
            ];
        }

        return new WP_REST_Response([
            'success'       => true,
            'dashboard_url' => $result->dashboardUrl,
            'events'        => $events,
        ], 200);
    }
}
