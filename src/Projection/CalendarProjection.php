<?php

namespace Splicewire\Beam\Calendars\Projection;

use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Recurrence\Occurrence;
use Splicewire\Beam\Calendars\Recurrence\SeriesExpander;

/**
 * The read model: a calendar plus a horizon becomes ONE flat, ordered list — stored rows and the
 * virtual instances the series in that window imply, merged and deduped.
 *
 * ## Why supersession is a key lookup here and was a date match before
 *
 * A stored row that materializes a series instance carries `(series_id, recurrence_id)`, so "this
 * virtual instance already exists as a real row" is a set-membership test on that exact pair. The
 * engine tier this was extracted from had no such pair available as columns and re-derived the
 * identity by comparing dates — which broke precisely when a pinned instance was MOVED off its
 * computed date, the one case the pinning feature exists to support.
 *
 * The projection is derived, horizon-bounded and uncached; nothing here writes.
 */
class CalendarProjection
{
    public function __construct(private SeriesExpander $expander = new SeriesExpander) {}

    /**
     * Every entry in the window, ordered by date then channel.
     *
     * @return list<ProjectedEvent>
     */
    public function events(Calendar $calendar, ?Horizon $horizon = null): array
    {
        $horizon ??= Horizon::default();
        $calendarId = (string) $calendar->getKey();

        // Every (series, recurrence) that already exists as a row — whether the scheduler spawned
        // it or an editor pinned it. Built from ALL materialized rows, not only those inside the
        // horizon: a pinned instance may have been moved OUT of the window, and its virtual twin
        // must still not reappear inside it. Scoping this query to the horizon would resurrect
        // exactly the duplicate the pin was created to remove.
        $materialized = $calendar->events()
            ->whereNotNull('series_id')->whereNotNull('recurrence_id')
            ->get(['series_id', 'recurrence_id'])
            ->mapWithKeys(fn (CalendarEvent $e): array => [$e->series_id.'|'.$e->recurrence_id => true])
            ->all();

        $out = [];

        foreach ($this->storedIn($calendar, $horizon) as $event) {
            $out[] = $this->fromRow($event, $calendarId);
        }

        foreach ($calendar->series as $series) {
            foreach ($this->expand($series, $horizon) as $occurrence) {
                if (isset($materialized[$occurrence->seriesRef.'|'.$occurrence->recurrenceId])) {
                    continue; // superseded by a real row
                }

                // The expander bounds the END only; applying the start is this layer's job.
                if (! $horizon->covers($occurrence->anchor)) {
                    continue;
                }

                $out[] = ProjectedEvent::virtual($occurrence, $calendarId, $this->titleFor($series));
            }
        }

        usort($out, fn (ProjectedEvent $a, ProjectedEvent $b): int => [$a->anchor, $a->channel] <=> [$b->anchor, $b->channel]);

        return $out;
    }

    /**
     * The DUE virtual instances of one series — everything at or before `$now` that has no row yet.
     * This is the scheduler's read, and it returns {@see Occurrence} rather than
     * {@see ProjectedEvent} because its consumer is a driver, not a display.
     *
     * @return list<Occurrence>
     */
    public function due(CalendarSeries $series, Horizon $horizon): array
    {
        $fired = $series->events()
            ->whereNotNull('recurrence_id')
            ->pluck('recurrence_id')
            ->flip();

        return array_values(array_filter(
            $this->expand($series, $horizon),
            fn (Occurrence $o): bool => ! $fired->has($o->recurrenceId) && $horizon->covers($o->anchor),
        ));
    }

    /** @return list<Occurrence> */
    private function expand(CalendarSeries $series, Horizon $horizon): array
    {
        $occurrences = $this->expander->expand(
            $series->toSeriesData(),
            (string) $series->getKey(),
            $horizon->end,
        );

        foreach ($occurrences as $occurrence) {
            $occurrence->calendarId = (string) $series->calendar_id;
        }

        return $occurrences;
    }

    private function storedIn(Calendar $calendar, Horizon $horizon): iterable
    {
        return $calendar->events()
            ->whereBetween('anchor', [$horizon->start->toDateString(), $horizon->end->toDateString()])
            ->get();
    }

    private function fromRow(CalendarEvent $event, string $calendarId): ProjectedEvent
    {
        $payload = $event->payload ?? [];

        return new ProjectedEvent(
            id: (string) $event->getKey(),
            calendarId: $calendarId,
            channel: (string) $event->channel,
            kind: $event->kind,
            anchor: $event->anchor?->toDateString() ?? '',
            // Every kind that carries a human label spells it `title`; one that does not simply has
            // none, and a consumer renders the date. This package does not dereference `target_ref`
            // to invent one — it cannot, since what a ref points AT is not its concern.
            title: is_string($payload['title'] ?? null) ? $payload['title'] : null,
            seriesRef: $event->series_id,
            recurrenceId: $event->recurrence_id,
            virtual: false,
            status: $event->status,
        );
    }

    private function titleFor(CalendarSeries $series): ?string
    {
        $spawn = $series->spawn ?? [];

        return is_string($spawn['instructions'] ?? null) ? $spawn['instructions'] : null;
    }
}
