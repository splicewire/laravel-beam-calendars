<?php

namespace Splicewire\Beam\Calendars\Data;

use Splicewire\Beam\Data\BeamData;

class ActionOccurrenceListData extends BeamData
{
    /** @param list<ActionOccurrenceData> $occurrences */
    public function __construct(public array $occurrences) {}
}
