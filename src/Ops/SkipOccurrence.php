<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Data\CalendarSeriesData;
use Splicewire\Beam\Calendars\Data\SkipInputData;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Ops\Concerns\ResolvesOccurrences;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * Drop one instance of a series — RFC-5545 EXDATE.
 *
 * The skip is written as an override ON THE SERIES, not as a tombstone row. That keeps the series
 * self-contained (no override side table) and means a skipped instance is never projected and never
 * fired, rather than being projected-then-filtered somewhere downstream where a second consumer
 * would miss the filter.
 *
 * ⚠️ A skipped instance still COUNTS against a `count`-bounded rule. That is RFC-5545's rule, not a
 * quirk: an excluded instance is generated and then removed, so skipping one does not extend the
 * series by one at the end. The expander implements it; this note exists because the behaviour
 * reliably reads as a bug to whoever meets it first.
 */
#[ParticleOp(
    resource: 'calendars',
    name: 'skip',
    kind: OperationKind::Write,
    model: Calendar::class,
    ability: 'update',
    input: SkipInputData::class,
    output: CalendarSeriesData::class,
)]
class SkipOccurrence
{
    use ResolvesOccurrences;

    public static function handle(Calendar $calendar, Request $request, mixed $actor = null): CalendarSeriesData
    {
        $data = $request->validate([
            'series_id' => ['required', 'string'],
            'recurrence_id' => ['required', 'string'],
        ]);

        $series = self::seriesOn((string) $calendar->getKey(), $data['series_id']);
        $occurrence = self::occurrenceOf($series, $data['recurrence_id']);

        $overrides = array_values(array_filter(
            $series->overrides ?? [],
            static fn (array $o): bool => ($o['recurrence_id'] ?? null) !== $occurrence->recurrenceId,
        ));

        // A LIST, never a keyed map: a map projects to a JSON-Schema `type: array` that would then
        // reject the JSON object it actually is. `array_values` above is what keeps it one.
        $overrides[] = ['recurrence_id' => $occurrence->recurrenceId, 'action' => 'skip'];

        $series->forceFill(['overrides' => $overrides])->save();

        return CalendarSeriesData::project($series->refresh());
    }
}
