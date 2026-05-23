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
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\QrCode;

/**
 * Generates, stores, and retrieves QR codes for invitation groups.
 *
 * QR code PNGs are saved to {wp_upload_dir}/eim-qr-codes/group_{group_id}.png
 * and tracked in the eim_qr_codes database table.
 *
 * Each QR code encodes a URL of the form: {home_url}/?eim_confirmation={16-char-code}
 */
final class QrCodeService
{
    /** @var string Upload sub-directory where QR PNG files are stored. */
    private const QR_SUBDIR       = 'eim-qr-codes';

    /** @var int Width and height in pixels of the generated QR code PNG. */
    private const QR_SIZE         = 300;

    /** @var int Quiet-zone margin in pixels around the QR code module grid. */
    private const QR_MARGIN       = 10;

    /** @var int Number of characters in each random confirmation code. */
    private const QR_CODE_LENGTH  = 16;

    /** @var int Maximum generation attempts before giving up on uniqueness. */
    private const MAX_CODE_ATTEMPTS = 10;

    /**
     * Returns the existing QR code for the invitation group, or generates a new one.
     *
     * @param Event           $event
     * @param InvitationGroup $group
     * @return QrCode|null Null when the PNG could not be written or the DB insert failed.
     */
    public function getOrCreateForGroup(Event $event, InvitationGroup $group): ?QrCode
    {
        $existing = QrCode::findForGroup($group->id);

        if ($existing !== null) {
            if (file_exists($existing->absolutePath())) {
                return $existing;
            }

            QrCode::deleteForGroup($group->id);
        }

        return $this->generateForGroup($event->id, $group->id);
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
     * Returns the same confirmation URL encoded into the QR code PNG.
     *
     * @param QrCode $qrCode
     * @return string
     */
    public function inviteUrl(QrCode $qrCode): string
    {
        return $this->buildInviteUrl($qrCode->confirmationCode);
    }

    /**
     * Generates a new QR code PNG for the given group, stores it on disk, and
     * creates the corresponding eim_qr_codes database record.
     *
     * @param int $eventId
     * @param int $groupId
     * @return QrCode|null Null when the PNG cannot be written or the DB insert fails.
     */
    private function generateForGroup(int $eventId, int $groupId): ?QrCode
    {
        $code    = $this->generateCode();
        $url     = $this->buildInviteUrl($code);
        $upload  = wp_upload_dir();
        $dir     = $upload['basedir'] . '/' . self::QR_SUBDIR;
        $file    = 'group_' . $groupId . '.png';
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

        return QrCode::create($eventId, $groupId, $code, $relPath);
    }

    /**
     * Generates a cryptographically random alphanumeric confirmation code that
     * does not already exist in the eim_qr_codes table.
     *
     * Retries up to MAX_CODE_ATTEMPTS times. A collision is astronomically
     * unlikely (62^16 ≈ 4.7 × 10^28 possibilities), so this is purely a
     * belt-and-suspenders guard.
     *
     * @return string 16-character string composed of A-Z, a-z, 0-9.
     * @throws \RuntimeException If a unique code cannot be generated after MAX_CODE_ATTEMPTS tries.
     */
    private function generateCode(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $charsLength = strlen($chars);

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::QR_CODE_LENGTH; $i++) {
                $code .= $chars[random_int(0, $charsLength - 1)];
            }

            if (QrCode::findByCode($code) === null) {
                return $code;
            }
        }

        throw new \RuntimeException(
            'Failed to generate a unique QR confirmation code after ' . self::MAX_CODE_ATTEMPTS . ' attempts.'
        );
    }

    /**
     * Constructs the confirmation URL that is encoded into the QR code PNG.
     *
     * @param string $code The 16-character confirmation code.
     * @return string Full URL of the form {home_url}/?eim_confirmation={code}.
     */
    private function buildInviteUrl(string $code): string
    {
        return home_url('/') . '?eim_confirmation=' . $code;
    }
}
