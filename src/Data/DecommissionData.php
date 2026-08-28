<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\Data;

/**
 * A scheduled operation that RETIRES a target on a date — the calendar's one built-in
 * side-effecting kind that is not a series.
 *
 * What "retire" means is the SpawnDriver's business, exactly as with a series firing: the free
 * tier records the firing and emits an event, and an engine that binds the port unpublishes,
 * archives or deletes according to what it owns. This kind exists in the free tier because
 * "something should stop on this date" is a calendar concept; the consequence is not.
 */
#[Title('Decommission')]
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
class DecommissionData extends Data implements SchemaIdentity
{
    public function __construct(
        public string $kind,
        #[Title('Channel')]
        public string $channel,
        #[Title('Date')]
        #[Description('When the target should be retired.')]
        public string $anchor,
        #[Title('Target type')]
        #[MapName('target_type')]
        public ?string $targetType = null,
        #[Title('Target')]
        #[MapName('target_ref')]
        public ?string $targetRef = null,
        #[Title('Reason')]
        public ?string $reason = null,
    ) {}

    public static function schemaName(): string
    {
        return 'calendar/decommission';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
