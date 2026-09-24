<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Min;
use Splicewire\Beam\Data\BeamData;

class ReplaceActionOccurrenceData extends BeamData
{
    public function __construct(
        #[Description('Series revision the caller last read; a stale revision is refused rather than overwriting a concurrent edit.')]
        #[MapName('expected_revision'), Min(1)] public int $expectedRevision,
        #[Description('Occurrence identity (Y-m-d) from the expanded series that this edit targets.')]
        #[MapName('recurrence_id'), DateFormat('Y-m-d')] public string $recurrenceId,
        #[Description('Replacement action for this one occurrence only; the series rule is unchanged.')]
        public CalendarActionData $action,
    ) {}
}
