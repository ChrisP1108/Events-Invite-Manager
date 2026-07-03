<?php

declare(strict_types=1);

namespace EventsInviteManager\Services;

if (!defined('ABSPATH')) exit;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\InvitationGroup;
use EventsInviteManager\Models\QrCode;

/**
 * Generates, stores, and retrieves QR codes for invitation groups.
 *
 * QR code images are saved to:
 * {wp_upload_dir}/eim-qr-codes/event_{event_id}_group_{group_id}/
 *
 * Each folder contains matching SVG and PNG files for the same confirmation
 * code. The database tracks the SVG path; email embeds use the PNG companion.
 *
 * Each QR code encodes a URL of the form: {home_url}/?eim_confirmation={16-char-code}
 */
final class QrCodeService
{
    /** @var string Upload sub-directory where QR code files are stored. */
    private const QR_SUBDIR       = 'eim-qr-codes';

    /** @var int Width and height in pixels of the generated QR code PNG. */
    private const QR_PNG_SIZE     = 1024;

    /** @var int Width and height in pixels used for the invite email QR image tag. */
    private const QR_EMAIL_DISPLAY_SIZE = 480;

    /** @var int Number of characters in each random confirmation code. */
    private const QR_CODE_LENGTH  = 16;

    /** @var int Maximum generation attempts before giving up on uniqueness. */
    private const MAX_CODE_ATTEMPTS = 10;

    /**
     * Returns the existing QR code for the invitation group, or generates a new one.
     *
     * @param Event           $event
     * @param InvitationGroup $group
     * @return QrCode|null Null when the image files could not be written or the DB insert failed.
     */
    public function getOrCreateForGroup(Event $event, InvitationGroup $group): ?QrCode
    {
        $existing = QrCode::findForGroup($group->id);

        if ($existing !== null) {
            if ($this->hasCompleteImageSet($existing)) {
                return $existing;
            }

            // The DB record (and confirmation code) exist but files are missing
            // or still use the legacy flat layout. Regenerate with the same code
            // so printed cards and sent emails remain valid.
            return $this->regenerateImageForGroup($existing);
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
        $img = '<img src="' . esc_url($qrCode->pngUrl()) . '" alt="Scan to RSVP" width="' . self::QR_EMAIL_DISPLAY_SIZE . '" height="' . self::QR_EMAIL_DISPLAY_SIZE . '" style="display:block;">';
        $url = esc_url($this->buildInviteUrl($qrCode->confirmationCode));

        return '<a href="' . $url . '" target="_blank" style="display:block;">' . $img . '</a>';
    }

    /**
     * Returns the same confirmation URL encoded into the QR code images.
     *
     * @param QrCode $qrCode
     * @return string
     */
    public function inviteUrl(QrCode $qrCode): string
    {
        return $this->buildInviteUrl($qrCode->confirmationCode);
    }

    /**
     * Generates new QR code images for the given group, stores them on disk, and
     * creates the corresponding eim_qr_codes database record.
     *
     * @param int $eventId
     * @param int $groupId
     * @return QrCode|null Null when files cannot be written or the DB insert fails.
     */
    private function generateForGroup(int $eventId, int $groupId): ?QrCode
    {
        $code    = $this->generateCode();
        $url     = $this->buildInviteUrl($code);
        $paths   = $this->pathsFor($eventId, $groupId);
        $dir     = $paths['dir'];
        $absPath = $paths['svg_abs'];

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        try {
            $result = Builder::create()
                ->writer(new SvgWriter())
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->foregroundColor(new Color(0, 0, 0))
                ->backgroundColor(new Color(0, 0, 0, 127))
                ->margin(0)
                ->build();

            $svg = $result->getString();

            $dom = new \DOMDocument();

            libxml_use_internal_errors(true);

            if (!$dom->loadXML($svg)) {
                libxml_clear_errors();
                $this->deleteGeneratedFiles($paths);
                return null;
            }

            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);

            // Remove width/height so the SVG scales via CSS.
            $svgEl = $dom->documentElement;
            if ($svgEl instanceof \DOMElement) {
                $svgEl->removeAttribute('width');
                $svgEl->removeAttribute('height');
            }

            // Remove direct <rect> children of the root <svg>.
            foreach ($xpath->query('/*[local-name()="svg"]/*[local-name()="rect"]') as $rect) {
                $rect->parentNode?->removeChild($rect);
            }

            // Make the QR <path> inherit its fill color.
            foreach ($xpath->query('/*[local-name()="svg"]/*[local-name()="path"]') as $path) {
                if ($path instanceof \DOMElement) {
                    $path->setAttribute('fill', 'inherit');
                    $path->removeAttribute('fill-opacity');
                }
            }

            $cleanSvg = $dom->saveXML($dom->documentElement);

            if ($cleanSvg === false) {
                $this->deleteGeneratedFiles($paths);
                return null;
            }

            file_put_contents($absPath, $cleanSvg);
            $this->writePng($url, $paths['png_abs']);
        } catch (\Throwable) {
            $this->deleteGeneratedFiles($paths);
            return null;
        }

        return QrCode::create($eventId, $groupId, $code, $paths['svg_rel']);
    }

