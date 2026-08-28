<?php

namespace Splicewire\Beam\Calendars\Data;

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
        public string|null|Optional $at = new Optional,
    ) {}
}
