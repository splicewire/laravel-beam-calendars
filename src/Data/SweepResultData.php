<?php

namespace Splicewire\Beam\Calendars\Data;

use Splicewire\Beam\Data\Data;

/**
 * What a sweep did. Returned by the sweep operation's `respond()` — see
 * {@see \Splicewire\Beam\Calendars\Ops\SweepCalendar} for why a Task op must answer with a real
 * shape rather than a bare `queued: true`.
 */
class SweepResultData extends Data
{
    /**
     * @param  list<string>  $fired  `series_id|recurrence_id` keys
     */
    public function __construct(
        public string $calendar_id,
        public bool $queued,
        public array $fired = [],
        public int $firedCount = 0,
    ) {}
}
