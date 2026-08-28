<?php

namespace Splicewire\Beam\Calendars\Render;

use DateTimeImmutable;
use Splicewire\Beam\Calendars\Contracts\CalendarRenderer;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Calendars\Projection\ProjectedEvent;

/**
 * Compiles a projected calendar into an RFC 5545 iCalendar (`.ics`) document — one VEVENT per
 * entry, all-day and day-granular.
 *
 * ⚠️ It renders the PROJECTION, not the rows, which is what makes a subscribed feed show recurring
 * instances at all. Rendering rows would export a 52-week series as a single event on its start
 * date — technically correct about storage and useless to a calendar client.
 *
 * Virtual and stored entries are rendered identically apart from the UID. That is intentional: a
 * subscriber must not see an instance appear, disappear and reappear as it materializes, and a UID
 * derived from `(series, recurrence)` is stable across exactly that transition.
 */
class IcsRenderer implements CalendarRenderer
{
    public function contentType(): string
    {
        return 'text/calendar';
    }

    /** @param  list<ProjectedEvent>  $occurrences */
    public function render(Calendar $calendar, array $occurrences, Horizon $horizon): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Splicewire//Beam Calendar//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$this->escape((string) ($calendar->title ?? 'Calendar')),
        ];

        $stamp = gmdate('Ymd\THis\Z');

        foreach ($occurrences as $event) {
            $lines = [...$lines, ...$this->vevent($calendar, $event, $stamp)];
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545 §3.1: CRLF, and the document ends with one.
        return implode("\r\n", $lines)."\r\n";
    }

    /** @return list<string> */
    private function vevent(Calendar $calendar, ProjectedEvent $event, string $stamp): array
    {
        $date = $this->date($event->anchor);

        if ($date === null) {
            return [];
        }

        return array_values(array_filter([
            'BEGIN:VEVENT',
            'UID:'.$this->uid($calendar, $event),
            'DTSTAMP:'.$stamp,
            // An all-day event is DATE-valued and its DTEND is EXCLUSIVE — the day after. Omitting
            // DTEND, or setting it to the same day, renders as a zero-length event in most clients.
            'DTSTART;VALUE=DATE:'.$date->format('Ymd'),
            'DTEND;VALUE=DATE:'.$date->modify('+1 day')->format('Ymd'),
            'SUMMARY:'.$this->escape($event->title ?? $this->fallbackSummary($event)),
            $event->channel !== '' ? 'CATEGORIES:'.$this->escape($event->channel) : null,
            $event->recurrenceId !== null ? 'RECURRENCE-ID;VALUE=DATE:'.$this->compact($event->recurrenceId) : null,
            'END:VEVENT',
        ], fn (?string $line): bool => $line !== null));
    }

    /**
     * Stable across materialization: an instance keyed on `(series, recurrence)` keeps its UID when
     * it stops being virtual and becomes a row, so a subscriber sees one event throughout rather
     * than a delete followed by an unrelated create.
     */
    private function uid(Calendar $calendar, ProjectedEvent $event): string
    {
        $local = $event->seriesRef !== null && $event->recurrenceId !== null
            ? $event->seriesRef.'-'.$this->compact($event->recurrenceId)
            : (string) ($event->id ?? $event->anchor.'-'.$event->channel);

        return $local.'@'.$calendar->getKey();
    }

    private function fallbackSummary(ProjectedEvent $event): string
    {
        // A kind with no title is not an error — see CalendarProjection::fromRow(). Name the kind
        // rather than inventing a label this package has no way to know.
        return trim((string) preg_replace('/^kind\./', '', (string) ($event->kind ?? 'Event'))) ?: 'Event';
    }

    private function date(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function compact(string $date): string
    {
        return str_replace('-', '', substr($date, 0, 10));
    }

    /** RFC 5545 §3.3.11 TEXT escaping — backslash first, or it re-escapes its own output. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\n", "\r", ',', ';'],
            ['\\\\', '\\n', '', '\\,', '\;'],
            $value,
        );
    }
}
