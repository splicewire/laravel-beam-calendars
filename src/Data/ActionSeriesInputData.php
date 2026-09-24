<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Data\BeamData;

class ActionSeriesInputData extends BeamData
{
    public function __construct(
        #[Description('The action every occurrence of the series performs.')]
        public CalendarActionData $action,
        #[Description('Recurrence rule that expands the series into dated occurrences.')]
        public RecurrenceRuleData $rule,
        #[Description('Optional last date (Y-m-d) the series expands to when its rule is otherwise unbounded.')]
        public ?string $window = null,
    ) {}
}
