<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\ConnectionGroup;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\Gift;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\Newsletter;
use EventsInviteManager\Models\QrCode;
use EventsInviteManager\Services\RsvpFlowResolver;
use EventsInviteManager\Services\RsvpFlowResult;
use WP_REST_Response;

abstract class AbstractApiController
{
    protected RsvpFlowResolver $resolver;

    public function __construct(?RsvpFlowResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new RsvpFlowResolver();
    }

    protected function validationErrorResponse(array $errors): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Please correct the highlighted fields.',
            'errors'  => $errors,
        ], 422);
    }

    protected function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolves the most appropriate connection group ID for an invitation group.
     *
     * When the primary invitee belongs to a single connection group that group is
     * returned immediately. When they belong to multiple, the method picks the one
     * whose member set is a superset of the invitation group members — i.e. the CG
     * the admin used when building the invitation. Falls back to alphabetically
     * first if no perfect superset match exists.
     */
    protected function resolveConnectionGroupId(InvitationGroup $group): ?int
    {
        $connectionGroups = ConnectionGroup::forInvitee($group->primaryInviteeId);

        if (empty($connectionGroups)) {
            return null;
        }

        if (count($connectionGroups) === 1) {
            return $connectionGroups[0]->id;
        }

        $invitationMemberIds = array_map(
            static fn(Invitee $m): int => $m->id,
            $group->getMembers()
        );

        foreach ($connectionGroups as $cg) {
            $cgMemberIds = array_map(
                static fn(Invitee $m): int => $m->id,
                $cg->getMembers()
            );
            if (empty(array_diff($invitationMemberIds, $cgMemberIds))) {
                return $cg->id;
            }
        }

        return $connectionGroups[0]->id;
    }

    /** @return array<string,mixed> */
    protected function inviteeImagePayload(Invitee $invitee): array
    {
        $thumbnailUrl = $invitee->imageUrl('thumbnail');
        $fullUrl      = $invitee->imageUrl('full');
        $imageUrl     = $invitee->imageUrl('medium');
        if ($imageUrl === '') {
            $imageUrl = $fullUrl !== '' ? $fullUrl : $thumbnailUrl;
        }

        return [
            'image_attachment_id' => $invitee->imageAttachmentId,
            'image_alt'           => $invitee->imageAttachmentId > 0 ? ($invitee->imageAltText() ?: $invitee->fullName()) : '',
            'image_thumbnail_url' => $thumbnailUrl,
            'image_url'           => $imageUrl,
            'image_full_url'      => $fullUrl,
        ];
    }

    protected function buildRsvpEditUrl(Event $event, string $code): ?string
    {
        if ($event->rsvpPageId === null || $event->rsvpPageId <= 0) {
            return null;
        }

        $url = get_permalink($event->rsvpPageId);

        if ($url === false || $url === '') {
            return null;
        }

        $url = add_query_arg('eim_confirmation', rawurlencode($code), $url);

        return add_query_arg('eim_edit', '1', $url);
    }

    /** @return array<string,mixed> */
    protected function dashboardEventPayload(Event $event): array
    {
        return [
            'name'           => $event->name,
            'description'    => $event->description,
            'date'           => $event->formattedDateTimeRange(),
            'start_datetime' => $event->startDatetime,
            'end_datetime'   => $event->endDatetime,
            'timezone'       => $event->timezone,
            'venue'          => $event->venueId ? $this->venuePayload($event->venueId) : null,
        ];
    }

    protected function venuePayload(int $venueId): ?array
    {
        $venue = Location::find($venueId);

        if ($venue === null) {
            return null;
        }

        return [
            'name'    => $venue->name,
            'address' => $venue->formattedAddress(),
        ];
    }

    /** @return array<string,mixed> */
    protected function rsvpSummaryPayload(RsvpFlowResult $result, string $code): array
    {
        if ($result->event === null || $result->group === null) {
            return [];
        }

        $event        = $result->event;
        $foodOptions  = $event->foodOptionsEnabled
            ? MenuItem::forEventByType($event->id, MenuItem::TYPE_FOOD)
            : [];
        $bevOptions   = $event->beverageOptionsEnabled
            ? MenuItem::forEventByType($event->id, MenuItem::TYPE_BEVERAGE)
            : [];
        $lodging      = $event->lodgingEnabled ? EventLodging::forEvent($event->id) : [];
        $foodById     = $this->menuOptionMap($foodOptions);
        $beverageById = $this->menuOptionMap($bevOptions);
        $lodgingById  = $this->lodgingOptionMap($lodging);

        $members = array_map(function (Invitee $member) use ($foodById, $beverageById, $lodgingById): array {
            $isAttending = $member->rsvpStatus === InvitationGroup::RSVP_ATTENDING;
            $fullName    = trim($member->firstName . ' ' . $member->lastName);

            return [
                'invitee_id'            => $member->id,
                'first_name'            => $member->firstName,
                'last_name'             => $member->lastName,
                'full_name'             => $fullName !== '' ? $fullName : $member->email,
                'email'                 => $member->email,
                'phone'                 => $member->phone,
                'street_address'        => $member->streetAddress,
                'city'                  => $member->city,
                'state'                 => $member->state,
                'zip_code'              => $member->zipCode,
                'rsvp_status'           => $member->rsvpStatus ?: InvitationGroup::RSVP_PENDING,
                'is_attending'          => $isAttending,
                'registered_at'         => $member->registeredAt,
                'food'                  => $isAttending ? $this->menuSelectionPayload($member->foodOptionId, $foodById) : null,
                'food_confirmed_at'     => $member->foodConfirmedAt,
                'beverage'              => $isAttending ? $this->menuSelectionPayload($member->beverageOptionId, $beverageById) : null,
                'beverage_confirmed_at' => $member->beverageConfirmedAt,
                'dietary_notes'         => $isAttending ? $member->dietaryNotes : '',
                'lodging'               => $isAttending ? $this->lodgingSelectionPayload($member, $lodgingById) : null,
                'lodging_confirmed_at'  => $member->lodgingConfirmedAt,
            ] + $this->inviteeImagePayload($member);
        }, $result->members);

        $acceptedMembers = array_values(array_filter(
            $members,
            static fn(array $member): bool => (bool) $member['is_attending']
        ));
        $groupLodging = null;
        foreach ($acceptedMembers as $member) {
            if ($member['lodging'] !== null) {
                $groupLodging = $member['lodging'];
                break;
            }
        }

        return [
            'event_id'              => $event->id,
            'invitation_group_id'   => $result->group->id,
            'edit_rsvp_url'         => $this->buildRsvpEditUrl($event, $code),
            'rsvp_notes'            => $result->group->rsvpNotes,
            'rsvp_notes_updated_at' => $result->group->rsvpNotesUpdatedAt,
            'lodging_booked'        => $result->group->lodgingBooked,
            'lodging_booked_at'     => $result->group->lodgingBookedAt,
            'lodging_notes'         => $result->group->lodgingNotes,
            'requires_lodging'      => $result->requiresLodging,
            'requires_food'         => $result->requiresFood,
            'requires_beverage'     => $result->requiresBeverage,
            'accepted_count'        => count($acceptedMembers),
            'accepted_members'      => $acceptedMembers,
            'group_lodging'         => $groupLodging,
            'members'               => $members,
        ];
    }

    /**
     * @param MenuItem[] $items
     * @return array<int,array<string,mixed>>
     */
    protected function menuOptionMap(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $map[$item->id] = $this->menuItemPayload($item);
        }

        return $map;
    }

    /** @return array<string,mixed> */
    protected function menuItemPayload(MenuItem $item): array
    {
        return [
            'id'          => $item->id,
            'type'        => $item->type,
            'label'       => $item->label,
            'description' => $item->description,
            'sort_order'  => $item->sortOrder,
        ];
    }

    /**
     * Returns the selected menu option, falling back to the global item if the
     * event assignment was later removed.
     *
     * @param array<int,array<string,mixed>> $optionsById
     */
    protected function menuSelectionPayload(?int $id, array $optionsById): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        if (isset($optionsById[$id])) {
            return $optionsById[$id];
        }

        $item = MenuItem::find($id);

        if ($item === null) {
            return [
                'id'          => $id,
                'type'        => null,
                'label'       => 'Unavailable option',
                'description' => '',
                'sort_order'  => 0,
            ];
        }

        $payload                 = $this->menuItemPayload($item);
        $payload['is_available'] = false;

        return $payload;
    }

    /**
     * @param EventLodging[] $lodging
     * @return array<int,array<string,mixed>>
     */
    protected function lodgingOptionMap(array $lodging): array
    {
        $map = [];

        foreach ($lodging as $option) {
            $map[$option->id] = $this->lodgingOptionPayload($option);
        }

        return $map;
    }

    /** @return array<string,mixed> */
    protected function lodgingOptionPayload(EventLodging $option): array
    {
        return [
            'type'        => 'lodging',
            'id'          => $option->id,
            'name'        => $option->name,
            'address'     => $option->formattedAddress(),
            'booking_url' => $option->bookingUrl,
            'is_other'    => $option->isOther,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lodgingById
     */
    protected function lodgingSelectionPayload(Invitee $member, array $lodgingById): ?array
    {
        if ($member->lodgingUndisclosed) {
            return [
                'type'  => 'undisclosed',
                'label' => 'Prefer not to disclose',
            ];
        }

        if ($member->lodgingIsOther) {
            return [
                'type'  => 'other',
                'label' => 'Other',
            ];
        }

        if ($member->lodgingId === null || $member->lodgingId <= 0) {
            return null;
        }

        if (isset($lodgingById[$member->lodgingId])) {
            return $lodgingById[$member->lodgingId];
        }

        return [
            'type'         => 'lodging',
            'id'           => $member->lodgingId,
            'name'         => 'Unavailable lodging option',
            'address'      => '',
            'booking_url'  => '',
            'is_other'     => false,
            'is_available' => false,
        ];
    }

    /** @return array<string,mixed> */
    protected function newsletterSummaryPayload(Newsletter $newsletter, ?Event $event = null): array
    {
        $plainContent = trim(wp_strip_all_tags($newsletter->content));

        $payload = [
            'id'           => $newsletter->id,
            'title'        => $newsletter->title,
            'excerpt'      => wp_trim_words($plainContent, 40, '...'),
            'publish_date' => $newsletter->publishDate,
        ];

        if ($event !== null) {
            $payload['event_id']   = $event->id;
            $payload['event_name'] = $event->name;
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    protected function newsletterDetailPayload(Newsletter $newsletter): array
    {
        return [
            'id'           => $newsletter->id,
            'title'        => $newsletter->title,
            'content'      => $newsletter->content,
            'publish_date' => $newsletter->publishDate,
        ];
    }

    /**
     * Builds the public registry payload for one event.
     *
     * @param InvitationGroup|null $viewerGroup Used only to flag whether it owns a purchase.
     * @return array<string,mixed>
     */
    protected function registryPayloadForEvent(Event $event, ?InvitationGroup $viewerGroup = null): array
    {
        $gifts       = Gift::forEvent($event->id, '', 'name', 'asc');
        $purchaseMap = Gift::purchaseDetailsForEvent($event->id);
        $items       = array_map(
            fn(Gift $gift): array => $this->giftRegistryItemPayload(
                $gift,
                $event->id,
                $purchaseMap[$gift->id] ?? null,
                $viewerGroup
            ),
            $gifts
        );

        $purchasedCount = count(array_filter(
            $items,
            static fn(array $item): bool => !empty($item['is_purchased'])
        ));

        return [
            'count'           => count($items),
            'purchased_count' => $purchasedCount,
            'available_count' => count($items) - $purchasedCount,
            'gifts'           => $items,
        ];
    }

    /** @return array<string,mixed> */
    protected function emptyRegistryPayload(): array
    {
        return [
            'count'           => 0,
            'purchased_count' => 0,
            'available_count' => 0,
            'gifts'           => [],
        ];
    }

    /**
     * @param array<string,mixed>|null $purchase
     * @return array<string,mixed>
     */
    protected function giftRegistryItemPayload(
        Gift $gift,
        int $eventId,
        ?array $purchase = null,
        ?InvitationGroup $viewerGroup = null
    ): array {
        $isPurchased       = !empty($purchase['is_purchased']);
        $ownerGroupId      = isset($purchase['purchased_by_group_id']) ? (int) $purchase['purchased_by_group_id'] : null;
        $ownedByViewer     = $viewerGroup !== null && $ownerGroupId !== null && $ownerGroupId === $viewerGroup->id;
        $imageThumbnailUrl = $gift->imageUrl('thumbnail');
        $imageFullUrl      = $gift->imageUrl('full');
        $imageUrl          = $gift->imageUrl('medium');
        if ($imageUrl === '') {
            $imageUrl = $imageFullUrl !== '' ? $imageFullUrl : $imageThumbnailUrl;
        }

        return [
            'id'                         => $gift->id,
            'event_id'                   => $eventId,
            'name'                       => $gift->name,
            'description'                => $gift->description,
            'price_cents'                => $gift->priceCents,
            'formatted_price'            => $gift->formattedPrice(),
            'website_url'                => $gift->websiteUrl,
            'image_attachment_id'        => $gift->imageAttachmentId,
            'image_alt'                  => $gift->imageAttachmentId > 0 ? ($gift->imageAltText() ?: $gift->name) : '',
            'image_thumbnail_url'        => $imageThumbnailUrl,
            'image_url'                  => $imageUrl,
            'image_full_url'             => $imageFullUrl,
            'is_purchased'               => $isPurchased,
            'purchased_at'               => $isPurchased ? ($purchase['purchased_at'] ?? null) : null,
            'purchased_by_current_group' => $ownedByViewer,
            'can_mark_purchased'         => !$isPurchased || $ownedByViewer,
            'can_unmark_purchased'       => $ownedByViewer,
        ];
    }

    /**
     * Returns upcoming dashboard entries accessible from a QR code.
     *
     * When $requireAttendingMembers is true (the default, used by newsletters and
     * registry), only events with at least one attending member are included.
     * Pass false from the dashboard to include every invited event — pending,
     * declined, or incomplete — so the frontend can show all invitations.
     *
     * When $requireCompleteFlow is true, only events where the RSVP flow is fully
     * complete (next_action === dashboard_redirect) are included.
     *
     * @return array<int,array{group:InvitationGroup,event:Event,code:string,flow:RsvpFlowResult}>
     */
    protected function registeredDashboardEntries(
        RsvpFlowResult $result,
        string $fallbackCode,
        bool $requireCompleteFlow = false,
        bool $requireAttendingMembers = true
    ): array {
        if ($result->group === null) {
            return [];
        }

        $groups       = InvitationGroup::forPrimaryInvitee($result->group->primaryInviteeId);
        $nowUtc       = current_time('mysql', true);
        $entries      = [];
        $seenGroupIds = [];

        foreach ($groups as $group) {
            if (isset($seenGroupIds[$group->id])) {
                continue;
            }
            $seenGroupIds[$group->id] = true;

            $code = $this->confirmationCodeForGroup($group, $result->group, $fallbackCode);
            if ($code === '') {
                continue;
            }

            $flow = $group->id === $result->group->id
                ? $result
                : $this->resolver->resolve($code);

            if (!$flow->success || $flow->event === null || $flow->group === null) {
                continue;
            }

            if ($requireAttendingMembers && !$this->hasAttendingMembers($flow->members)) {
                continue;
            }

            if (!$this->isUpcomingEvent($flow->event, $nowUtc)) {
                continue;
            }

            if ($requireCompleteFlow && $flow->nextAction !== RsvpFlowResult::ACTION_DASHBOARD_REDIRECT) {
                continue;
            }

            $entries[] = [
                'group' => $flow->group,
                'event' => $flow->event,
                'code'  => $code,
                'flow'  => $flow,
            ];
        }

        return $entries;
    }

    /**
     * @param array<int,array{group:InvitationGroup,event:Event,code:string,flow:RsvpFlowResult}> $entries
     * @return array{group:InvitationGroup,event:Event,code:string,flow:RsvpFlowResult}|null
     */
    protected function dashboardEntryForEvent(array $entries, int $eventId): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['event']->id === $eventId) {
                return $entry;
            }
        }

        return null;
    }

    protected function confirmationCodeForGroup(InvitationGroup $group, InvitationGroup $currentGroup, string $fallbackCode): string
    {
        if ($group->id === $currentGroup->id && $fallbackCode !== '') {
            return $fallbackCode;
        }

        $qrCode = QrCode::findForGroup($group->id);

        return $qrCode?->confirmationCode ?? '';
    }

    /**
     * Event datetimes are stored in UTC, so this compares against UTC WordPress time.
     */
    protected function isUpcomingEvent(Event $event, string $nowUtc): bool
    {
        $endDatetime = $event->endDatetime ?? $event->startDatetime ?? null;

        return $endDatetime === null || $endDatetime >= $nowUtc;
    }

    /** @param Invitee[] $members */
    protected function hasAttendingMembers(array $members): bool
    {
        foreach ($members as $member) {
            if ($member->rsvpStatus === InvitationGroup::RSVP_ATTENDING) {
                return true;
            }
        }

        return false;
    }
}
