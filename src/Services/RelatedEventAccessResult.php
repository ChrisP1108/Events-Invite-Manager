<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;

/**
 * Immutable value object returned by RelatedEventAccessResolver::resolve().
 *
 * On success, bundles the specific Event/InvitationGroup pair a confirmation
 * code is authorized to act on — either the code's own event, or one of its
 * direct children. On failure, `event`/`group` are null and `reason` explains
 * why access was denied.
 */
final class RelatedEventAccessResult
{
    /** The confirmation code itself was not found. */
    public const REASON_INVALID_CODE = 'invalid_code';

    /** A target event_id was given but no such event exists. */
    public const REASON_EVENT_NOT_FOUND = 'event_not_found';

    /** The target event exists but is not a direct child of the code's own event. */
    public const REASON_NOT_A_CHILD = 'not_a_child';

    /**
     * The target is a legitimate child of the code's event, but no materialized
     * inherited group exists for it. This indicates a sync inconsistency and is
     * never self-healed from a read request — see RelatedEventAccessResolver.
     */
    public const REASON_SYNC_INCOMPLETE = 'sync_incomplete';

    public function __construct(
        public readonly bool             $success,
        public readonly ?Event           $event,
        public readonly ?InvitationGroup $group,
        public readonly string           $confirmationCode,
        public readonly ?string          $reason = null,
    ) {}
}
