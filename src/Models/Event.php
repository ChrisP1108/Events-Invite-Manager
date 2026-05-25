<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\EventLodging;
use EventsInviteManager\Models\MenuItem;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\QrCode;

/**
 * Represents a single event and provides static CRUD methods against the database.
 *
 * Instances are created by the static factory methods (find, all) and are
 * intentionally not writable after creation — use the static update() method
 * to persist changes.
 */
final class Event
{
    /**
     * @param int     $id                     Primary key.
     * @param string  $name                   Event name.
     * @param string  $description            Optional free-text description.
     * @param ?int    $rsvpPageId             WordPress page ID for the front-end RSVP page, or null if not set.
     * @param ?int    $venueId                FK to eim_locations for the event venue, or null if not set.
     * @param string  $fromName               Display name used in the From header of outgoing emails.
     * @param string  $fromEmail              Email address used in the From header of outgoing emails.
     * @param string  $inviteEmailSubject     Subject line for the invite email.
     * @param string  $inviteEmailTemplate    HTML body template for the invite email.
     * @param ?string $startDatetime          MySQL DATETIME string (Y-m-d H:i:s) for the event start, or null.
     * @param ?string $endDatetime            MySQL DATETIME string (Y-m-d H:i:s) for the event end, or null.
     * @param string  $timezone               IANA timezone identifier (e.g. "America/New_York"), or empty string.
     * @param bool    $lodgingEnabled         Whether lodging options are offered for this event.
     * @param bool    $foodOptionsEnabled     Whether food menu options are enabled for this event.
     * @param bool    $beverageOptionsEnabled Whether beverage menu options are enabled for this event.
     * @param ?int    $newsletterPageId       WordPress page ID for the post-RSVP newsletter page, or null if not set.
     * @param ?int    $dashboardPageId        WordPress page ID for the invitee dashboard redirect after RSVP, or null if not set.
     * @param ?int    $maxInvitees            Maximum number of invitees allowed, or null for unlimited.
     * @param string  $createdAt              MySQL datetime string.
     * @param string  $updatedAt              MySQL datetime string.
     */
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $description,
        public readonly ?int    $rsvpPageId,
        public readonly ?int    $venueId,
        public readonly string  $fromName,
        public readonly string  $fromEmail,
        public readonly string  $inviteEmailSubject,
        public readonly string  $inviteEmailTemplate,
        public readonly ?string $startDatetime,
        public readonly ?string $endDatetime,
        public readonly string  $timezone,
        public readonly bool    $lodgingEnabled,
        public readonly bool    $foodOptionsEnabled,
        public readonly bool    $beverageOptionsEnabled,
        public readonly ?int    $newsletterPageId,
        public readonly ?int    $dashboardPageId,
        public readonly ?int    $maxInvitees,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    /**
     * Returns events for the admin list table with optional search, sort, and field filter.
     *
     * DB-sortable: name, start_datetime (date), invitee_count.
     *
     * @return self[]
     */
    public static function listForAdmin(
        string $search = '',
        string $sort   = 'start_datetime',
        string $order  = 'desc',
        string $field  = ''
    ): array {
        global $wpdb;

        $table    = DatabaseManager::eventsTable();
        $orderSql = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $dbSortMap = [
            'name'           => 'name',
            'start_datetime' => 'start_datetime',
            'date'           => 'start_datetime',
        ];
        $sortCol = $dbSortMap[$sort] ?? 'start_datetime';

        if ($search === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY {$sortCol} IS NULL ASC, {$sortCol} {$orderSql}, name ASC"
            );
            return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
        }

        $like = '%' . $wpdb->esc_like(strtolower($search)) . '%';

