<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class ActionOccurrenceOverrideData extends BeamData
{
    public function __construct(
        #[MapName('recurrence_id')]
        public string $recurrenceId,
        public string $mode,
        #[MapName('action_id')]
        public ?string $actionId = null,
    ) {}
}
