<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class ActionOccurrenceListData extends BeamData
{
    /** @param list<ActionOccurrenceData> $occurrences */
    public function __construct(public array $occurrences) {}
}
