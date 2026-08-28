<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;

/**
 * The typed payload for a recurring series — a single schedulable, side-effecting declaration that
 * carries a recurrence RULE plus a SPAWN template instead of one dated reference.
 *
 * Its rule expands into virtual Occurrences at projection time and materializes into ordinary
 * event rows on fire or override, so the series stays O(1) on disk however long it runs.
 *
 * `anchor` is the series' START date. `window` is an optional bounded expansion horizon — an ISO
 * date the expander will not project past when the rule is otherwise unbounded.
 *
 * `overrides` pins single instances (RFC-5545 EXDATE / RECURRENCE-ID) as a LIST, never a keyed
 * map: a map projects to a JSON-Schema `type: array` that would then reject a JSON object. Each
 * entry is `{recurrence_id, action, spawn?}` where `action` is `skip` (EXDATE — omit that
 * occurrence) or `replace` (RECURRENCE-ID — swap its spawn). Overrides ride the series so it stays
 * self-contained; there is no override side table.
 *
 * ⚠️ `schemaName()` is `calendar/series` — SINGULAR, and it must stay that way even though the
 * package is `laravel-beam-calendars`. Stored payloads reconcile forward through PayloadMigrator
 * on this exact string; renaming it to match the package would orphan every row already written by
 * the engine tier this was extracted from. The schema name is data, not branding.
 */
#[Title('Recurring series')]
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
class SeriesData extends BeamData implements SchemaIdentity
{
    /**
     * @param  list<array<string, mixed>>|Optional  $overrides
     */
    public function __construct(
        public string $kind,
        #[Title('Channel')]
        #[Description('The delivery lane the series goes out on.')]
        public string $channel,
        #[Title('Starts on')]
        #[Description('The first occurrence date.')]
        public string $anchor,
        public RecurrenceRuleData $rule,
        public SpawnData $spawn,
        #[Title('Expand until')]
        #[Description('Optional horizon the expander will not project past.')]
        public ?string $window = null,
        public array|Optional $overrides = new Optional,
    ) {}

    public static function schemaName(): string
    {
        return 'calendar/series';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
