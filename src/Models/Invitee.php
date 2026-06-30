<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

/**
 * Represents a single invitee and provides static CRUD methods against the database.
 *
 * Invitee profile details are global. Event-specific invitation state (RSVP status,
 * invite-sent timestamp, group membership) is hydrated onto instances returned by
 * forEvent(), findForEvent(), and findByEmailAndEvent() via JOINs through the
 * eim_event_invitation_groups and eim_event_invitation_group_members tables.
 */
final class Invitee
{
    /**
     * @param int     $id               Primary key.
     * @param int     $eventId          Event context ID (0 when loaded globally without an event).
     * @param string  $firstName        Invitee first name.
     * @param string  $lastName         Invitee last name.
     * @param string  $email            Invitee email address.
     * @param string  $phone            Invitee phone number.
     * @param string  $streetAddress    Street address.
     * @param string  $city             City.
     * @param string  $state            State or region.
     * @param string  $zipCode          Postal/ZIP code.
     * @param int     $imageAttachmentId WordPress media attachment ID for the invitee image.
     * @param string  $rsvpStatus       RSVP status for event context: 'pending' | 'attending' | 'declined' | '' for global.
     * @param bool    $isRegistered     True when rsvpStatus === 'attending'.
     * @param ?string $registeredAt     MySQL datetime when the invitee confirmed attendance, or null.
     * @param ?string $inviteSentAt     MySQL datetime when the invite email was sent, or null.
     * @param ?int    $groupId          Invitation group ID for the event context, or null.
     * @param ?int    $foodOptionId     Selected food menu item ID, or null.
     * @param ?int    $beverageOptionId Selected beverage menu item ID, or null.
     * @param string  $dietaryNotes     Free-text dietary notes.
     * @param ?string $foodConfirmedAt     MySQL datetime when the food selection was finalised, or null.
     * @param ?string $beverageConfirmedAt MySQL datetime when the beverage selection was finalised, or null.
     * @param ?int    $lodgingId           Selected lodging (EventLodging) ID, or null.
     * @param bool    $lodgingIsOther      True when the invitee chose the "Other" lodging option.
     * @param bool    $lodgingUndisclosed  True when the invitee chose not to disclose their lodging.
     * @param ?string $lodgingConfirmedAt  MySQL datetime when the lodging selection was confirmed, or null.
     * @param string  $createdAt           MySQL datetime of row creation.
     * @param string  $updatedAt           MySQL datetime of last update.
     */
    public function __construct(
        public readonly int     $id,
        public readonly int     $eventId,
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly string  $email,
        public readonly string  $phone,
        public readonly string  $streetAddress,
        public readonly string  $city,
        public readonly string  $state,
        public readonly string  $zipCode,
        public readonly int     $imageAttachmentId,
        public readonly string  $rsvpStatus,
        public readonly bool    $isRegistered,
        public readonly ?string $registeredAt,
        public readonly ?string $inviteSentAt,
        public readonly ?int    $groupId,
        public readonly ?int    $foodOptionId,
        public readonly ?int    $beverageOptionId,
        public readonly string  $dietaryNotes,
        public readonly ?string $foodConfirmedAt,
        public readonly ?string $beverageConfirmedAt,
        public readonly ?int    $lodgingId,
        public readonly bool    $lodgingIsOther,
        public readonly bool    $lodgingUndisclosed,
        public readonly ?string $lodgingConfirmedAt,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly string  $seatAssignment,
    ) {}

    // -------------------------------------------------------------------------
    // Event-scoped finders (hydrated with RSVP state from invitation groups)
    // -------------------------------------------------------------------------

