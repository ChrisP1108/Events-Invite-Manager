<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Location;

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
     * @param int     $id                        Primary key.
     * @param string  $name                      Event name.
     * @param string  $description               Optional free-text description.
     * @param string  $rsvpPageUrl               URL of the front-end RSVP/registration page.
     * @param string  $fromName                  Display name used in the From header of outgoing emails.
     * @param string  $fromEmail                 Email address used in the From header of outgoing emails.
     * @param string  $inviteEmailSubject        Subject line for the invite email.
     * @param string  $inviteEmailTemplate       HTML body template for the invite email.
     * @param string  $confirmationEmailSubject  Subject line for the confirmation code email.
     * @param string  $confirmationEmailTemplate HTML body template for the confirmation code email.
     * @param ?string $eventDate                 MySQL DATE string (Y-m-d), or null if not set.
     * @param ?string $startTime                 MySQL TIME string (H:i:s), or null if not set.
     * @param ?string $endTime                   MySQL TIME string (H:i:s), or null if not set.
     * @param bool    $lodgingEnabled            Whether lodging options are offered for this event.
     * @param string  $createdAt                 MySQL datetime string.
     * @param string  $updatedAt                 MySQL datetime string.
     */
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $description,
        public readonly string  $rsvpPageUrl,
        public readonly string  $fromName,
        public readonly string  $fromEmail,
        public readonly string  $inviteEmailSubject,
        public readonly string  $inviteEmailTemplate,
        public readonly string  $confirmationEmailSubject,
        public readonly string  $confirmationEmailTemplate,
        public readonly ?string $eventDate,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly bool    $lodgingEnabled,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

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
            'name'                          => $data['name']                          ?? '',
            'description'                   => $data['description']                   ?? '',
            'rsvp_page_url'                 => $data['rsvp_page_url']                 ?? '',
            'from_name'                     => $data['from_name']                     ?? '',
            'from_email'                    => $data['from_email']                    ?? '',
            'invite_email_subject'          => $data['invite_email_subject']          ?? '',
            'invite_email_template'         => $data['invite_email_template']         ?? '',
            'confirmation_email_subject'    => $data['confirmation_email_subject']    ?? '',
            'confirmation_email_template'   => $data['confirmation_email_template']   ?? '',
            'event_date'                    => !empty($data['event_date'])  ? $data['event_date']  : null,
            'start_time'                    => !empty($data['start_time'])  ? $data['start_time']  : null,
            'end_time'                      => !empty($data['end_time'])    ? $data['end_time']    : null,
            'lodging_enabled'               => isset($data['lodging_enabled']) ? (int) $data['lodging_enabled'] : 0,
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
                'name'                          => $data['name']                          ?? '',
                'description'                   => $data['description']                   ?? '',
                'rsvp_page_url'                 => $data['rsvp_page_url']                 ?? '',
                'from_name'                     => $data['from_name']                     ?? '',
                'from_email'                    => $data['from_email']                    ?? '',
                'invite_email_subject'          => $data['invite_email_subject']          ?? '',
                'invite_email_template'         => $data['invite_email_template']         ?? '',
                'confirmation_email_subject'    => $data['confirmation_email_subject']    ?? '',
                'confirmation_email_template'   => $data['confirmation_email_template']   ?? '',
                'event_date'                    => !empty($data['event_date']) ? $data['event_date'] : null,
                'start_time'                    => !empty($data['start_time']) ? $data['start_time'] : null,
                'end_time'                      => !empty($data['end_time'])   ? $data['end_time']   : null,
                'lodging_enabled'               => isset($data['lodging_enabled']) ? (int) $data['lodging_enabled'] : 0,
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

        $wpdb->delete(DatabaseManager::eventInviteesTable(), ['event_id' => $id]);
        Location::deleteForEvent($id);
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

        $table = DatabaseManager::eventInviteesTable();

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND is_registered = 1", $this->id)
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
            "SELECT * FROM {$table} WHERE event_date IS NOT NULL ORDER BY event_date ASC, name ASC"
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Returns events for a given month grouped by day-of-month, ordered by start time.
     *
     * Used to populate calendar cells. Events without a date are excluded.
     *
     * @param int $year  Four-digit year.
     * @param int $month Month number (1–12).
     * @return array<int, self[]> Keys are day-of-month integers (1–31).
     */
    public static function byDayForMonth(int $year, int $month): array
    {
        global $wpdb;

        $table = DatabaseManager::eventsTable();
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_date BETWEEN %s AND %s ORDER BY start_time ASC, name ASC",
                $start,
                $end
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $day           = (int) date('j', strtotime($row->event_date));
            $grouped[$day][] = self::fromRow($row);
        }

        return $grouped;
    }

    /**
     * Returns the event date formatted using the site's WordPress date format setting.
     *
     * Returns an empty string when no date is set.
     *
     * @return string
     */
    public function formattedDate(): string
    {
        if (!$this->eventDate) {
            return '';
        }

        return date_i18n((string) get_option('date_format'), strtotime($this->eventDate));
    }

    /**
     * Returns the event time range formatted as "g:i A – g:i A", or just the start
     * time when no end time is set.
     *
     * Returns an empty string when no start time is set.
     *
     * @return string
     */
    public function formattedTimeRange(): string
    {
        if (!$this->startTime) {
            return '';
        }

        $start = date_i18n('g:i A', strtotime($this->startTime));

        return $this->endTime
            ? $start . ' – ' . date_i18n('g:i A', strtotime($this->endTime))
            : $start;
    }

    /**
     * Hydrates an Event instance from a raw database row object.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:                        (int) $row->id,
            name:                            $row->name,
            description:                     $row->description                   ?? '',
            rsvpPageUrl:                     $row->rsvp_page_url                 ?? '',
            fromName:                        $row->from_name                     ?? '',
            fromEmail:                       $row->from_email                    ?? '',
            inviteEmailSubject:              $row->invite_email_subject          ?? '',
            inviteEmailTemplate:             $row->invite_email_template         ?? '',
            confirmationEmailSubject:        $row->confirmation_email_subject    ?? '',
            confirmationEmailTemplate:       $row->confirmation_email_template   ?? '',
            eventDate:                       $row->event_date                    ?? null,
            startTime:                       $row->start_time                    ?? null,
            endTime:                         $row->end_time                      ?? null,
            lodgingEnabled:           (bool) ($row->lodging_enabled              ?? false),
            createdAt:                       $row->created_at                    ?? '',
            updatedAt:                       $row->updated_at                    ?? '',
        );
    }
}
