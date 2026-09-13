<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Min;
use Splicewire\Beam\Data\BeamData;

class ActionOccurrenceInputData extends BeamData
{
    public function __construct(
        #[MapName('expected_revision'), Min(1)] public int $expectedRevision,
        #[MapName('recurrence_id'), DateFormat('Y-m-d')] public string $recurrenceId,
    ) {}
}
