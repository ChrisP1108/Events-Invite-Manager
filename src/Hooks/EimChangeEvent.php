<?php

declare(strict_types=1);

namespace EventsInviteManager\Hooks;

if (!defined('ABSPATH')) exit;

/**
 * Immutable value object passed to the 'eim_change' WordPress action hook.
 *
 * Usage — listening to all changes:
 *
 *   add_action('eim_change', function (EimChangeEvent $e): void {
 *       // $e->type        — entity type, e.g. EimChangeEvent::TYPE_EVENT
 *       // $e->change_type — one of ADDED, EDITED, DELETED
 *       // $e->data        — the model object (or array for deleted items)
 *   });
 *
 * Usage — filtering by type:
 *
 *   add_action('eim_change', function (EimChangeEvent $e): void {
 *       if ($e->type !== EimChangeEvent::TYPE_INVITEE) return;
 *       // handle invitee changes only
 *   });
 */
final class EimChangeEvent
{
    // ── Change type constants ────────────────────────────────────────────────

    public const ADDED   = 'added';
    public const EDITED  = 'edited';
    public const DELETED = 'deleted';

    // ── Entity type constants ────────────────────────────────────────────────

    public const TYPE_EVENT            = 'event';
    public const TYPE_INVITEE          = 'invitee';
    public const TYPE_REQUESTED_ADD_ON = 'requested_add_on';
    public const TYPE_MESSAGE          = 'message';
    public const TYPE_CONNECTION_GROUP = 'connection_group';
    public const TYPE_LOCATION         = 'location';
    public const TYPE_MENU_ITEM        = 'menu_item';
    public const TYPE_BUDGET_PLAN      = 'budget_plan';
    public const TYPE_BUDGET_LINE_ITEM = 'budget_line_item';
    public const TYPE_VENDOR           = 'vendor';
    public const TYPE_NEWSLETTER       = 'newsletter';
    public const TYPE_CATEGORY         = 'category';
    public const TYPE_GIFT             = 'gift';

    // ── Value object ─────────────────────────────────────────────────────────

    public function __construct(
        /** One of the TYPE_* constants above. */
        public readonly string $type,
        /** One of ADDED, EDITED, or DELETED. */
        public readonly string $change_type,
        /**
         * The affected entity. For ADDED and EDITED this is the model object
         * after the write. For DELETED this is the model object captured just
         * before deletion.
         */
        public readonly mixed $data,
    ) {}

    /**
     * Fires the 'eim_change' WordPress action with a new EimChangeEvent.
     *
     * Call this instead of do_action() directly to keep model code concise.
     */
    public static function dispatch(string $type, string $changeType, mixed $data): void
    {
        do_action('eim_change', new self($type, $changeType, $data));
    }
}
