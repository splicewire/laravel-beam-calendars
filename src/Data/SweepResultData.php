<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/**
 * What a sweep did. Returned by the sweep operation's `respond()` — see
 * {@see \Splicewire\Beam\Calendars\Ops\SweepCalendar} for why a Task op must answer with a real
 * shape rather than a bare `queued: true`.
 */
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
class SweepResultData extends BeamData
{
    /**
     * @param  list<string>  $fired  `series_id|recurrence_id` keys
     */
    public function __construct(
        #[MapName('calendar_id')]
        public string $calendarId,
        public bool $queued,
        public array $fired = [],
        #[MapName('fired_count')]
        public int $firedCount = 0,
    ) {}
}
