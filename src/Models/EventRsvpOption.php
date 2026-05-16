<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

// Backwards-compatibility shim. All plugin code has been updated to use
// MenuItem directly. This alias lets any lingering reference to
// EventRsvpOption resolve to the same class without extending or
// duplicating logic, and preserves correct class identity on instanceof
// checks and static factory returns.
if (!class_exists(__NAMESPACE__ . '\\EventRsvpOption', false)) {
    class_alias(MenuItem::class, __NAMESPACE__ . '\\EventRsvpOption');
}
