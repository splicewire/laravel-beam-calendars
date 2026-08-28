<?php

namespace Splicewire\Beam\Calendars\Ops\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Recurrence\Occurrence;
use Splicewire\Beam\Calendars\Recurrence\SeriesExpander;

/**
 * Shared resolution for the two operations that act on ONE instance of a series.
 *
 * Both need the same thing and both can get it wrong the same way: a `(series, recurrence)` pair
 * arrives from a client, and neither "the series belongs to the addressed calendar" nor "the
 * recurrence is actually an instance of that series" is guaranteed by anything upstream. The op
 * mount authorises the CALENDAR; it says nothing about a series id in the body.
 *
 * The second check is the non-obvious one. A recurrence id is just a date, so a caller can hand
 * over a perfectly well-formed date the rule never generates — and pinning or skipping a date the
 * series does not produce writes an override that can never match anything, silently, with a 200.
 */
trait ResolvesOccurrences
{
    /** The series on THIS calendar, or a 422 — never a series from someone else's calendar. */
    protected static function seriesOn(string $calendarId, string $seriesId): CalendarSeries
    {
        $series = CalendarSeries::query()
            ->where('calendar_id', $calendarId)
            ->whereKey($seriesId)
            ->first();

        return $series ?? throw ValidationException::withMessages([
            'series_id' => 'That series does not belong to this calendar.',
        ]);
    }

    /**
     * The occurrence the rule actually generates for this recurrence id, or a 422.
     *
     * Expansion is bounded at the recurrence date itself, so this stays cheap even for a long
     * series: it never expands past the instance being asked about.
     *
     * ⚠️ **Expanded with the overrides STRIPPED**, and that is the whole subtlety. The expander
     * drops a skipped occurrence entirely — correctly, since a skip means "this does not happen".
     * But asking it "does this instance exist" while the overrides are applied makes an
     * already-skipped instance answer NO, which makes skipping it twice an error and, worse, makes
     * un-skipping or pinning it impossible: the operation that would restore it is refused on the
     * grounds that it is missing.
     *
     * Whether an instance EXISTS is a property of the RULE. Whether it happens is a property of the
     * overrides. Conflating the two makes every override a one-way door.
     */
    protected static function occurrenceOf(CalendarSeries $series, string $recurrenceId): Occurrence
    {
        $target = SeriesExpander::recurrenceId($recurrenceId);

        $base = $series->toSeriesData();
        $base->overrides = [];

        $expanded = (new SeriesExpander)->expand(
            $base,
            (string) $series->getKey(),
            Carbon::parse($recurrenceId)->endOfDay(),
        );

        foreach ($expanded as $occurrence) {
            if ($occurrence->recurrenceId === $target) {
                return $occurrence;
            }
        }

        throw ValidationException::withMessages([
            'recurrence_id' => sprintf(
                'This series generates no occurrence on [%s]. Pinning a date the rule does not '
                .'produce would write an override that can never match.',
                $recurrenceId,
            ),
        ]);
    }
}
