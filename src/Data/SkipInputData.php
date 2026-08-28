<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/**
 * Which occurrence to drop — the RFC-5545 EXDATE coordinate, and nothing else.
 *
 * A skip names an instance; it never edits one. That is why this is a distinct shape from
 * {@see MaterializeInputData} rather than the same DTO reused with its edit fields left optional:
 * a shared class would publish `title`/`anchor` on the skip contract and quietly accept an edit
 * that {@see \Splicewire\Beam\Calendars\Ops\SkipOccurrence} would then discard.
 *
 * ⚠️ The wire keys are DECLARED below — see {@see CalendarSeriesInputData} for why this package
 * never leaves them to the host's global mapper, and `WireNameTest` for the assertion.
 */
class SkipInputData extends BeamData
{
    public function __construct(
        #[MapName('series_id')]
        public string $seriesId,
        #[MapName('recurrence_id')]
        public string $recurrenceId,
    ) {}
}
