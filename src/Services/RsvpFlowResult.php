<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\Invitee;

/**
 * Immutable value object returned by RsvpFlowResolver::resolve().
 *
 * Encapsulates every piece of state the REST layer (or any other caller) needs
 * to decide what to show the invitee next: whether the code is valid, what
 * action is required, which menu types are needed, and where to redirect when
 * everything is done.
 *
 * The `nextAction` property is always one of the ACTION_* string constants
 * defined on this class.
 */
final class RsvpFlowResult
{
    /**
     * The confirmation code was not found in the database, or the group / event
     * it references no longer exists.
     */
    public const ACTION_INVALID_CODE = 'invalid_code';

    /**
     * At least one group member has not yet confirmed or declined their attendance.
     * The front-end should show the RSVP form.
     */
    public const ACTION_RSVP_REQUIRED = 'rsvp_required';

    /**
     * All group members have responded and at least one is attending, but the
     * lodging selection for the group has not yet been confirmed.
     * The front-end should show the lodging selection form.
     */
    public const ACTION_LODGING_REQUIRED = 'lodging_required';

    /**
     * All group members have responded and at least one is attending, but one or
     * more attending members have not yet completed their required menu selection.
     * The front-end should show the menu selection form.
     */
    public const ACTION_MENU_REQUIRED = 'menu_required';

    /**
     * All required steps are complete. The front-end should redirect the invitee
     * to the invitee dashboard page (see `dashboardUrl`).
     */
    public const ACTION_DASHBOARD_REDIRECT = 'dashboard_redirect';

    /**
     * Every group member has declined. No further action is needed.
     */
    public const ACTION_DECLINED = 'declined';

    /**
     * @param bool             $success             False only for hard errors (invalid code, missing data).
     * @param string           $nextAction          One of the ACTION_* constants indicating the next step.
     * @param Event|null       $event               Resolved event, or null on error.
     * @param InvitationGroup|null $group           Resolved invitation group, or null on error.
     * @param Invitee[]        $members             All members of the group (empty on error).
     * @param bool             $requiresLodging     True when the event has lodging enabled with at least one option.
     * @param bool             $requiresFood        True when the event has food options enabled with active items.
     * @param bool             $requiresBeverage    True when the event has beverage options enabled with active items.
     * @param string|null      $dashboardUrl        Public URL of the invitee dashboard page, or null if not configured.
     * @param string|null      $message             Human-readable error description, or null on success.
     * @param bool             $rsvpDeadlinePassed  True when rsvp_deadline is set and has already elapsed.
     */
    public function __construct(
        public readonly bool             $success,
        public readonly string           $nextAction,
        public readonly ?Event           $event,
        public readonly ?InvitationGroup $group,
        public readonly array            $members,
        public readonly bool             $requiresLodging,
        public readonly bool             $requiresFood,
        public readonly bool             $requiresBeverage,
        public readonly ?string          $dashboardUrl,
        public readonly ?string          $message,
        public readonly bool             $rsvpDeadlinePassed = false,
    ) {}

    /**
     * Returns true when the next action requires any kind of menu selection.
     *
     * Convenience method for callers that only need to know whether to show
     * a menu form without caring which specific types are needed.
     *
     * @return bool
     */
    public function needsLodgingSelection(): bool
    {
        return $this->nextAction === self::ACTION_LODGING_REQUIRED;
    }

    public function needsMenuSelection(): bool
    {
        return $this->nextAction === self::ACTION_MENU_REQUIRED;
    }

    /**
     * Returns true when the flow is fully complete and no further input is needed.
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->nextAction === self::ACTION_DASHBOARD_REDIRECT
            || $this->nextAction === self::ACTION_DECLINED;
    }
}
