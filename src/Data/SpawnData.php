<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Data\Data;

/**
 * What one firing of a {@see SeriesData} produces — a discriminated template. `mode` selects the
 * branch: `generate` drives a net-new target per occurrence from `instructions` and/or
 * `definition_ref`; `reference` names one existing `target_ref` to re-surface.
 *
 * ⚠️ `target_ref` is deliberately NEUTRAL, and this is the one place this package's port diverges
 * from the engine code it came from, which spelled the field `composition_id`. A composition is a
 * paid-tier concept the topology fence forbids this package from naming, and a calendar that can
 * only ever point at compositions is not a calendar. The engine's SpawnDriver resolves the ref
 * into whatever it owns; this package treats it as an opaque string and never dereferences it.
 *
 * Only `mode` is required; each branch's fields are `Optional` so either branch validates and the
 * driver reads the field its own mode requires. `Optional` rather than nullable-default for the
 * same nested-validation reason as {@see RecurrenceRuleData} — see its docblock, which is the one
 * that explains why this must not be "simplified".
 */
#[Title('Each occurrence')]
/**
 * ⚠️ The wire keys are DECLARED below, which is what makes the camelCase property spelling a
 * style choice rather than a silent contract change.
 *
 * Under the host's global `input => CamelCaseMapper` / `output => null`, an UNDECLARED DTO
 * publishes whatever the global mapper happens to produce. This package shipped with neither
 * axis declared, so its read side emitted `calendar_id` while its write side demanded
 * `calendarId` — read one key, write another, with nothing reporting it. `WireNameTest` now
 * asserts the published keys directly.
 */
class SpawnData extends Data
{
    public function __construct(
        #[Title('Mode')]
        #[Description('Generate a new item each time, or re-surface a fixed one.')]
        public SpawnMode $mode,
        #[Title('Instructions')]
        #[Description('What each generated occurrence should produce (generate mode).')]
        public string|Optional $instructions = new Optional,
        #[Title('Definition reference')]
        #[Description('An optional definition each occurrence generates from.')]
        #[MapName('definition_ref')]
        public string|Optional $definitionRef = new Optional,
        #[Title('Target')]
        #[Description('The fixed item each occurrence re-surfaces (reference mode). Opaque to this package.')]
        #[MapName('target_ref')]
        public string|Optional $targetRef = new Optional,
    ) {}
}
