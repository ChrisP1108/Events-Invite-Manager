<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Newsletter;
use WP_REST_Request;
use WP_REST_Response;

class NewsletterController extends AbstractApiController
{
    /**
     * Handles GET /eim/v1/newsletters.
     *
     * Validates the confirmation code through the RSVP flow resolver. Newsletter
     * content is returned only when the flow is fully complete; this keeps
     * late-added lodging/menu steps in front of the newsletter page.
     */
    public function handleNewsletters(WP_REST_Request $request): WP_REST_Response
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
                    'message'     => 'Please complete the RSVP flow before viewing newsletters.',
                    'next_action' => $result->nextAction,
                ],
                403
            );
        }

        // Cross-event: gather upcoming registered events for this primary invitee.
        // Each event uses its own QR confirmation code so edit links and late-added
        // RSVP requirements are evaluated against the correct invitation group.
        $entries = $this->registeredDashboardEntries($result, $code, true);

        $newsletterId = (int) ($request->get_param('newsletter_id') ?? 0);

        if ($newsletterId > 0) {
            // Single-newsletter detail: search across all registered events.
            foreach ($entries as $entry) {
                $newsletter = Newsletter::findPublishedForEvent($entry['event']->id, $newsletterId);
                if ($newsletter !== null) {
                    return new WP_REST_Response([
                        'success'       => true,
                        'event_id'      => $entry['event']->id,
                        'invitation_group_id' => $entry['group']->id,
                        'edit_rsvp_url' => $this->buildRsvpEditUrl($entry['event'], $entry['code']),
                        'rsvp_summary'  => $this->rsvpSummaryPayload($entry['flow'], $entry['code']),
                        'newsletter'    => $this->newsletterDetailPayload($newsletter),
                    ], 200);
                }
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Newsletter not found.',
            ], 404);
        }

        // All newsletters across every registered event, grouped by event.
        $allNewsletters = [];
        $eventGroups    = [];
        foreach ($entries as $entry) {
            $newsletters = array_map(
                fn(Newsletter $nl): array => $this->newsletterSummaryPayload($nl, $entry['event']),
                Newsletter::publishedForEvent($entry['event']->id)
            );

            foreach ($newsletters as $newsletter) {
                $allNewsletters[] = $newsletter;
            }

            $eventGroups[] = [
                'event_id'      => $entry['event']->id,
                'invitation_group_id' => $entry['group']->id,
                'edit_rsvp_url' => $this->buildRsvpEditUrl($entry['event'], $entry['code']),
                'event'         => $this->dashboardEventPayload($entry['event']),
                'count'         => count($newsletters),
                'newsletters'   => $newsletters,
            ];
        }

        return new WP_REST_Response([
            'success'       => true,
            'edit_rsvp_url' => $this->buildRsvpEditUrl($result->event, $code),
            'rsvp_summary'  => $this->rsvpSummaryPayload($result, $code),
            'count'         => count($allNewsletters),
            'events'        => $eventGroups,
            'newsletters'   => $allNewsletters,
        ], 200);
    }
}
