<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;
use EventsInviteManager\Hooks\EimChangeEvent;

/**
 * Represents a frontend-submitted request to add a new person to a connection group.
 *
 * Requests are created from the front-end RSVP form and must be approved by an admin
 * before the person is actually added as an invitee. On approval a full Invitee record
 * is created, the person is added to the connection group, and if an invitation group
 * context is present the new member is auto-RSVP'd as attending.
 */
final class RequestedInviteeAddOn
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $connectionGroupId,
        public readonly string  $connectionGroupName,
        public readonly ?int    $eventId,
        public readonly ?string $eventName,
        public readonly ?int    $invitationGroupId,
        public readonly string  $firstName,
        public readonly string  $lastName,
        public readonly string  $email,
        public readonly string  $phone,
        public readonly string  $streetAddress,
        public readonly string  $city,
        public readonly string  $state,
        public readonly string  $zipCode,
        public readonly int     $imageAttachmentId,
        public readonly string  $notes,
        public readonly string  $status,
        public readonly ?int    $approvedInviteeId,
        public readonly ?string $reviewedAt,
        public readonly string  $createdAt,
    ) {}

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    // -------------------------------------------------------------------------
    // Static finders
    // -------------------------------------------------------------------------

    public static function find(int $id): ?self
    {
        global $wpdb;

        $table    = DatabaseManager::requestedInviteeAddOnsTable();
        $cgTable  = DatabaseManager::inviteeConnectionGroupsTable();
        $evTable  = DatabaseManager::eventsTable();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,
                    COALESCE(cg.name, '') AS connection_group_name,
                    e.name                AS event_name
             FROM {$table} r
             LEFT JOIN {$cgTable} cg ON cg.id = r.connection_group_id
             LEFT JOIN {$evTable} e  ON e.id  = r.event_id
             WHERE r.id = %d
             LIMIT 1",
            $id
        ));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Returns all requests for admin list display, optionally filtered/sorted.
     *
     * @param string $search      Text search query.
     * @param string $sort        Sort column key.
     * @param string $order       'asc' or 'desc'.
     * @param string $field       Specific column to restrict search to, or '' for any.
     * @return self[]
     */
    public static function listForAdmin(string $search, string $sort, string $order, string $field): array
    {
        global $wpdb;

        $table   = DatabaseManager::requestedInviteeAddOnsTable();
        $cgTable = DatabaseManager::inviteeConnectionGroupsTable();
        $evTable = DatabaseManager::eventsTable();

        $allowedSorts = ['first_name', 'last_name', 'email', 'phone', 'connection_group_name', 'event_name', 'status', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }
        $direction  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $sortColumn = match ($sort) {
            'connection_group_name' => 'cg.name',
            'event_name'            => 'e.name',
            default                 => "r.{$sort}",
        };

        $where = '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = match ($field) {
                'first_name'       => $wpdb->prepare("WHERE r.first_name LIKE %s", $like),
                'last_name'        => $wpdb->prepare("WHERE r.last_name LIKE %s", $like),
                'email'            => $wpdb->prepare("WHERE r.email LIKE %s", $like),
                'phone'            => $wpdb->prepare("WHERE r.phone LIKE %s", $like),
                'connection_group' => $wpdb->prepare("WHERE cg.name LIKE %s", $like),
                'event'            => $wpdb->prepare("WHERE e.name LIKE %s", $like),
                'status'           => $wpdb->prepare("WHERE r.status LIKE %s", $like),
                default            => $wpdb->prepare(
                    "WHERE r.first_name LIKE %s OR r.last_name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s OR cg.name LIKE %s OR e.name LIKE %s",
                    $like, $like, $like, $like, $like, $like
                ),
            };
        }

        $rows = $wpdb->get_results(
            "SELECT r.*, COALESCE(cg.name, '') AS connection_group_name, e.name AS event_name
             FROM {$table} r
             LEFT JOIN {$cgTable} cg ON cg.id = r.connection_group_id
             LEFT JOIN {$evTable} e  ON e.id  = r.event_id
             {$where}
             ORDER BY {$sortColumn} {$direction}"
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    /**
     * Returns requests for a specific event, optionally filtered/sorted.
     *
     * Used by the per-event section on the event edit screen.
     *
     * @param int    $eventId
     * @param string $search
     * @param string $sort
     * @param string $order
     * @param string $field
     * @return self[]
     */
    public static function listForEvent(int $eventId, string $search, string $sort, string $order, string $field): array
    {
        global $wpdb;

        $table   = DatabaseManager::requestedInviteeAddOnsTable();
        $cgTable = DatabaseManager::inviteeConnectionGroupsTable();

        $allowedSorts = ['first_name', 'last_name', 'email', 'phone', 'connection_group_name', 'status', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }
        $direction  = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $sortColumn = $sort === 'connection_group_name' ? 'cg.name' : "r.{$sort}";

        $baseWhere = $wpdb->prepare("WHERE r.event_id = %d", $eventId);

        if ($search !== '') {
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $extra = match ($field) {
                'first_name'       => $wpdb->prepare("AND r.first_name LIKE %s", $like),
                'last_name'        => $wpdb->prepare("AND r.last_name LIKE %s", $like),
                'email'            => $wpdb->prepare("AND r.email LIKE %s", $like),
                'phone'            => $wpdb->prepare("AND r.phone LIKE %s", $like),
                'connection_group' => $wpdb->prepare("AND cg.name LIKE %s", $like),
                'status'           => $wpdb->prepare("AND r.status LIKE %s", $like),
                default            => $wpdb->prepare(
                    "AND (r.first_name LIKE %s OR r.last_name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s OR cg.name LIKE %s)",
                    $like, $like, $like, $like, $like
                ),
            };
            $baseWhere .= ' ' . $extra;
        }

        $rows = $wpdb->get_results(
            "SELECT r.*, COALESCE(cg.name, '') AS connection_group_name, NULL AS event_name
             FROM {$table} r
             LEFT JOIN {$cgTable} cg ON cg.id = r.connection_group_id
             {$baseWhere}
             ORDER BY {$sortColumn} {$direction}"
        );

        return array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Creates a new add-on request (called from the REST API on frontend submission).
     *
     * @param array<string,mixed> $data
     * @return int|false New request ID, or false on failure.
     */
    public static function create(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::requestedInviteeAddOnsTable(), [
            'connection_group_id' => (int) ($data['connection_group_id'] ?? 0),
            'event_id'            => isset($data['event_id']) ? (int) $data['event_id'] : null,
            'invitation_group_id' => isset($data['invitation_group_id']) ? (int) $data['invitation_group_id'] : null,
            'first_name'          => (string) ($data['first_name'] ?? ''),
            'last_name'           => (string) ($data['last_name'] ?? ''),
            'email'               => strtolower(trim((string) ($data['email'] ?? ''))),
            'phone'               => (string) ($data['phone'] ?? ''),
            'street_address'      => (string) ($data['street_address'] ?? ''),
            'city'                => (string) ($data['city'] ?? ''),
            'state'               => (string) ($data['state'] ?? ''),
            'zip_code'            => (string) ($data['zip_code'] ?? ''),
            'image_attachment_id' => (int) ($data['image_attachment_id'] ?? 0),
            'notes'               => (string) ($data['notes'] ?? ''),
            'status'              => 'pending',
        ]);

        $id = $result ? (int) $wpdb->insert_id : false;
        if ($id !== false) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_REQUESTED_ADD_ON, EimChangeEvent::ADDED, self::find($id));
        }
        return $id;
    }

    /**
     * Updates mutable fields on an existing request (admin edits typos before approving).
     *
     * @param int                  $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $fields = [];
        foreach (['first_name', 'last_name', 'phone', 'street_address', 'city', 'state', 'zip_code', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('email', $data)) {
            $fields['email'] = strtolower(trim((string) $data['email']));
        }
        if (array_key_exists('image_attachment_id', $data)) {
            $fields['image_attachment_id'] = (int) $data['image_attachment_id'];
        }

        if (empty($fields)) {
            return true;
        }

        $result = $wpdb->update(DatabaseManager::requestedInviteeAddOnsTable(), $fields, ['id' => $id]);
        $ok = $result !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_REQUESTED_ADD_ON, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Approves a request: creates the invitee, adds them to the connection group,
     * optionally adds to event and auto-RSVPs via the invitation group.
     *
     * @param int $id Request ID.
     * @return array{success:bool, invitee_id?:int, error?:string}
     */
    public static function approve(int $id): array
    {
        global $wpdb;

        $request = self::find($id);
        if (!$request) {
            return ['success' => false, 'error' => 'not_found'];
        }

        // Only pending requests may be approved.
        if ($request->status !== 'pending') {
            return ['success' => false, 'error' => $request->status === 'approved' ? 'already_approved' : 'already_reviewed'];
        }

        // Wrap all writes in a transaction so a mid-sequence failure leaves no
        // partial state (e.g. invitee created but not added to the invitation group).
        // Both the request row and the capacity count are locked with FOR UPDATE so
        // two concurrent admin approvals cannot both pass their checks before either commits.
        $wpdb->query('START TRANSACTION');

        // Lock the request row so a second concurrent approval is forced to wait.
        // If the row is no longer 'pending' by the time we acquire the lock (because
        // the first approval already committed), bail out before writing anything.
        $riarTable  = DatabaseManager::requestedInviteeAddOnsTable();
        $lockedId   = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$riarTable} WHERE id = %d AND status = 'pending' FOR UPDATE",
                $id
            )
        );
        if ($lockedId === null) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'already_reviewed'];
        }

        // Re-check capacity inside the transaction so concurrent approvals are serialised.
        if ($request->eventId) {
            $event = Event::find($request->eventId);
            if ($event && $event->maxInvitees !== null) {
                $inviteeTable = DatabaseManager::eventInviteesTable();
                $currentCount = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$inviteeTable} WHERE event_id = %d FOR UPDATE",
                        $request->eventId
                    )
                );
                if ($currentCount >= $event->maxInvitees) {
                    $wpdb->query('ROLLBACK');
                    return ['success' => false, 'error' => 'event_full'];
                }
            }
        }

        // Reuse existing invitee if one with this email already exists.
        $inviteeTable = DatabaseManager::inviteesTable();
        $existingId   = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$inviteeTable} WHERE email = %s LIMIT 1", $request->email)
        );

        if ($existingId > 0) {
            $inviteeId = $existingId;
        } else {
            $inviteeId = Invitee::create([
                'first_name'          => $request->firstName,
                'last_name'           => $request->lastName,
                'email'               => $request->email,
                'phone'               => $request->phone,
                'street_address'      => $request->streetAddress,
                'city'                => $request->city,
                'state'               => $request->state,
                'zip_code'            => $request->zipCode,
                'image_attachment_id' => $request->imageAttachmentId,
            ]);

            if (!$inviteeId) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'error' => 'create_failed'];
            }
        }

        // Add to connection group (INSERT IGNORE handles duplicates).
        if (!ConnectionGroup::addMember($request->connectionGroupId, $inviteeId)) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'connection_group_add_failed'];
        }

        // If a specific event context was saved, add to event invitees.
        if ($request->eventId && !Invitee::addToEvent($inviteeId, $request->eventId)) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'event_add_failed'];
        }

        // If an invitation group was saved, auto-RSVP as attending.
        if ($request->invitationGroupId) {
            // Append at the end of the group's order — this invitee was just
            // created moments ago, so there's no pre-planned connection-group
            // position to inherit.
            $sortOrder = InvitationGroup::nextMemberSortOrder((int) $request->invitationGroupId);

            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . DatabaseManager::invitationGroupMembersTable() . "
                 (group_id, invitee_id, rsvp_status, registered_at, sort_order)
                 VALUES (%d, %d, 'attending', %s, %d)",
                $request->invitationGroupId,
                $inviteeId,
                current_time('mysql'),
                $sortOrder
            ));

            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'error' => 'invitation_group_add_failed'];
            }
        }

        // Mark request approved.
        $updated = $wpdb->update(
            DatabaseManager::requestedInviteeAddOnsTable(),
            [
                'status'              => 'approved',
                'approved_invitee_id' => $inviteeId,
                'reviewed_at'         => current_time('mysql'),
            ],
            ['id' => $id]
        );

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => 'update_failed'];
        }

        $wpdb->query('COMMIT');

        EimChangeEvent::dispatch(EimChangeEvent::TYPE_REQUESTED_ADD_ON, EimChangeEvent::EDITED, self::find($id));

        return ['success' => true, 'invitee_id' => $inviteeId];
    }

    /**
     * Marks a request as denied without removing it from the list.
     *
     * @param int $id
     * @return bool
     */
    public static function deny(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            DatabaseManager::requestedInviteeAddOnsTable(),
            ['status' => 'denied', 'reviewed_at' => current_time('mysql')],
            ['id' => $id]
        );

        $ok = $result !== false;
        if ($ok) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_REQUESTED_ADD_ON, EimChangeEvent::EDITED, self::find($id));
        }
        return $ok;
    }

    /**
     * Hard-deletes a request row.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $snapshot = self::find($id);
        $result   = $wpdb->delete(DatabaseManager::requestedInviteeAddOnsTable(), ['id' => $id]);
        $ok       = $result !== false;
        if ($ok && $snapshot !== null) {
            EimChangeEvent::dispatch(EimChangeEvent::TYPE_REQUESTED_ADD_ON, EimChangeEvent::DELETED, $snapshot);
        }
        return $ok;
    }

    /**
     * Deletes all add-on requests for a given event (called when deleting an event).
     *
     * @param int $eventId
     * @return void
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;
        $wpdb->delete(DatabaseManager::requestedInviteeAddOnsTable(), ['event_id' => $eventId], ['%d']);
    }

    /**
     * Creates the table if it does not already exist.
     *
     * Called at the top of RequestedInviteesPage::renderPage() as a safety guard
     * against silent dbDelta failures on upgrade.
     *
     * @return void
     */
    public static function maybeCreateTable(): void
    {
        global $wpdb;

        $table = DatabaseManager::requestedInviteeAddOnsTable();
        if ((string) $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_group_id  BIGINT(20) UNSIGNED NOT NULL,
            event_id             BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            invitation_group_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            first_name           VARCHAR(100)        NOT NULL,
            last_name            VARCHAR(100)        NOT NULL,
            email                VARCHAR(255)        NOT NULL,
            phone                VARCHAR(40)         NOT NULL DEFAULT '',
            street_address       VARCHAR(255)        NOT NULL DEFAULT '',
            city                 VARCHAR(100)        NOT NULL DEFAULT '',
            state                VARCHAR(50)         NOT NULL DEFAULT '',
            zip_code             VARCHAR(20)         NOT NULL DEFAULT '',
            image_attachment_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            notes                TEXT,
            status               VARCHAR(10)         NOT NULL DEFAULT 'pending',
            approved_invitee_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            reviewed_at          DATETIME            NULL DEFAULT NULL,
            created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY connection_group_id (connection_group_id),
            KEY event_id (event_id),
            KEY invitation_group_id (invitation_group_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function fromRow(object $row): self
    {
        return new self(
            id:                  (int) $row->id,
            connectionGroupId:   (int) $row->connection_group_id,
            connectionGroupName: (string) ($row->connection_group_name ?? ''),
            eventId:             isset($row->event_id) && $row->event_id !== null ? (int) $row->event_id : null,
            eventName:           isset($row->event_name) && $row->event_name !== null ? (string) $row->event_name : null,
            invitationGroupId:   isset($row->invitation_group_id) && $row->invitation_group_id !== null ? (int) $row->invitation_group_id : null,
            firstName:           (string) $row->first_name,
            lastName:            (string) $row->last_name,
            email:               (string) $row->email,
            phone:               (string) $row->phone,
            streetAddress:       (string) $row->street_address,
            city:                (string) $row->city,
            state:               (string) $row->state,
            zipCode:             (string) $row->zip_code,
            imageAttachmentId:   (int) $row->image_attachment_id,
            notes:               (string) ($row->notes ?? ''),
            status:              (string) $row->status,
            approvedInviteeId:   isset($row->approved_invitee_id) && $row->approved_invitee_id !== null ? (int) $row->approved_invitee_id : null,
            reviewedAt:          isset($row->reviewed_at) ? (string) $row->reviewed_at : null,
            createdAt:           (string) $row->created_at,
        );
    }
}
