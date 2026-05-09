<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a stored QR code record linking an event-invitee pair to a
 * 16-character confirmation code and the PNG file path on disk.
 *
 * New records store an uploads-relative path (e.g. eim-qr-codes/1_2.png).
 * Legacy records stored a plugin-relative path (assets/qr_codes/1_2.png);
 * imageUrl() and absolutePath() handle both transparently.
 */
final class QrCode
{
    /**
     * @param int    $id               Primary key.
     * @param int    $eventId          Associated event ID.
     * @param int    $inviteeId        Associated invitee ID.
     * @param string $confirmationCode Random 16-character alphanumeric code embedded in the QR URL.
     * @param string $qrCodePath       Uploads-relative path to the stored PNG (e.g. eim-qr-codes/1_2.png).
     * @param string $createdAt        MySQL datetime string.
     * @param string $updatedAt        MySQL datetime string.
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $eventId,
        public readonly int    $inviteeId,
        public readonly string $confirmationCode,
        public readonly string $qrCodePath,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Finds a QR code record by its confirmation code.
     *
     * @param string $code
     * @return self|null
     */
    public static function findByCode(string $code): ?self
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE confirmation_code = %s LIMIT 1", $code)
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Finds an existing QR code for a specific event-invitee pair.
     *
     * @param int $eventId
     * @param int $inviteeId
     * @return self|null
     */
    public static function findForEventInvitee(int $eventId, int $inviteeId): ?self
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND invitee_id = %d LIMIT 1",
                $eventId,
                $inviteeId
            )
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Inserts a new QR code record and returns the hydrated model, or null on failure.
     *
     * @param int    $eventId
     * @param int    $inviteeId
     * @param string $confirmationCode
     * @param string $qrCodePath       Plugin-relative path to the saved PNG file.
     * @return self|null
     */
    public static function create(int $eventId, int $inviteeId, string $confirmationCode, string $qrCodePath): ?self
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::qrCodesTable(), [
            'event_id'          => $eventId,
            'invitee_id'        => $inviteeId,
            'confirmation_code' => $confirmationCode,
            'qr_code_path'      => $qrCodePath,
        ]);

        return $result ? self::findByCode($confirmationCode) : null;
    }

    /**
     * Deletes QR code records for events whose end time (or start time when no end is
     * set) is earlier than the current WordPress local time, and removes their PNG files.
     *
     * Intended to be called by a daily WP-Cron job so the uploads directory and the
     * eim_qr_codes table stay lean after events have concluded. Events with no date set
     * are never touched.
     *
     * @return int Number of QR code records removed.
     */
    public static function cleanupForPastEvents(): int
    {
        global $wpdb;

        $qrTable     = DatabaseManager::qrCodesTable();
        $eventsTable = DatabaseManager::eventsTable();
        $now         = current_time('mysql', true); // UTC to match UTC-stored datetimes.

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT qr.*
                 FROM {$qrTable} qr
                 INNER JOIN {$eventsTable} e ON e.id = qr.event_id
                 WHERE (e.end_datetime IS NOT NULL AND e.end_datetime < %s)
                    OR (e.end_datetime IS NULL AND e.start_datetime IS NOT NULL AND e.start_datetime < %s)",
                $now,
                $now
            )
        );

        if (empty($rows)) {
            return 0;
        }

        $qrCodes = array_map(static fn(object $row) => self::fromRow($row), $rows);
        self::deleteFiles($qrCodes);

        $ids = implode(', ', array_map(static fn(self $qr) => $qr->id, $qrCodes));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are all cast to int above.
        $wpdb->query("DELETE FROM {$qrTable} WHERE id IN ({$ids})");

        return count($qrCodes);
    }

    /**
     * Deletes all QR code records for a given event and removes their PNG files.
     *
     * Called by Event::delete() before the event row is removed.
     *
     * @param int $eventId
     * @return void
     */
    public static function deleteForEvent(int $eventId): void
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %d", $eventId));
        self::deleteFiles(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []));
        $wpdb->delete($table, ['event_id' => $eventId]);
    }

    /**
     * Deletes all QR code records for a given invitee and removes their PNG files.
     *
     * Called by Invitee::delete() before the invitee row is removed.
     *
     * @param int $inviteeId
     * @return void
     */
    public static function deleteForInvitee(int $inviteeId): void
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE invitee_id = %d", $inviteeId));
        self::deleteFiles(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []));
        $wpdb->delete($table, ['invitee_id' => $inviteeId]);
    }

    /**
     * Deletes the QR code record for a specific event-invitee pair and removes its PNG.
     *
     * Called by Invitee::removeFromEvent() when an invitee is removed from an event.
     *
     * @param int $eventId
     * @param int $inviteeId
     * @return void
     */
    public static function deleteForEventInvitee(int $eventId, int $inviteeId): void
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND invitee_id = %d",
                $eventId,
                $inviteeId
            )
        );
        self::deleteFiles(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []));
        $wpdb->delete($table, ['event_id' => $eventId, 'invitee_id' => $inviteeId]);
    }

    /**
     * Returns the public URL to the stored QR code PNG.
     *
     * New records use an uploads-relative path; legacy records stored a
     * plugin-relative path beginning with "assets/". Both are handled here.
     *
     * @return string
     */
    public function imageUrl(): string
    {
        if (str_starts_with($this->qrCodePath, 'assets/')) {
            return EIM_PLUGIN_URL . $this->qrCodePath;
        }

        return wp_upload_dir()['baseurl'] . '/' . $this->qrCodePath;
    }

    /**
     * Returns the absolute server path to the stored QR code PNG.
     *
     * Used internally when files need to be unlinked on deletion.
     *
     * @return string
     */
    public function absolutePath(): string
    {
        if (str_starts_with($this->qrCodePath, 'assets/')) {
            return EIM_PLUGIN_DIR . $this->qrCodePath;
        }

        return wp_upload_dir()['basedir'] . '/' . $this->qrCodePath;
    }

    /**
     * Unlinks the PNG file for each of the supplied QR code records.
     *
     * Silently skips records whose file does not exist on disk (e.g. already removed).
     *
     * @param self[] $qrCodes
     * @return void
     */
    private static function deleteFiles(array $qrCodes): void
    {
        foreach ($qrCodes as $qrCode) {
            $path = $qrCode->absolutePath();

            if ($path !== '' && file_exists($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    /**
     * Hydrates a QrCode instance from a raw database row object.
     *
     * @param object $row
     * @return self
     */
    private static function fromRow(object $row): self
    {
        return new self(
            id:               (int) $row->id,
            eventId:          (int) $row->event_id,
            inviteeId:        (int) $row->invitee_id,
            confirmationCode:       $row->confirmation_code,
            qrCodePath:             $row->qr_code_path,
            createdAt:              $row->created_at ?? '',
            updatedAt:              $row->updated_at ?? '',
        );
    }
}
