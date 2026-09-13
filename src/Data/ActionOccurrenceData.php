<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/** A read projection; expanding this value never creates an action. */
class ActionOccurrenceData extends BeamData
{
    public function __construct(
        #[MapName('recurrence_id')]
        public string $recurrenceId,
        public CalendarActionData $action,
        public ?CalendarActionRecordData $record = null,
    ) {}
}
