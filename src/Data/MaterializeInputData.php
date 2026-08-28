<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;

/**
 * Which occurrence to pin, plus the edit that pinning it is FOR.
 *
 * The ref pair is required — a materialize without a `(series_id, recurrence_id)` coordinate has
 * nothing to supersede. `anchor` and `title` are the edit: both optional, because pinning an
 * instance unchanged is a legitimate act (it is how a client reserves the row before editing it),
 * and `anchor` is explicitly nullable because MOVING the pinned instance is the point — the
 * identity is the recurrence, not the date.
 *
 * ⚠️ The wire keys are DECLARED below — see {@see CalendarSeriesInputData} for why this package
 * never leaves them to the host's global mapper, and `WireNameTest` for the assertion.
 */
class MaterializeInputData extends BeamData
{
    public function __construct(
        #[MapName('series_id')]
        public string $seriesId,
        #[MapName('recurrence_id')]
        public string $recurrenceId,
        public string|null|Optional $anchor = new Optional,
        public string|null|Optional $title = new Optional,
    ) {}
}
