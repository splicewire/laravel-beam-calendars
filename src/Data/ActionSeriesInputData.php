<?php

namespace Splicewire\Beam\Calendars\Data;

use Splicewire\Beam\Data\BeamData;

class ActionSeriesInputData extends BeamData
{
    public function __construct(
        public CalendarActionData $action,
        public RecurrenceRuleData $rule,
        public ?string $window = null,
    ) {}
}