    /**
     * Rewrites the QR code image files for an existing DB record using its stored confirmation code.
     *
     * Called when the file is missing but the record still exists — preserves the
     * confirmation code so printed cards and sent emails stay valid.
     *
     * @param QrCode $existing
     * @return QrCode|null The updated QrCode on success; null when files cannot be written.
     */
    private function regenerateImageForGroup(QrCode $existing): ?QrCode
    {
        $url     = $this->buildInviteUrl($existing->confirmationCode);
        $paths   = $this->pathsFor($existing->eventId, $existing->groupId);
        $absPath = $paths['svg_abs'];
        $dir     = $paths['dir'];
        $oldSvg  = $existing->absolutePath();
        $oldPng  = $existing->pngAbsolutePath();

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        try {
            $result = Builder::create()
                ->writer(new SvgWriter())
                ->writerOptions([
                    SvgWriter::WRITER_OPTION_COMPACT => true,
                    SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
                ])
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size(self::QR_SIZE)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->backgroundColor(new Color(0, 0, 0, 127))
                ->margin(0)
                ->build();

            $svg = $result->getString();

            $dom = new \DOMDocument();

            libxml_use_internal_errors(true);

            if (!$dom->loadXML($svg)) {
                libxml_clear_errors();
                return null;
            }

            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);

            // Remove width/height so the SVG scales via CSS.
            $svgEl = $dom->documentElement;
            if ($svgEl instanceof \DOMElement) {
                $svgEl->removeAttribute('width');
                $svgEl->removeAttribute('height');
            }

            // Remove direct <rect> children of the root <svg>.
            foreach ($xpath->query('/*[local-name()="svg"]/*[local-name()="rect"]') as $rect) {
                $rect->parentNode?->removeChild($rect);
            }

            // Make the QR <path> inherit its fill color.
            foreach ($xpath->query('/*[local-name()="svg"]/*[local-name()="path"]') as $path) {
                if ($path instanceof \DOMElement) {
                    $path->setAttribute('fill', 'inherit');
                    $path->removeAttribute('fill-opacity');
                }
            }

            $cleanSvg = $dom->saveXML($dom->documentElement);

            if ($cleanSvg === false) {
                return null;
            }

            file_put_contents($absPath, $cleanSvg);
            $this->writePng($url, $paths['png_abs']);

        } catch (\Throwable) {
            return null;
        }

        if ($existing->svgRelativePath() !== $paths['svg_rel'] && !QrCode::updatePath($existing->id, $paths['svg_rel'])) {
            return null;
        }

        $this->deleteLegacyFiles($oldSvg, $oldPng, $paths);

        return QrCode::findByCode($existing->confirmationCode) ?? $existing;
    }

    /**
     * Returns true when both QR formats exist in the canonical per-group folder.
     *
     * @param QrCode $qrCode
     * @return bool
     */
    private function hasCompleteImageSet(QrCode $qrCode): bool
    {
        $paths = $this->pathsFor($qrCode->eventId, $qrCode->groupId);

        return $qrCode->svgRelativePath() === $paths['svg_rel']
            && file_exists($paths['svg_abs'])
            && file_exists($paths['png_abs']);
    }

    /**
     * Generates the companion PNG with transparent background and no margin.
     *
     * @param string $url
     * @param string $absPath
     * @return void
     */
    private function writePng(string $url, string $absPath): void
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->size(self::QR_PNG_SIZE)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->foregroundColor(new Color(0, 0, 0))
            ->backgroundColor(new Color(0, 0, 0, 127))
            ->margin(0)
            ->build();

        $result->saveToFile($absPath);
    }

    /**
     * Builds canonical paths for a group's QR code folder and image files.
     *
     * @param int $eventId
     * @param int $groupId
     * @return array{dir:string, base_dir:string, svg_abs:string, png_abs:string, svg_rel:string, png_rel:string}
     */
    private function pathsFor(int $eventId, int $groupId): array
    {
        $upload      = wp_upload_dir();
        $baseName    = 'event_' . $eventId . '_group_' . $groupId;
        $relativeDir = self::QR_SUBDIR . '/' . $baseName;

        return [
            'dir'      => $upload['basedir'] . '/' . $relativeDir,
            'base_dir' => $upload['basedir'] . '/' . self::QR_SUBDIR,
            'svg_abs'  => $upload['basedir'] . '/' . $relativeDir . '/' . $baseName . '.svg',
            'png_abs'  => $upload['basedir'] . '/' . $relativeDir . '/' . $baseName . '.png',
            'svg_rel'  => $relativeDir . '/' . $baseName . '.svg',
            'png_rel'  => $relativeDir . '/' . $baseName . '.png',
        ];
    }

    /**
     * Removes partially written files for a failed brand-new QR generation.
     *
     * @param array{dir:string, svg_abs:string, png_abs:string} $paths
     * @return void
     */
    private function deleteGeneratedFiles(array $paths): void
    {
        foreach ([$paths['svg_abs'], $paths['png_abs']] as $path) {
            if (file_exists($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        if (is_dir($paths['dir'])) {
            @rmdir($paths['dir']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    /**
     * Removes legacy flat files after a QR record has moved to the folder layout.
     *
     * @param string $oldSvg
     * @param string $oldPng
     * @param array{dir:string, base_dir:string, svg_abs:string, png_abs:string} $paths
     * @return void
     */
    private function deleteLegacyFiles(string $oldSvg, string $oldPng, array $paths): void
    {
        foreach (array_unique([$oldSvg, $oldPng]) as $path) {
            if ($path !== $paths['svg_abs'] && $path !== $paths['png_abs'] && file_exists($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        $oldDir = dirname($oldSvg);
        if ($oldDir !== $paths['dir'] && $oldDir !== $paths['base_dir'] && is_dir($oldDir)) {
            @rmdir($oldDir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
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
     * Constructs the confirmation URL that is encoded into the QR code images.
     *
     * @param string $code The 16-character confirmation code.
     * @return string Full URL of the form {home_url}/?eim_confirmation={code}.
     */
    private function buildInviteUrl(string $code): string
    {
        $domain = trim((string) get_option('eim_qr_code_domain', ''));
        $base   = $domain !== '' ? trailingslashit($domain) : home_url('/');

        return $base . '?eim_confirmation=' . $code;
    }
}
