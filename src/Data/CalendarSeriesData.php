<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Recurrence\SeriesExpander;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The `calendar-series` particle resource — one row per recurrence rule.
 *
 * `rrule` is projected, not stored: the RFC-5545 string is derived from the typed rule on read.
 * Exposing it is what lets a client display or export the recurrence without reimplementing the
 * derivation, and deriving it here rather than storing it is what keeps the typed rule the single
 * source of truth.
 */
#[ParticleResource(
    key: 'calendar-series',
    backing: CalendarSeries::class,
    data: self::class,
    input: CalendarSeriesInputData::class,
    filterable: true,
    label: 'Calendar series',
    singularLabel: 'Calendar series',
    group: 'Calendars',
    icon: 'repeat',
    section: 'calendars',
)]
/**
 * ⚠️ The wire keys are DECLARED below, which is what makes the camelCase property spelling a
 * style choice rather than a silent contract change.
 *
 * Under the host's global `input => CamelCaseMapper` / `output => null`, an UNDECLARED DTO
 * publishes whatever the global mapper happens to produce. This package shipped with neither
 * axis declared, so its read side emitted `calendar_id` while its write side demanded
 * `calendarId` — read one key, write another, with nothing reporting it. `WireNameTest` now
 * asserts the published keys directly.
 */
#[TypeScript]
class CalendarSeriesData extends BeamData
{
    public function __construct(
        public string $id,
        #[Filterable]
        #[MapName('calendar_id')]
        public string $calendarId,
        #[Filterable]
        public string $channel,
        #[Sortable(default: true)]
        #[Filterable]
        public ?string $anchor,
        public ?string $window,
        /** The RFC-5545 projection of the typed rule. Derived on read — never stored. */
        public ?string $rrule,
        #[MapName('override_count')]
        public int $overrideCount,
    ) {}

    public static function scope(Builder $query): Builder
    {
        $calendarModel = config('beam.calendars.models.calendar', Calendar::class);

        return $query->whereIn(
            'calendar_id',
            Gate::getPolicyFor($calendarModel)
                ->scopeForUser($calendarModel::query(), Auth::user())
                ->select('id'),
        );
    }

    public static function project(CalendarSeries $series): self
    {
        return new self(
            id: (string) $series->getKey(),
            calendarId: (string) $series->calendar_id,
            channel: (string) $series->channel,
            anchor: $series->anchor?->toDateString(),
            window: $series->window?->toDateString(),
            rrule: self::rruleOf($series),
            overrideCount: count($series->overrides ?? []),
        );
    }

    /**
     * A stored rule that cannot be hydrated is a data problem, not a reason for a listing to 500 —
     * so an unparseable rule projects as null and the row still appears, which is also the only way
     * anyone notices it needs fixing.
     */
    private static function rruleOf(CalendarSeries $series): ?string
    {
        try {
            return SeriesExpander::rrule($series->toSeriesData()->rule);
        } catch (\Throwable) {
            return null;
        }
    }
}