        if ($field === 'name') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE LOWER(name) LIKE %s ORDER BY {$sortCol} IS NULL ASC, {$sortCol} {$orderSql}, name ASC",
                    $like
                )
            );
        } elseif ($field === 'description') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE LOWER(description) LIKE %s ORDER BY {$sortCol} IS NULL ASC, {$sortCol} {$orderSql}, name ASC",
                    $like
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE LOWER(name) LIKE %s OR LOWER(COALESCE(description,'')) LIKE %s ORDER BY {$sortCol} IS NULL ASC, {$sortCol} {$orderSql}, name ASC",
                    $like, $like
                )
            );
        }

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns all events ordered alphabetically by name.
     *
     * @return self[]
     */
    public static function all(): array
    {
        global $wpdb;

        $table = DatabaseManager::eventsTable();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Finds a single event by its primary key.
     *
     * @param int $id
     * @return self|null Null when no event with that ID exists.
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::eventsTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Inserts a new event and returns its auto-increment ID, or false on failure.
     *
     * @param array<string, mixed> $data Associative array of column values.
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::eventsTable(), [
            'name'                  => $data['name']                 ?? '',
            'description'           => $data['description']          ?? '',
            'from_name'             => $data['from_name']            ?? '',
            'from_email'            => $data['from_email']           ?? '',
            'invite_email_subject'  => $data['invite_email_subject'] ?? '',
            'invite_email_template' => $data['invite_email_template'] ?? '',
            'rsvp_page_id'          => isset($data['rsvp_page_id']) && (int) $data['rsvp_page_id'] > 0 ? (int) $data['rsvp_page_id'] : null,
            'venue_id'              => isset($data['venue_id']) && (int) $data['venue_id'] > 0 ? (int) $data['venue_id'] : null,
            'start_datetime'        => !empty($data['start_datetime']) ? $data['start_datetime'] : null,
            'end_datetime'          => !empty($data['end_datetime'])   ? $data['end_datetime']   : null,
            'timezone'              => $data['timezone'] ?? '',
            'lodging_enabled'          => isset($data['lodging_enabled']) ? (int) $data['lodging_enabled'] : 0,
            'food_options_enabled'     => isset($data['food_options_enabled']) ? (int) $data['food_options_enabled'] : 0,
            'beverage_options_enabled' => isset($data['beverage_options_enabled']) ? (int) $data['beverage_options_enabled'] : 0,
            'newsletter_page_id'       => isset($data['newsletter_page_id']) && (int) $data['newsletter_page_id'] > 0 ? (int) $data['newsletter_page_id'] : null,
            'dashboard_page_id'        => isset($data['dashboard_page_id']) && (int) $data['dashboard_page_id'] > 0 ? (int) $data['dashboard_page_id'] : null,
            'max_invitees'             => isset($data['max_invitees']) && $data['max_invitees'] > 0 ? (int) $data['max_invitees'] : null,
        ]);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Updates an existing event row. Only the keys present in $data are overwritten.
     *
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return bool False if the query failed; true otherwise (including no-op updates).
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::eventsTable(),
            [
                'name'                  => $data['name']                 ?? '',
                'description'           => $data['description']          ?? '',
                'from_name'             => $data['from_name']            ?? '',
                'from_email'            => $data['from_email']           ?? '',
                'invite_email_subject'  => $data['invite_email_subject'] ?? '',
                'invite_email_template' => $data['invite_email_template'] ?? '',
                'rsvp_page_id'          => isset($data['rsvp_page_id']) && (int) $data['rsvp_page_id'] > 0 ? (int) $data['rsvp_page_id'] : null,
                'venue_id'              => isset($data['venue_id']) && (int) $data['venue_id'] > 0 ? (int) $data['venue_id'] : null,
                'start_datetime'        => !empty($data['start_datetime']) ? $data['start_datetime'] : null,
                'end_datetime'          => !empty($data['end_datetime'])   ? $data['end_datetime']   : null,
                'timezone'              => $data['timezone'] ?? '',
                'lodging_enabled'          => isset($data['lodging_enabled']) ? (int) $data['lodging_enabled'] : 0,
                'food_options_enabled'     => isset($data['food_options_enabled']) ? (int) $data['food_options_enabled'] : 0,
                'beverage_options_enabled' => isset($data['beverage_options_enabled']) ? (int) $data['beverage_options_enabled'] : 0,
                'newsletter_page_id'       => isset($data['newsletter_page_id']) && (int) $data['newsletter_page_id'] > 0 ? (int) $data['newsletter_page_id'] : null,
                'dashboard_page_id'        => isset($data['dashboard_page_id']) && (int) $data['dashboard_page_id'] > 0 ? (int) $data['dashboard_page_id'] : null,
                'max_invitees'             => isset($data['max_invitees']) && $data['max_invitees'] > 0 ? (int) $data['max_invitees'] : null,
            ],
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Deletes an event and all of its invitation associations and lodging locations.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        QrCode::deleteForEvent($id);
        InvitationGroup::deleteForEvent($id);
        $wpdb->delete(DatabaseManager::eventInviteesTable(),    ['event_id' => $id]);
        EventLodging::deleteForEvent($id);
        MenuItem::deleteForEvent($id);
        Gift::deleteForEvent($id);
        $wpdb->delete(DatabaseManager::budgetPlanEventsTable(), ['event_id' => $id]);
        $wpdb->delete(DatabaseManager::budgetLineItemsTable(),  ['event_id' => $id]);
        $result = $wpdb->delete(DatabaseManager::eventsTable(), ['id' => $id]);

        return $result !== false;
    }

    /**
     * Returns the number of invitees for this event.
     *
     * @return int
     */
    public function inviteeCount(): int
    {
        global $wpdb;

        $table = DatabaseManager::eventInviteesTable();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $this->id));
    }

    /**
     * Returns the number of registered invitees for this event.
     *
     * @return int
     */
    public function registeredCount(): int
    {
        global $wpdb;

        $groupsTable  = DatabaseManager::invitationGroupsTable();
        $membersTable = DatabaseManager::invitationGroupMembersTable();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$membersTable} egm
                 INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                 WHERE eig.event_id = %d AND egm.rsvp_status = 'attending'",
                $this->id
            )
        );
    }

    /**
     * Returns all events that have a date set, ordered by date then name — used to
     * populate the calendar jump-to dropdown.
     *
     * @return self[]
     */
    public static function allByDate(): array
    {
        global $wpdb;

        $table = DatabaseManager::eventsTable();
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE start_datetime IS NOT NULL ORDER BY start_datetime ASC, name ASC"
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns events for a given month grouped by day-of-month, ordered by start time.
     *
     * start_datetime is stored in UTC. The query window is expanded by one day on each
     * side so that events near month boundaries are never missed due to timezone offsets.
     * After fetching, each event is converted to its own timezone and only those whose
     * local date falls within the requested month are included.
     *
     * Events without a date are excluded.
     *
     * @param int $year  Four-digit year.
     * @param int $month Month number (1–12).
     * @return array<int, self[]> Keys are day-of-month integers (1–31).
     */
    public static function byDayForMonth(int $year, int $month): array
    {
        global $wpdb;

        $table      = DatabaseManager::eventsTable();
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

        // Widen the UTC query by ±1 day to cover any timezone offset crossings.
        $queryStart = date('Y-m-d', strtotime($monthStart . ' -1 day'));
        $queryEnd   = date('Y-m-d', strtotime($monthEnd   . ' +1 day'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE DATE(start_datetime) BETWEEN %s AND %s ORDER BY start_datetime ASC, name ASC",
                $queryStart,
                $queryEnd
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $event = self::fromRow($row);

            if ($event->startDatetime === null) {
                continue;
            }

            $local = $event->utcToLocal($event->startDatetime);

            // Exclude events whose local date falls outside the requested month.
            if ((int) $local->format('Y') !== $year || (int) $local->format('n') !== $month) {
                continue;
            }

            $grouped[(int) $local->format('j')][] = $event;
        }

        return $grouped;
    }

    /**
     * Returns the start date formatted using the site's WordPress date format setting.
     *
     * The stored UTC datetime is converted to the event's timezone before formatting.
     * Returns an empty string when no start is set.
     *
     * @return string
     */
    public function formattedDate(): string
    {
        if (!$this->startDatetime) {
            return '';
        }

        return $this->utcToLocal($this->startDatetime)->format((string) get_option('date_format', 'M j, Y'));
    }

    /**
     * Returns a short time range for compact displays such as calendar cells.
     *
     * Same-day: "4:00 PM – 9:00 PM". Cross-day: "4:00 PM – Jan 16 9:00 PM".
     * Times are expressed in the event's own timezone. Returns an empty string when no start is set.
     *
     * @return string
     */
    public function formattedTimeRange(): string
    {
        if (!$this->startDatetime) {
            return '';
        }

        $start    = $this->utcToLocal($this->startDatetime);
        $startStr = $start->format('g:i A');

        if (!$this->endDatetime) {
            return $startStr;
        }

        $end     = $this->utcToLocal($this->endDatetime);
        $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');

        return $sameDay
            ? $startStr . ' – ' . $end->format('g:i A')
            : $startStr . ' – ' . $end->format('M j g:i A');
    }

    /**
     * Returns a full human-readable date/time range for list and detail displays.
     *
     * Same-day:   "Jan 15, 2025, 4:00 PM – 9:00 PM"
     * Cross-day:  "Jan 15, 2025, 4:00 PM – Jan 16, 2025, 9:00 PM"
     * No end:     "Jan 15, 2025, 4:00 PM"
     *
     * Times are expressed in the event's own timezone.
     *
     * @return string
     */
    public function formattedDateTimeRange(): string
    {
        if (!$this->startDatetime) {
            return '';
        }

        $dateFormat = (string) get_option('date_format', 'M j, Y');
        $start      = $this->utcToLocal($this->startDatetime);
        $startDate  = $start->format($dateFormat);
        $startTime  = $start->format('g:i A');

        if (!$this->endDatetime) {
            return $startDate . ', ' . $startTime;
        }

        $end     = $this->utcToLocal($this->endDatetime);
        $endTime = $end->format('g:i A');
        $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');

        return $sameDay
            ? $startDate . ', ' . $startTime . ' – ' . $endTime
            : $startDate . ', ' . $startTime . ' – ' . $end->format($dateFormat) . ', ' . $endTime;
    }

    /**
     * Converts a UTC MySQL datetime string to a \DateTime in the event's own timezone.
     *
     * Falls back to UTC when the event has no timezone set (e.g. legacy rows that were
     * saved before the timezone field existed).
     *
     * @param string $utcDatetime MySQL DATETIME string assumed to be UTC.
     * @return \DateTime
     */
    private function utcToLocal(string $utcDatetime): \DateTime
    {
        $dt = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));

        if ($this->timezone !== '') {
            try {
                $dt->setTimezone(new \DateTimeZone($this->timezone));
            } catch (\Throwable) {
                // Invalid timezone identifier — leave in UTC.
            }
        }

        return $dt;
    }

    /**
     * Hydrates an Event instance from a raw database row object.
     *
     * @param object $row
     * @return self
     */
    /**
     * Returns the public permalink of the newsletter page assigned to this event, or null if none.
     *
     * Returns null when no newsletter_page_id is set or get_permalink() cannot resolve the page.
     *
     * @return string|null
     */
    /**
     * Returns the public permalink of the newsletter page assigned to this event,
     * optionally appending the invitee's confirmation code as a query parameter
     * so the newsletter page can gate access.
     *
     * @param string $confirmationCode When non-empty, appended as ?eim_confirmation={code}.
     * @return string|null Null when no newsletter_page_id is configured.
     */
    public function newsletterUrl(string $confirmationCode = ''): ?string
    {
        if ($this->newsletterPageId === null || $this->newsletterPageId <= 0) {
            return null;
        }

        $url = get_permalink($this->newsletterPageId);

        if ($url === false || $url === '') {
            return null;
        }

        if ($confirmationCode !== '') {
            $url = add_query_arg('eim_confirmation', rawurlencode($confirmationCode), $url);
        }

        return $url;
    }

    /**
     * Returns the public permalink of the invitee dashboard page assigned to this event,
     * optionally appending the confirmation code so the dashboard can identify the group.
     *
     * @param string $confirmationCode When non-empty, appended as ?eim_confirmation={code}.
     * @return string|null Null when no dashboard_page_id is configured.
     */
    public function dashboardUrl(string $confirmationCode = ''): ?string
    {
        if ($this->dashboardPageId === null || $this->dashboardPageId <= 0) {
            return null;
        }

        $url = get_permalink($this->dashboardPageId);

        if ($url === false || $url === '') {
            return null;
        }

        if ($confirmationCode !== '') {
            $url = add_query_arg('eim_confirmation', rawurlencode($confirmationCode), $url);
        }

        return $url;
    }

    private static function fromRow(object $row): self
    {
        return new self(
            id:                  (int) $row->id,
            name:                      $row->name,
            description:               $row->description           ?? '',
            rsvpPageId:          isset($row->rsvp_page_id) && $row->rsvp_page_id !== null ? (int) $row->rsvp_page_id : null,
            venueId:             isset($row->venue_id)     && $row->venue_id     !== null ? (int) $row->venue_id     : null,
            fromName:                  $row->from_name             ?? '',
            fromEmail:                 $row->from_email            ?? '',
            inviteEmailSubject:        $row->invite_email_subject  ?? '',
            inviteEmailTemplate:       $row->invite_email_template ?? '',
            startDatetime:             $row->start_datetime        ?? null,
            endDatetime:               $row->end_datetime          ?? null,
            timezone:                  $row->timezone              ?? '',
            lodgingEnabled:          (bool) ($row->lodging_enabled           ?? false),
            foodOptionsEnabled:      (bool) ($row->food_options_enabled      ?? false),
            beverageOptionsEnabled:  (bool) ($row->beverage_options_enabled  ?? false),
            newsletterPageId:    isset($row->newsletter_page_id) && $row->newsletter_page_id !== null ? (int) $row->newsletter_page_id : null,
            dashboardPageId:     isset($row->dashboard_page_id) && $row->dashboard_page_id !== null ? (int) $row->dashboard_page_id : null,
            maxInvitees:              isset($row->max_invitees) && $row->max_invitees !== null ? (int) $row->max_invitees : null,
            createdAt:                 $row->created_at            ?? '',
            updatedAt:                 $row->updated_at            ?? '',
        );
    }
}
