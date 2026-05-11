<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a stored QR code record linking an invitation group to a
 * 16-character confirmation code and the PNG file path on disk.
 *
 * File naming convention: group_{group_id}.png
 */
final class QrCode
{
    /**
     * @param int    $id               Primary key.
     * @param int    $eventId          Associated event ID.
     * @param int    $groupId          Associated invitation group ID.
     * @param string $confirmationCode Random 16-character alphanumeric code embedded in the QR URL.
     * @param string $qrCodePath       Uploads-relative path to the stored PNG (e.g. eim-qr-codes/group_5.png).
     * @param string $createdAt        MySQL datetime string.
     * @param string $updatedAt        MySQL datetime string.
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $eventId,
        public readonly int    $groupId,
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
     * Finds an existing QR code for a specific invitation group.
     *
     * @param int $groupId
     * @return self|null
     */
    public static function findForGroup(int $groupId): ?self
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE group_id = %d LIMIT 1", $groupId)
        );

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Inserts a new QR code record and returns the hydrated model, or null on failure.
     *
     * @param int    $eventId
     * @param int    $groupId
     * @param string $confirmationCode
     * @param string $qrCodePath
     * @return self|null
     */
    public static function create(int $eventId, int $groupId, string $confirmationCode, string $qrCodePath): ?self
    {
        global $wpdb;

        $result = $wpdb->insert(DatabaseManager::qrCodesTable(), [
            'event_id'          => $eventId,
            'group_id'          => $groupId,
            'confirmation_code' => $confirmationCode,
            'qr_code_path'      => $qrCodePath,
        ]);

        return $result ? self::findByCode($confirmationCode) : null;
    }

    /**
     * Deletes QR code records for events whose end time has passed, and removes PNG files.
     *
     * @return int Number of QR code records removed.
     */
    public static function cleanupForPastEvents(): int
    {
        global $wpdb;

        $qrTable     = DatabaseManager::qrCodesTable();
        $eventsTable = DatabaseManager::eventsTable();
        $now         = current_time('mysql', true);

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
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DELETE FROM {$qrTable} WHERE id IN ({$ids})");

        return count($qrCodes);
    }

    /**
     * Deletes all QR code records for a given event and removes their PNG files.
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
     * Deletes the QR code record for a specific invitation group and removes its PNG.
     *
     * @param int $groupId
     * @return void
     */
    public static function deleteForGroup(int $groupId): void
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE group_id = %d", $groupId));
        self::deleteFiles(array_map(static fn(object $r) => self::fromRow($r), $rows ?? []));
        $wpdb->delete($table, ['group_id' => $groupId]);
    }

    /**
     * Returns the public URL to the stored QR code PNG.
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
     * @return string
     */
    public function absolutePath(): string
    {
        if (str_starts_with($this->qrCodePath, 'assets/')) {
            return EIM_PLUGIN_DIR . $this->qrCodePath;
        }

        return wp_upload_dir()['basedir'] . '/' . $this->qrCodePath;
    }

    private static function deleteFiles(array $qrCodes): void
    {
        foreach ($qrCodes as $qrCode) {
            $path = $qrCode->absolutePath();

            if ($path !== '' && file_exists($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    private static function fromRow(object $row): self
    {
        return new self(
            id:               (int) $row->id,
            eventId:          (int) $row->event_id,
            groupId:          (int) $row->group_id,
            confirmationCode:       $row->confirmation_code,
            qrCodePath:             $row->qr_code_path,
            createdAt:              $row->created_at ?? '',
            updatedAt:              $row->updated_at ?? '',
        );
    }
}