    /**
     * Returns all invitees for the given event ordered by last name then first name.
     *
     * @param int $eventId
     * @return self[]
     */
    public static function forEvent(int $eventId): array
    {
        global $wpdb;

        $inviteesTable = DatabaseManager::inviteesTable();
        $eiTable       = DatabaseManager::eventInviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id            AS invitation_event_id,
                        gd.group_id            AS invitation_group_id,
                        gd.invite_sent_at      AS invitation_invite_sent_at,
                        COALESCE(gd.rsvp_status, 'pending') AS invitation_rsvp_status,
                        gd.registered_at       AS invitation_registered_at,
                        gd.food_option_id        AS invitation_food_option_id,
                        gd.beverage_option_id    AS invitation_beverage_option_id,
                        gd.dietary_notes         AS invitation_dietary_notes,
                        gd.food_confirmed_at     AS invitation_food_confirmed_at,
                        gd.beverage_confirmed_at AS invitation_beverage_confirmed_at,
                        gd.lodging_id            AS invitation_lodging_id,
                        gd.lodging_is_other      AS invitation_lodging_is_other,
                        gd.lodging_undisclosed   AS invitation_lodging_undisclosed,
                        gd.lodging_confirmed_at  AS invitation_lodging_confirmed_at
                 FROM {$eiTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 LEFT JOIN (
                     SELECT egm.invitee_id, egm.group_id, egm.rsvp_status,
                            egm.registered_at, eig.invite_sent_at,
                            egm.food_option_id, egm.beverage_option_id, egm.dietary_notes,
                            egm.food_confirmed_at, egm.beverage_confirmed_at,
                            egm.lodging_id, egm.lodging_is_other, egm.lodging_undisclosed, egm.lodging_confirmed_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                     WHERE eig.event_id = %d
                 ) gd ON gd.invitee_id = ei.invitee_id
                 WHERE ei.event_id = %d
                 ORDER BY i.last_name ASC, i.first_name ASC",
                $eventId,
                $eventId
            )
        );

