<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Models\Location;

/**
 * Represents a single lodging option assigned to an event.
 *
 * Each row in eim_event_lodging links an event to a global location (eim_locations)
 * that has has_lodging = 1. The properties from the joined Location row are denormalised
 * here so callers get a single flat object suitable for rendering.
 */
final class EventLodging
{
    /**
     * @param int    $id            Primary key of the eim_event_lodging row.
     * @param int    $eventId       Parent event ID.
     * @param int    $locationId    FK to eim_locations.
     * @param int    $sortOrder     Display order (ascending).
     * @param string $createdAt     MySQL datetime of row creation.
     * @param string $name          Location name (from JOIN).
     * @param string $streetAddress Location street address (from JOIN).
     * @param string $city          Location city (from JOIN).
     * @param string $state         Location state (from JOIN).
     * @param string $zipCode       Location ZIP code (from JOIN).
     * @param bool   $isOther       True for the "Other" option with no fixed address (from JOIN).
     * @param string $bookingUrl    Optional booking URL (from JOIN).
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $eventId,
        public readonly int    $locationId,
        public readonly int    $sortOrder,
        public readonly string $createdAt,
        public readonly string $name,
        public readonly string $streetAddress,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly bool   $isOther,
        public readonly string $bookingUrl,
    ) {}

    /**
     * Returns all lodging assignments for the given event, ordered by sort_order then name.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $el = DatabaseManager::eventLodgingTable();
        $loc = DatabaseManager::locationsTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT el.id, el.event_id, el.location_id, el.sort_order, el.created_at,
                        l.name, l.street_address, l.city, l.state, l.zip_code, l.is_other, l.booking_url
                 FROM {$el} el
                 INNER JOIN {$loc} l ON l.id = el.location_id
                 WHERE el.event_id = %d
                 ORDER BY el.sort_order ASC, l.name ASC",
                $eventId
            )
        );

        return array_map(static fn(object $row) => self::fromRow($row), $rows ?? []);
    }

    /**
     * Adds a location as a lodging option for an event.
     *
     * Returns false and does nothing if the location does not exist or does not have
     * has_lodging = 1, ensuring the constraint is enforced at the model layer regardless
     * of which code path calls this method.
     *
     * @param int $eventId
     * @param int $locationId
     * @param int $sortOrder
     * @return bool
     */
    public static function create(int $eventId, int $locationId, int $sortOrder = 0): bool
    {
        global $wpdb;

        $loc = Location::find($locationId);
        if ($loc === null || !$loc->hasLodging) {
            return false;
        }

        $result = $wpdb->insert(DatabaseManager::eventLodgingTable(), [
            'event_id'    => $eventId,
            'location_id' => $locationId,
            'sort_order'  => $sortOrder,
        ]);

        return $result !== false;
    }

    /**
     * Removes a lodging assignment by its primary key.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->delete(DatabaseManager::eventLodgingTable(), ['id' => $id]);

        return $result !== false;
    }

    /**
     * Removes all lodging assignments for an event. Called by Event::delete().
     *
     * @param int $eventId
     * @return void
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::eventLodgingTable(), ['event_id' => $eventId]);
    }

    /**
     * Returns a formatted single-line address, or an empty string for Other locations.
     *
     * @return string
     */
    public function formattedAddress(): string
    {
        if ($this->isOther) {
            return '';
        }

        return implode(', ', array_filter([
            $this->streetAddress,
            $this->city,
            $this->state,
            $this->zipCode,
        ]));
    }

    /**
     * Hydrates an EventLodging instance from a joined database row object.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:            (int)  $row->id,
            eventId:       (int)  $row->event_id,
            locationId:    (int)  $row->location_id,
            sortOrder:     (int)  $row->sort_order,
            createdAt:            $row->created_at     ?? '',
            name:                 $row->name,
            streetAddress:        $row->street_address ?? '',
            city:                 $row->city           ?? '',
            state:                $row->state          ?? '',
            zipCode:              $row->zip_code       ?? '',
            isOther:       (bool) ($row->is_other      ?? false),
            bookingUrl:           $row->booking_url    ?? '',
        );
    }
}
