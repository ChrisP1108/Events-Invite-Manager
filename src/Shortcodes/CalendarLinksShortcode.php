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
 *   mode     (default "event") – accepted values: event, save_the_date, both.
 */
final class CalendarLinksShortcode
{
    private const VALID_TYPES = ['google', 'ical', 'outlook'];
    private const VALID_MODES = ['event', 'save_the_date', 'both'];

    public function register(): void
    {
        add_shortcode('eim_calendar_links', [$this, 'render']);
    }

    public function render(array|string $attrs): string
    {
        $attrs = shortcode_atts(
            [
                'styled'     => 'true',
                'includes'   => '',
                'mode'       => 'event',
                'before_text' => '',
                'after_text' => '',
                'no_pretext' => '',
            ],
            is_array($attrs) ? $attrs : [],
            'eim_calendar_links'
        );

        $styled     = strtolower(trim((string) $attrs['styled'])) !== 'false';
        $types      = $this->parseIncludes((string) $attrs['includes']);
        $mode       = $this->parseMode((string) $attrs['mode']);
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

        $linkSets = $this->buildLinkSets($event, $mode);

        if (empty($linkSets)) {
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

        return $this->renderHtml($linkSets, $types, $styled, $beforeText, $afterText, $noPretext);
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
     * Parses the "mode" attribute into a supported calendar-link mode.
     */
    private function parseMode(string $raw): string
    {
        $mode = str_replace('-', '_', strtolower(trim($raw)));

        if ($mode === '') {
            return 'event';
        }

        return in_array($mode, self::VALID_MODES, true) ? $mode : 'event';
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
     * Builds the requested calendar link set(s).
     *
     * @return array<int, array{key:string,label:string,link:Link}>
     */
    private function buildLinkSets(Event $event, string $mode): array
    {
        $sets = [];

        if ($mode === 'save_the_date' || $mode === 'both') {
            $link = $this->buildCalendarSpanLink($event);
            if ($link !== null) {
                $sets[] = ['key' => 'save-the-date', 'label' => 'Save the Date', 'link' => $link];
            }
        }

        if ($mode === 'event' || $mode === 'both') {
            $link = $this->buildEventLink($event);
            if ($link !== null) {
                $sets[] = ['key' => 'event', 'label' => 'Event', 'link' => $link];
            }
        }

        return $sets;
    }

    /**
     * Converts the event's UTC datetime strings to \DateTime objects in the
     * event's own timezone and builds a Spatie Link.
     *
     * Returns null when the start datetime cannot be parsed.
     */
    private function buildEventLink(Event $event): ?Link
    {
        if ($event->startDatetime === null) {
            return null;
        }

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

        return $this->decorateLink($link, $event, $event->description);
    }

    /**
     * Builds an all-day calendar span link from the optional event metadata.
     */
    private function buildCalendarSpanLink(Event $event): ?Link
    {
        if ($event->calendarSpanStartDate === null || $event->calendarSpanStartDate === '') {
            return null;
        }

        $endDate = ($event->calendarSpanEndDate !== null && $event->calendarSpanEndDate !== '')
            ? $event->calendarSpanEndDate
            : $event->calendarSpanStartDate;

        try {
            $tz   = $event->timezone !== '' ? new \DateTimeZone($event->timezone) : new \DateTimeZone('UTC');
            $from = new \DateTime($event->calendarSpanStartDate . ' 00:00:00', $tz);
            $to   = new \DateTime($endDate . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return null;
        }

        if ($to < $from) {
            return null;
        }

        $days        = ((int) $from->diff($to)->days) + 1;
        $title       = $event->calendarSpanTitle !== '' ? $event->calendarSpanTitle : $event->name;
        $description = $event->calendarSpanDescription !== '' ? $event->calendarSpanDescription : $event->description;
        $link        = Link::createAllDay($title, $from, $days);

        return $this->decorateLink($link, $event, $description);
    }

    /**
     * Adds event description and venue details to a calendar link.
     */
    private function decorateLink(Link $link, Event $event, string $description): Link
    {
        if ($description !== '') {
            $link = $link->description($description);
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
     * @param array<int, array{key:string,label:string,link:Link}> $linkSets
     * @param string[] $types
     */
    private function renderHtml(array $linkSets, array $types, bool $styled, string $beforeText = '', string $afterText = '', bool $noPretext = false): string
    {
        $groups = [];

        foreach ($linkSets as $set) {
            $items = '';
            foreach ($types as $type) {
                $items .= $this->renderItem($set['link'], $type, $beforeText, $afterText, $noPretext);
            }

            if ($items !== '') {
                $groups[] = ['key' => $set['key'], 'label' => $set['label'], 'items' => $items];
            }
        }

        if (empty($groups)) {
            return '';
        }

        $styledClass = $styled ? ' eim-calendar-links--styled' : '';
        $multiClass  = count($groups) > 1 ? ' eim-calendar-links--multiple' : '';
        $items       = '';

        foreach ($groups as $group) {
            if (count($groups) === 1) {
                $items .= $group['items'];
                continue;
            }

            $items .= sprintf(
                '<div class="eim-calendar-link-group eim-calendar-link-group--%s"><span class="eim-calendar-link-group-label">%s</span>%s</div>',
                esc_attr($group['key']),
                esc_html($group['label']),
                $group['items']
            );
        }

        return sprintf(
            '<div class="eim-calendar-links%s%s">%s</div>',
            esc_attr($styledClass),
            esc_attr($multiClass),
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