        return array_map(static fn(object $row) => self::fromEventRow($row), $rows ?? []);
    }

    /**
     * Finds a single invitee by primary key (global profile, no event context).
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        global $wpdb;

        $table = DatabaseManager::inviteesTable();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));

        return $row ? self::fromPublicRow($row) : null;
    }

    /**
     * Finds an invitee by primary key with event-specific invitation state hydrated.
     *
     * @param int $id
     * @param int $eventId
     * @return self|null
     */
    public static function findForEvent(int $id, int $eventId): ?self
    {
        global $wpdb;

        $inviteesTable = DatabaseManager::inviteesTable();
        $eiTable       = DatabaseManager::eventInviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id            AS invitation_event_id,
                        gd.group_id            AS invitation_group_id,
                        gd.invite_sent_at      AS invitation_invite_sent_at,
                        COALESCE(gd.rsvp_status, 'pending') AS invitation_rsvp_status,
                        gd.registered_at       AS invitation_registered_at,
                        gd.food_option_id        AS invitation_food_option_id,
                        gd.beverage_option_id    AS invitation_beverage_option_id,
                        gd.dietary_notes         AS invitation_dietary_notes,
                        gd.food_confirmed_at     AS invitation_food_confirmed_at,
                        gd.beverage_confirmed_at AS invitation_beverage_confirmed_at,
                        gd.lodging_id            AS invitation_lodging_id,
                        gd.lodging_is_other      AS invitation_lodging_is_other,
                        gd.lodging_undisclosed   AS invitation_lodging_undisclosed,
                        gd.lodging_confirmed_at  AS invitation_lodging_confirmed_at
                 FROM {$eiTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 LEFT JOIN (
                     SELECT egm.invitee_id, egm.group_id, egm.rsvp_status,
                            egm.registered_at, eig.invite_sent_at,
                            egm.food_option_id, egm.beverage_option_id, egm.dietary_notes,
                            egm.food_confirmed_at, egm.beverage_confirmed_at,
                            egm.lodging_id, egm.lodging_is_other, egm.lodging_undisclosed, egm.lodging_confirmed_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                     WHERE eig.event_id = %d
                 ) gd ON gd.invitee_id = ei.invitee_id
                 WHERE ei.invitee_id = %d AND ei.event_id = %d
                 LIMIT 1",
                $eventId,
                $id,
                $eventId
            )
        );

        return $row ? self::fromEventRow($row) : null;
    }

    /**
     * Finds an invitee by email address and event ID with event context hydrated.
     *
     * @param string $email
     * @param int    $eventId
     * @return self|null
     */
    public static function findByEmailAndEvent(string $email, int $eventId): ?self
    {
        global $wpdb;

        $inviteesTable = DatabaseManager::inviteesTable();
        $eiTable       = DatabaseManager::eventInviteesTable();
        $groupsTable   = DatabaseManager::invitationGroupsTable();
        $membersTable  = DatabaseManager::invitationGroupMembersTable();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT i.*,
                        ei.event_id            AS invitation_event_id,
                        gd.group_id            AS invitation_group_id,
                        gd.invite_sent_at      AS invitation_invite_sent_at,
                        COALESCE(gd.rsvp_status, 'pending') AS invitation_rsvp_status,
                        gd.registered_at       AS invitation_registered_at,
                        gd.food_option_id        AS invitation_food_option_id,
                        gd.beverage_option_id    AS invitation_beverage_option_id,
                        gd.dietary_notes         AS invitation_dietary_notes,
                        gd.food_confirmed_at     AS invitation_food_confirmed_at,
                        gd.beverage_confirmed_at AS invitation_beverage_confirmed_at,
                        gd.lodging_id            AS invitation_lodging_id,
                        gd.lodging_is_other      AS invitation_lodging_is_other,
                        gd.lodging_undisclosed   AS invitation_lodging_undisclosed,
                        gd.lodging_confirmed_at  AS invitation_lodging_confirmed_at
                 FROM {$eiTable} ei
                 INNER JOIN {$inviteesTable} i ON i.id = ei.invitee_id
                 LEFT JOIN (
                     SELECT egm.invitee_id, egm.group_id, egm.rsvp_status,
                            egm.registered_at, eig.invite_sent_at,
                            egm.food_option_id, egm.beverage_option_id, egm.dietary_notes,
                            egm.food_confirmed_at, egm.beverage_confirmed_at,
                            egm.lodging_id, egm.lodging_is_other, egm.lodging_undisclosed, egm.lodging_confirmed_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                     WHERE eig.event_id = %d
                 ) gd ON gd.invitee_id = ei.invitee_id
                 WHERE i.email = %s AND ei.event_id = %d
                 ORDER BY i.id ASC
                 LIMIT 1",
                $eventId,
                strtolower(trim($email)),
                $eventId
            )
        );

        return $row ? self::fromEventRow($row) : null;
    }

    // -------------------------------------------------------------------------
    // Admin list
    // -------------------------------------------------------------------------

    /**
     * Returns invitees with their invited events for the global admin list.
     *
     * Search covers first/last name, email, phone, invited event names, and names
     * of people who share a connection group with the invitee.
     *
     * @param string $search
     * @param string $orderBy  first_name | last_name | email | phone | events
     * @param string $order    asc | desc
     * @param string $field    Restrict search to a single column; empty string searches all.
     * @return array<int, array{invitee: self, events: array<int, array<string, mixed>>}>
     */
    public static function listForAdmin(string $search = '', string $orderBy = 'last_name', string $order = 'asc', string $field = ''): array
    {
        global $wpdb;

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventsTable        = DatabaseManager::eventsTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $cgMembersTable     = DatabaseManager::inviteeConnectionGroupMembersTable();
        $cgGroupsTable      = DatabaseManager::inviteeConnectionGroupsTable();

        $eventSortSql = "(SELECT GROUP_CONCAT(e_sort.name ORDER BY e_sort.name ASC SEPARATOR ', ')
                          FROM {$eventInviteesTable} ei_sort
                          INNER JOIN {$eventsTable} e_sort ON e_sort.id = ei_sort.event_id
                          WHERE ei_sort.invitee_id = i.id)";

        $sortColumns = [
            'first_name' => 'i.first_name',
            'last_name'  => 'i.last_name',
            'email'      => 'i.email',
            'phone'      => 'i.phone',
            'events'     => $eventSortSql,
        ];

        // has_address is computed in PHP after the fetch; use last_name for the DB sort pass.
        $phpSort = $orderBy === 'has_address';
        $sortSql = $phpSort ? $sortColumns['last_name'] : ($sortColumns[$orderBy] ?? $sortColumns['last_name']);
        $dirSql  = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $params  = [];
        $where   = '';
        $search  = trim($search);

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';

            switch ($field) {
                case 'first_name':
                    $where  = "WHERE i.first_name LIKE %s";
                    $params = [$like];
                    break;
                case 'last_name':
                    $where  = "WHERE i.last_name LIKE %s";
                    $params = [$like];
                    break;
                case 'email':
                    $where  = "WHERE i.email LIKE %s";
                    $params = [$like];
                    break;
                case 'phone':
                    $where  = "WHERE i.phone LIKE %s";
                    $params = [$like];
                    break;
                case 'events':
                    $where  = "WHERE EXISTS (
                        SELECT 1
                        FROM {$eventInviteesTable} ei_s
                        INNER JOIN {$eventsTable} e_s ON e_s.id = ei_s.event_id
                        WHERE ei_s.invitee_id = i.id AND e_s.name LIKE %s
                    )";
                    $params = [$like];
                    break;
                case 'connection_groups':
                    $where  = "WHERE EXISTS (
                        SELECT 1
                        FROM {$cgMembersTable} cgm
                        INNER JOIN {$cgGroupsTable} cg ON cg.id = cgm.group_id
                        WHERE cgm.invitee_id = i.id AND cg.name LIKE %s
                    )";
                    $params = [$like];
                    break;
                default:
                    $where  = "WHERE (
                        i.first_name LIKE %s
                        OR i.last_name LIKE %s
                        OR i.email LIKE %s
                        OR i.phone LIKE %s
                        OR EXISTS (
                            SELECT 1
                            FROM {$eventInviteesTable} ei_s
                            INNER JOIN {$eventsTable} e_s ON e_s.id = ei_s.event_id
                            WHERE ei_s.invitee_id = i.id AND e_s.name LIKE %s
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM {$cgMembersTable} cgm1
                            INNER JOIN {$cgMembersTable} cgm2 ON cgm2.group_id = cgm1.group_id
                            INNER JOIN {$inviteesTable} ci ON ci.id = cgm1.invitee_id
                            WHERE cgm2.invitee_id = i.id
                              AND cgm1.invitee_id != i.id
                              AND (ci.first_name LIKE %s OR ci.last_name LIKE %s)
                        )
                    )";
                    $params = [$like, $like, $like, $like, $like, $like, $like];
            }
        }

        $sql = "SELECT i.*
                FROM {$inviteesTable} i
                {$where}
                ORDER BY {$sortSql} {$dirSql}, i.last_name ASC, i.first_name ASC, i.id ASC";

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $invitees = array_map(static fn(object $row) => self::fromPublicRow($row), $rows ?? []);

        if ($phpSort) {
            $desc = $dirSql === 'DESC';
            usort($invitees, static function (self $a, self $b) use ($desc): int {
                $aHas = (int) $a->hasAddress();
                $bHas = (int) $b->hasAddress();
                if ($aHas !== $bHas) {
                    return $desc ? $bHas - $aHas : $aHas - $bHas;
                }
                return strcasecmp($a->lastName, $b->lastName) ?: strcasecmp($a->firstName, $b->firstName);
            });
        }

        $inviteeIds      = array_map(static fn(self $inv) => $inv->id, $invitees);
        $eventsByInvitee = self::eventsForInvitees($inviteeIds);

        return array_map(
            static fn(self $invitee): array => [
                'invitee' => $invitee,
                'events'  => $eventsByInvitee[$invitee->id] ?? [],
            ],
            $invitees
        );
    }

    /**
     * Searches global invitees not yet invited to the given event.
     *
     * Also matches names of people in the same connection group.
     *
     * @param string $search
     * @param int    $eventId
     * @param int    $limit
     * @return self[]
     */
    public static function searchAvailableForEvent(string $search, int $eventId, int $limit = 20): array
    {
        global $wpdb;

        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }

        $inviteesTable      = DatabaseManager::inviteesTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $cgMembersTable     = DatabaseManager::inviteeConnectionGroupMembersTable();
        $like = '%' . $wpdb->esc_like($search) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*
                 FROM {$inviteesTable} i
                 WHERE (
                    i.first_name LIKE %s
                    OR i.last_name LIKE %s
                    OR i.email LIKE %s
                    OR i.phone LIKE %s
                    OR EXISTS (
                        SELECT 1
                        FROM {$cgMembersTable} cgm1
                        INNER JOIN {$cgMembersTable} cgm2 ON cgm2.group_id = cgm1.group_id
                        INNER JOIN {$inviteesTable} ci ON ci.id = cgm1.invitee_id
                        WHERE cgm2.invitee_id = i.id
                          AND cgm1.invitee_id != i.id
                          AND (ci.first_name LIKE %s OR ci.last_name LIKE %s)
                    )
                 )
                 AND NOT EXISTS (
                    SELECT 1 FROM {$eventInviteesTable} ei
                    WHERE ei.invitee_id = i.id AND ei.event_id = %d
                 )
                 ORDER BY i.last_name ASC, i.first_name ASC
                 LIMIT %d",
                $like, $like, $like, $like, $like, $like,
                $eventId,
                max(1, $limit)
            )
        );

        return array_map(static fn(object $row) => self::fromPublicRow($row), $rows ?? []);
    }

    /**
     * Returns the events an invitee has been invited to, ordered by event name.
     *
     * @param int $inviteeId
     * @return array<int, array<string, mixed>>
     */
    public static function eventsForInvitee(int $inviteeId): array
    {
        return self::eventsForInvitees([$inviteeId])[$inviteeId] ?? [];
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Inserts a new global invitee profile row.
     *
     * @param array<string, mixed> $data Must contain first_name, last_name, email.
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::inviteesTable(), [
            'first_name'     => $data['first_name']     ?? '',
            'last_name'      => $data['last_name']      ?? '',
            'email'          => strtolower(trim($data['email'] ?? '')),
            'phone'          => $data['phone']          ?? '',
            'street_address' => $data['street_address'] ?? '',
            'city'           => $data['city']           ?? '',
            'state'          => $data['state']          ?? '',
            'zip_code'       => $data['zip_code']       ?? '',
            'image_attachment_id' => (int) ($data['image_attachment_id'] ?? 0),
        ]);

        $id = $result ? (int) $wpdb->insert_id : false;
        if ($id !== false) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_INVITEE, EimChangeEvent::ADDED, self::find($id));
        }
        return $id;
    }

    /**
     * Updates mutable fields on an existing invitee profile row.
     *
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = array_filter([
            'first_name'     => $data['first_name']     ?? null,
            'last_name'      => $data['last_name']      ?? null,
            'email'          => isset($data['email']) ? strtolower(trim($data['email'])) : null,
            'phone'          => $data['phone']          ?? null,
            'street_address' => $data['street_address'] ?? null,
            'city'           => $data['city']           ?? null,
            'state'          => $data['state']          ?? null,
            'zip_code'       => $data['zip_code']       ?? null,
            'image_attachment_id' => array_key_exists('image_attachment_id', $data) ? (int) $data['image_attachment_id'] : null,
        ], static fn($v) => $v !== null);

        if (empty($fields)) {
            return true;
        }

        $result = $wpdb->update(DatabaseManager::inviteesTable(), $fields, ['id' => $id]);

        $ok = $result !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_INVITEE, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Adds an existing invitee to an event's membership table.
     *
     * Invitation group creation is handled separately by InvitationGroup::create().
     *
     * @param int $inviteeId
     * @param int $eventId
     * @return bool
     */
    public static function addToEvent(int $inviteeId, int $eventId): bool
    {
        global $wpdb;

        if ($inviteeId <= 0 || $eventId <= 0) {
            return false;
        }

        $table  = DatabaseManager::eventInviteesTable();
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND invitee_id = %d",
                $eventId,
                $inviteeId
            )
        );

        if ($exists > 0) {
            return true;
        }

        return $wpdb->insert($table, ['event_id' => $eventId, 'invitee_id' => $inviteeId]) !== false;
    }

    /**
     * Deletes an invitee profile, removes them from all groups, and cleans up event data.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $snapshot = self::find($id);

        ConnectionGroup::removeInviteeFromAllGroups($id);
        InvitationGroup::removeInviteeFromAllGroups($id);
        $wpdb->delete(DatabaseManager::eventInviteesTable(), ['invitee_id' => $id]);
        $result = $wpdb->delete(DatabaseManager::inviteesTable(), ['id' => $id]);

        $ok = $result !== false;
        if ($ok && $snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_INVITEE, EimChangeEvent::DELETED, $snapshot);
        }
        return $ok;
    }

    // -------------------------------------------------------------------------
    // Instance helpers
    // -------------------------------------------------------------------------

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    public function hasAddress(): bool
    {
        return $this->streetAddress !== ''
            && $this->city         !== ''
            && $this->state        !== ''
            && $this->zipCode      !== '';
    }

    public function formattedAddress(): string
    {
        return implode(', ', array_filter([
            $this->streetAddress,
            $this->city,
            $this->state,
            $this->zipCode,
        ]));
    }

    public function imageUrl(string $size = 'thumbnail'): string
    {
        if ($this->imageAttachmentId <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($this->imageAttachmentId, $size);
        return is_string($url) ? $url : '';
    }

    public function imageAltText(): string
    {
        if ($this->imageAttachmentId <= 0) {
            return '';
        }

        $alt = get_post_meta($this->imageAttachmentId, '_wp_attachment_image_alt', true);
        return is_string($alt) ? $alt : '';
    }

    // -------------------------------------------------------------------------
    // Row hydration (public so InvitationGroup and ConnectionGroup can use it)
    // -------------------------------------------------------------------------

    /**
     * Hydrates an Invitee from a raw DB row, optionally including invitation_* aliases.
     *
     * Used by InvitationGroup::loadMembersForGroups() and ConnectionGroup::loadMembersForGroups().
     *
     * @param object $row
     * @return self
     */
    public static function fromPublicRow(object $row): self
    {
        $rsvpStatus = $row->invitation_rsvp_status ?? '';

        return new self(
            id:                  (int)  $row->id,
            eventId:             (int)  ($row->invitation_event_id ?? 0),
            firstName:                  $row->first_name,
            lastName:                   $row->last_name,
            email:                      $row->email,
            phone:                      $row->phone           ?? '',
            streetAddress:              $row->street_address  ?? '',
            city:                       $row->city            ?? '',
            state:                      $row->state           ?? '',
            zipCode:                    $row->zip_code        ?? '',
            imageAttachmentId:    (int) ($row->image_attachment_id ?? 0),
            rsvpStatus:                 $rsvpStatus,
            isRegistered:               $rsvpStatus === InvitationGroup::RSVP_ATTENDING,
            registeredAt:               $row->invitation_registered_at      ?? $row->registered_at  ?? null,
            inviteSentAt:               $row->invitation_invite_sent_at     ?? null,
            groupId:                    isset($row->invitation_group_id)              ? (int) $row->invitation_group_id              : null,
            foodOptionId:               isset($row->invitation_food_option_id)        && $row->invitation_food_option_id        !== null ? (int) $row->invitation_food_option_id        : null,
            beverageOptionId:           isset($row->invitation_beverage_option_id)    && $row->invitation_beverage_option_id    !== null ? (int) $row->invitation_beverage_option_id    : null,
            dietaryNotes:               $row->invitation_dietary_notes ?? '',
            foodConfirmedAt:            $row->invitation_food_confirmed_at     ?? null,
            beverageConfirmedAt:        $row->invitation_beverage_confirmed_at ?? null,
            lodgingId:                  isset($row->invitation_lodging_id) && $row->invitation_lodging_id !== null ? (int) $row->invitation_lodging_id : null,
            lodgingIsOther:             (bool) ($row->invitation_lodging_is_other    ?? false),
            lodgingUndisclosed:         (bool) ($row->invitation_lodging_undisclosed ?? false),
            lodgingConfirmedAt:         $row->invitation_lodging_confirmed_at ?? null,
            createdAt:                  $row->created_at ?? '',
            updatedAt:                  $row->updated_at ?? '',
            seatAssignment:             $row->invitation_seat_assignment ?? '',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns invited events grouped by invitee ID.
     *
     * @param int[] $inviteeIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function eventsForInvitees(array $inviteeIds): array
    {
        global $wpdb;

        $inviteeIds = array_values(array_unique(array_filter(array_map('intval', $inviteeIds))));
        if (empty($inviteeIds)) {
            return [];
        }

        $eventsTable        = DatabaseManager::eventsTable();
        $eventInviteesTable = DatabaseManager::eventInviteesTable();
        $groupsTable        = DatabaseManager::invitationGroupsTable();
        $membersTable       = DatabaseManager::invitationGroupMembersTable();
        $menuItemsTable     = DatabaseManager::menuItemsTable();
        $placeholders = implode(', ', array_fill(0, count($inviteeIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ei.invitee_id, e.id, e.name,
                        rsvp.rsvp_status,
                        rsvp.registered_at,
                        rsvp.food_option_id,
                        food.label AS food_label,
                        rsvp.beverage_option_id,
                        beverage.label AS beverage_label,
                        rsvp.dietary_notes,
                        rsvp.food_confirmed_at,
                        rsvp.beverage_confirmed_at
                 FROM {$eventInviteesTable} ei
                 INNER JOIN {$eventsTable} e ON e.id = ei.event_id
                 LEFT JOIN (
                     SELECT eig.event_id, egm.invitee_id, egm.rsvp_status, egm.registered_at,
                            egm.food_option_id, egm.beverage_option_id, egm.dietary_notes,
                            egm.food_confirmed_at, egm.beverage_confirmed_at
                     FROM {$membersTable} egm
                     INNER JOIN {$groupsTable} eig ON eig.id = egm.group_id
                 ) rsvp ON rsvp.event_id = ei.event_id AND rsvp.invitee_id = ei.invitee_id
                 LEFT JOIN {$menuItemsTable} food ON food.id = rsvp.food_option_id
                 LEFT JOIN {$menuItemsTable} beverage ON beverage.id = rsvp.beverage_option_id
                 WHERE ei.invitee_id IN ({$placeholders})
                 ORDER BY e.name ASC",
                ...$inviteeIds
            )
        );

        $grouped = [];
        foreach ($rows ?? [] as $row) {
            $grouped[(int) $row->invitee_id][] = [
                'id'                      => (int) $row->id,
                'name'                    => (string) $row->name,
                'rsvp_status'             => (string) ($row->rsvp_status ?? InvitationGroup::RSVP_PENDING),
                'registered_at'           => $row->registered_at ?? null,
                'food_option_id'          => isset($row->food_option_id) && $row->food_option_id !== null ? (int) $row->food_option_id : null,
                'food_label'              => $row->food_label ?? '',
                'beverage_option_id'      => isset($row->beverage_option_id) && $row->beverage_option_id !== null ? (int) $row->beverage_option_id : null,
                'beverage_label'          => $row->beverage_label ?? '',
                'dietary_notes'           => $row->dietary_notes ?? '',
                'food_confirmed_at'       => $row->food_confirmed_at ?? null,
                'beverage_confirmed_at'   => $row->beverage_confirmed_at ?? null,
            ];
        }

        return $grouped;
    }

    /**
     * Hydrates an Invitee from a row that includes invitation_* alias columns.
     *
     * @param object $row
     * @return self
     */
    private static function fromEventRow(object $row): self
    {
        $rsvpStatus = $row->invitation_rsvp_status ?? InvitationGroup::RSVP_PENDING;

        return new self(
            id:                  (int)  $row->id,
            eventId:             (int)  ($row->invitation_event_id ?? 0),
            firstName:                  $row->first_name,
            lastName:                   $row->last_name,
            email:                      $row->email,
            phone:                      $row->phone           ?? '',
            streetAddress:              $row->street_address  ?? '',
            city:                       $row->city            ?? '',
            state:                      $row->state           ?? '',
            zipCode:                    $row->zip_code        ?? '',
            imageAttachmentId:    (int) ($row->image_attachment_id ?? 0),
            rsvpStatus:                 $rsvpStatus,
            isRegistered:               $rsvpStatus === InvitationGroup::RSVP_ATTENDING,
            registeredAt:               $row->invitation_registered_at      ?? null,
            inviteSentAt:               $row->invitation_invite_sent_at     ?? null,
            groupId:                    isset($row->invitation_group_id)              ? (int) $row->invitation_group_id              : null,
            foodOptionId:               isset($row->invitation_food_option_id)        && $row->invitation_food_option_id        !== null ? (int) $row->invitation_food_option_id        : null,
            beverageOptionId:           isset($row->invitation_beverage_option_id)    && $row->invitation_beverage_option_id    !== null ? (int) $row->invitation_beverage_option_id    : null,
            dietaryNotes:               $row->invitation_dietary_notes ?? '',
            foodConfirmedAt:            $row->invitation_food_confirmed_at     ?? null,
            beverageConfirmedAt:        $row->invitation_beverage_confirmed_at ?? null,
            lodgingId:                  isset($row->invitation_lodging_id) && $row->invitation_lodging_id !== null ? (int) $row->invitation_lodging_id : null,
            lodgingIsOther:             (bool) ($row->invitation_lodging_is_other    ?? false),
            lodgingUndisclosed:         (bool) ($row->invitation_lodging_undisclosed ?? false),
            lodgingConfirmedAt:         $row->invitation_lodging_confirmed_at ?? null,
            createdAt:                  $row->created_at ?? '',
            updatedAt:                  $row->updated_at ?? '',
            seatAssignment:             $row->invitation_seat_assignment ?? '',
        );
    }
}
