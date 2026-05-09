<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Invitee;
use EventsInviteManager\Models\QrCode;

/**
 * Generates, stores, and retrieves QR codes for event invitees.
 *
 * QR code PNGs are saved to {wp_upload_dir}/eim-qr-codes/{event_id}_{invitee_id}.png
 * and tracked in the eim_qr_codes database table. Using the uploads directory avoids
 * data loss during plugin updates and ensures the web server can always write there.
 *
 * Each QR code encodes a URL of the form: {home_url}/?eim_confirmation={16-char-code}
 * The plugin intercepts that URL and redirects to the event's configured RSVP page,
 * preserving the confirmation code in the query string.
 */
final class QrCodeService
{
    /** Subdirectory name inside the WordPress uploads directory. */
    private const QR_SUBDIR = 'eim-qr-codes';

    /** Side length in pixels of the generated QR code PNG. */
    private const QR_SIZE = 300;

    /** Margin in pixels around the QR code modules. */
    private const QR_MARGIN = 10;

    /**
     * Returns the existing QR code for the event-invitee pair, or generates and stores
     * a new one if none exists yet.
     *
     * If a DB record exists but the PNG file has been removed from disk (e.g. by a manual
     * upload cleanup), the stale record is deleted and a fresh QR code is generated so
     * embed tags in invite emails always point to a real file.
     *
     * @param Event   $event
     * @param Invitee $invitee
     * @return QrCode|null Null when the PNG could not be written to disk or the DB insert failed.
     */
    public function getOrCreate(Event $event, Invitee $invitee): ?QrCode
    {
        $existing = QrCode::findForEventInvitee($event->id, $invitee->id);

        if ($existing !== null) {
            if (file_exists($existing->absolutePath())) {
                return $existing;
            }

            // File missing — drop the stale record and regenerate below.
            QrCode::deleteForEventInvitee($event->id, $invitee->id);
        }

        return $this->generate($event->id, $invitee->id);
    }

    /**
     * Returns an HTML <img> tag pointing to the stored QR code PNG.
     *
     * @param QrCode $qrCode
     * @return string
     */
    public function imgTag(QrCode $qrCode): string
    {
        return '<img src="' . esc_url($qrCode->imageUrl()) . '" alt="Scan to RSVP" width="' . self::QR_SIZE . '" height="' . self::QR_SIZE . '" style="display:block;">';
    }

    /**
     * Generates a QR code PNG, saves it to the uploads directory, and creates the DB record.
     *
     * @param int $eventId
     * @param int $inviteeId
     * @return QrCode|null
     */
    private function generate(int $eventId, int $inviteeId): ?QrCode
    {
        $code    = $this->generateCode();
        $url     = home_url('/') . '?eim_confirmation=' . $code;
        $upload  = wp_upload_dir();
        $dir     = $upload['basedir'] . '/' . self::QR_SUBDIR;
        $file    = $eventId . '_' . $inviteeId . '.png';
        $absPath = $dir . '/' . $file;
        $relPath = self::QR_SUBDIR . '/' . $file;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        try {
            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size(self::QR_SIZE)
                ->margin(self::QR_MARGIN)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();

            $result->saveToFile($absPath);
        } catch (\Throwable) {
            return null;
        }

        return QrCode::create($eventId, $inviteeId, $code, $relPath);
    }

    /**
     * Generates a cryptographically random 16-character alphanumeric confirmation code.
     *
     * @return string
     */
    private function generateCode(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $code  = '';

        for ($i = 0; $i < 16; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $code;
    }
}
