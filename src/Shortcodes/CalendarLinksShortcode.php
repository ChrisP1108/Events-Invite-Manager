<?php

declare(strict_types=1);

namespace EventsInviteManager\Shortcodes;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Models\Event;
use EventsInviteManager\Models\Location;
use EventsInviteManager\Models\QrCode;
use Spatie\CalendarLinks\Link;

/**
 * Shortcode: [eim_calendar_links]
 *
 * Renders "Add to Calendar" links for the event identified by the
 * eim_confirmation query-string parameter.
 *
 * Attributes:
 *   styled   (default "true")  – "false" suppresses the bundled stylesheet.
 *                                CSS class names are always emitted.
 *   includes (default "google,ical,outlook") – comma-separated list of
 *            calendar types to render. Accepted values: google, ical, outlook.
 */
final class CalendarLinksShortcode
{
    private const VALID_TYPES = ['google', 'ical', 'outlook'];

    public function register(): void
    {
        add_shortcode('eim_calendar_links', [$this, 'render']);
    }

    public function render(array|string $attrs): string
    {
        $attrs = shortcode_atts(
            ['styled' => 'true', 'includes' => '', 'before_text' => '', 'after_text' => '', 'no_pretext' => ''],
            is_array($attrs) ? $attrs : [],
            'eim_calendar_links'
        );

        $styled     = strtolower(trim((string) $attrs['styled'])) !== 'false';
        $types      = $this->parseIncludes((string) $attrs['includes']);
        $beforeText = trim((string) $attrs['before_text']);
        $afterText  = trim((string) $attrs['after_text']);
        $noPretext  = trim((string) $attrs['no_pretext']) !== '';

        if (empty($types)) {
            return '';
        }

        $event = $this->resolveEvent();

        if ($event === null) {
            return '';
        }

        if ($event->startDatetime === null) {
            return '';
        }

        $link = $this->buildLink($event);

        if ($link === null) {
            return '';
        }

        if ($styled) {
            wp_enqueue_style(
                'eim-calendar-links',
                EIM_PLUGIN_URL . 'assets/css/calendar-links.css',
                [],
                EIM_VERSION
            );
        }

        return $this->renderHtml($link, $types, $styled, $beforeText, $afterText, $noPretext);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parses the "includes" attribute into a validated list of calendar type strings.
     *
     * @return string[]
     */
    private function parseIncludes(string $raw): array
    {
        if (trim($raw) === '') {
            return self::VALID_TYPES;
        }

        $requested = array_map('trim', explode(',', strtolower($raw)));

        return array_values(array_filter($requested, static fn(string $t) => in_array($t, self::VALID_TYPES, true)));
    }

    /**
     * Resolves the Event from the eim_confirmation query-string parameter.
     */
    private function resolveEvent(): ?Event
    {
        $code = isset($_GET['eim_confirmation'])
            ? sanitize_text_field(wp_unslash($_GET['eim_confirmation']))
            : '';

        if ($code === '') {
            return null;
        }

        $qrCode = QrCode::findByCode($code);

        if ($qrCode === null) {
            return null;
        }

        return Event::find($qrCode->eventId);
    }

    /**
     * Converts the event's UTC datetime strings to \DateTime objects in the
     * event's own timezone and builds a Spatie Link.
     *
     * Returns null when the start datetime cannot be parsed.
     */
    private function buildLink(Event $event): ?Link
    {
        try {
            $tz    = $event->timezone !== '' ? new \DateTimeZone($event->timezone) : new \DateTimeZone('UTC');
            $from  = (new \DateTime($event->startDatetime, new \DateTimeZone('UTC')))->setTimezone($tz);

            if ($event->endDatetime !== null) {
                $to = (new \DateTime($event->endDatetime, new \DateTimeZone('UTC')))->setTimezone($tz);
            } else {
                // Default to a one-hour event when no end time is set.
                $to = clone $from;
                $to->modify('+1 hour');
            }
        } catch (\Throwable) {
            return null;
        }

        $link = Link::create($event->name, $from, $to);

        if ($event->description !== '') {
            $link = $link->description($event->description);
        }

        if ($event->venueId !== null) {
            $venue = Location::find($event->venueId);
            if ($venue !== null) {
                $parts = array_filter([$venue->name, $venue->formattedAddress()]);
                if ($parts) {
                    $link = $link->address(implode(', ', $parts));
                }
            }
        }

        return $link;
    }

    /**
     * Renders the HTML wrapper and individual calendar anchor tags.
     *
     * @param string[] $types
     */
    private function renderHtml(Link $link, array $types, bool $styled, string $beforeText = '', string $afterText = '', bool $noPretext = false): string
    {
        $items = '';

        foreach ($types as $type) {
            $items .= $this->renderItem($link, $type, $beforeText, $afterText, $noPretext);
        }

        if ($items === '') {
            return '';
        }

        $styledClass = $styled ? ' eim-calendar-links--styled' : '';

        return sprintf(
            '<div class="eim-calendar-links%s">%s</div>',
            esc_attr($styledClass),
            $items
        );
    }

    private function renderItem(Link $link, string $type, string $beforeText = '', string $afterText = '', bool $noPretext = false): string
    {
        [$href, $calendarName, $download] = match ($type) {
            'google'  => [$link->google(),      'Google Calendar', ''],
            'ical'    => [$link->ics(),         'Apple Calendar',  $this->icsFilename($link)],
            'outlook' => [$link->webOutlook(),  'Outlook',         ''],
            default   => ['', '', ''],
        };

        $suffix = $afterText !== '' ? ' ' . $afterText : '';
        $label  = $noPretext
            ? $calendarName . $suffix
            : ($beforeText !== '' ? $beforeText : 'Add to') . ' ' . $calendarName . $suffix;

        if ($href === '') {
            return '';
        }

        $downloadAttr = $download !== '' ? sprintf(' download="%s"', esc_attr($download)) : '';
        $targetAttr   = $type === 'ical' ? '' : ' target="_blank" rel="noopener noreferrer"';

        return sprintf(
            '<a href="%s" class="eim-calendar-link eim-calendar-link--%s"%s%s>'
                . '%s'
            . '</a>',
            esc_attr($href),
            esc_attr($type),
            $targetAttr,
            $downloadAttr,
            esc_html($label)
        );
    }

    private function icsFilename(Link $link): string
    {
        $slug = sanitize_title($link->title);

        return ($slug !== '' ? $slug : 'event') . '.ics';
    }
}
