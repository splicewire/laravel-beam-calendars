<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * What a sweep did. Returned by the sweep operation's `respond()` — see
 * {@see \Splicewire\Beam\Calendars\Ops\SweepCalendar} for why a Task op must answer with a real
 * shape rather than a bare `queued: true`.
 */
/**
 * ⚠️ The wire keys are DECLARED below, which is what makes the camelCase property spelling a
 * style choice rather than a silent contract change.
 *
 * Under the host's global `input => CamelCaseMapper` / `output => null`, an UNDECLARED DTO
 * publishes whatever the global mapper happens to produce. This package shipped with neither
 * axis declared, so its read side emitted `calendar_id` while its write side demanded
 * `calendarId` — read one key, write another, with nothing reporting it. `WireNameTest` now
 * asserts the published keys directly.
 *
 * ⚠️ **`#[TypeScript]` is not decoration here — without it this package's generated client does not
 * compile.** The sweep op declares this class as its return, so `splicewire:beam:generate:client`
 * emits `calendars-op.ts` referencing `Splicewire.Beam.Calendars.Data.SweepResultData`, and the type is
 * only emitted for a class that declares the attribute. Measured 2026-08-29 at `~/Herd/splicewire-app`:
 * regenerating produced exactly three `tsc` errors, all *"Namespace
 * 'Splicewire.Beam.Calendars.Data' has no exported member 'SweepResultData'"*, and this class was the
 * only one of the package's four Data classes without the attribute. That is why the flagship's
 * `ui/src/generated` had gone stale rather than being refreshed — regenerating it broke the build.
 */
#[TypeScript]
class SweepResultData extends BeamData
{
    /**
     * @param  list<string>  $fired  `series_id|recurrence_id` keys
     */
    public function __construct(
        #[MapName('calendar_id')]
        public string $calendarId,
        public bool $queued,
        public array $fired = [],
        #[MapName('fired_count')]
        public int $firedCount = 0,
    ) {}
}
