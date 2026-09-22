<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;

/**
 * The instant a sweep fires AS OF — optional, and absent means "now".
 *
 * A sweep accepts one parameter, so `input: false` would be a lie on
 * {@see \Splicewire\Beam\Calendars\Ops\SweepCalendar}: the op reads `at` off the request and hands
 * it to {@see \Splicewire\Beam\Calendars\Jobs\SweepCalendarJob}. Declaring the single field is what
 * stops the generated client from publishing a sweep call that takes no arguments.
 *
 * Single-word key, so nothing to map — the camel/snake hazard {@see CalendarSeriesInputData}
 * documents cannot bite a name with no word boundary in it.
 */
class SweepInputData extends BeamData
{
    public function __construct(
        #[Description('Instant through which due occurrences are swept; omission or null uses the current time.')]
        public string|null|Optional $at = new Optional,
    ) {}
}
