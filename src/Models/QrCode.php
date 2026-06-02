<?php

declare(strict_types=1);

namespace EventsInviteManager\Models;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Database\DatabaseManager;

/**
 * Represents a stored QR code record linking an invitation group to a
 * 16-character confirmation code and the SVG file path on disk.
 *
 * File naming convention:
 * eim-qr-codes/event_{event_id}_group_{group_id}/event_{event_id}_group_{group_id}.svg
 *
 * The companion PNG lives beside the SVG with the same basename.
 */
final class QrCode
{
    /**
     * @param int    $id               Primary key.
     * @param int    $eventId          Associated event ID.
     * @param int    $groupId          Associated invitation group ID.
     * @param string $confirmationCode Random 16-character alphanumeric code embedded in the QR URL.
     * @param string $qrCodePath       Uploads-relative path to the stored SVG.
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
     * Returns a map of group_id → QrCode for all supplied group IDs in one query.
     *
     * Groups without a QR code are omitted from the returned array.
     *
     * @param int[] $groupIds
     * @return array<int, self>
     */
    public static function mapByGroupIds(array $groupIds): array
    {
        global $wpdb;

        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (empty($groupIds)) {
            return [];
        }

        $table        = DatabaseManager::qrCodesTable();
        $placeholders = implode(', ', array_fill(0, count($groupIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE group_id IN ({$placeholders})",
                ...$groupIds
            )
        );

        $map = [];
        foreach ($rows ?? [] as $row) {
            $qr                    = self::fromRow($row);
            $map[$qr->groupId] = $qr;
        }

        return $map;
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
     * Updates the stored SVG path for an existing QR code record.
     *
     * @param int    $id
     * @param string $qrCodePath Uploads-relative path to the stored SVG.
     * @return bool
     */
    public static function updatePath(int $id, string $qrCodePath): bool
    {
        global $wpdb;

        return $wpdb->update(
            DatabaseManager::qrCodesTable(),
            ['qr_code_path' => $qrCodePath],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Deletes QR code records for events whose end time has passed, and removes image files.
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
     * Deletes all QR code records for a given event and removes their image files.
     *
     * @param int $eventId
     * @return int Number of QR code records removed.
     */
    public static function deleteForEvent(int $eventId): int
    {
        global $wpdb;

        $table = DatabaseManager::qrCodesTable();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %d", $eventId));
        $qrCodes = array_map(static fn(object $r) => self::fromRow($r), $rows ?? []);

        if (empty($qrCodes)) {
            return 0;
        }

        self::deleteFiles($qrCodes);
        $wpdb->delete($table, ['event_id' => $eventId]);

        return count($qrCodes);
    }

    /**
     * Deletes the QR code record for a specific invitation group and removes its images.
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
     * Returns the public URL to the stored QR code SVG.
     *
     * @return string
     */
    public function imageUrl(): string
    {
        return $this->urlForRelativePath($this->svgRelativePath());
    }

    /**
     * Returns the public URL to the stored QR code SVG.
     *
     * @return string
     */
    public function svgUrl(): string
    {
        return $this->imageUrl();
    }

    /**
     * Returns the public URL to the companion QR code PNG.
     *
     * @return string
     */
    public function pngUrl(): string
    {
        return $this->urlForRelativePath($this->pngRelativePath());
    }

    /**
     * Returns the absolute server path to the stored QR code SVG.
     *
     * @return string
     */
    public function absolutePath(): string
    {
        return $this->absolutePathForRelativePath($this->svgRelativePath());
    }

    /**
     * Returns the absolute server path to the companion QR code PNG.
     *
     * @return string
     */
    public function pngAbsolutePath(): string
    {
        return $this->absolutePathForRelativePath($this->pngRelativePath());
    }

    /**
     * Returns the uploads-relative path to the stored QR code SVG.
     *
     * @return string
     */
    public function svgRelativePath(): string
    {
        return preg_replace('/\.(png|svg)$/i', '.svg', $this->qrCodePath) ?: $this->qrCodePath;
    }

    /**
     * Returns the uploads-relative path to the companion QR code PNG.
     *
     * @return string
     */
    public function pngRelativePath(): string
    {
        return preg_replace('/\.(png|svg)$/i', '.png', $this->qrCodePath) ?: $this->qrCodePath . '.png';
    }

    /**
     * Returns the absolute server path to the stored QR code SVG.
     *
     * @return string
     */
    private function absolutePathForRelativePath(string $relativePath): string
    {
        if (str_starts_with($relativePath, 'assets/')) {
            return EIM_PLUGIN_DIR . $relativePath;
        }

        return wp_upload_dir()['basedir'] . '/' . $relativePath;
    }

    /**
     * Returns the public URL to a QR code asset path.
     *
     * @return string
     */
    private function urlForRelativePath(string $relativePath): string
    {
        if (str_starts_with($relativePath, 'assets/')) {
            return EIM_PLUGIN_URL . $relativePath;
        }

        return wp_upload_dir()['baseurl'] . '/' . $relativePath;
    }

    private static function deleteFiles(array $qrCodes): void
    {
        foreach ($qrCodes as $qrCode) {
            $paths = array_unique([$qrCode->absolutePath(), $qrCode->pngAbsolutePath()]);

            foreach ($paths as $path) {
                if ($path !== '' && file_exists($path)) {
                    @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
            }

            $dir = dirname($qrCode->absolutePath());
            if ($dir !== '' && is_dir($dir)) {
                @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
