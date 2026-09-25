<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Keyword;
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
     * The host's date-picker hint, spelled out rather than imported.
     *
     * ⚠️ This is deliberate, not laziness. `Splicewire\Tower\Schema\Keywords::Widget` holds the same
     * string, and beam-calendars may not reach for it — this package's composer.json states the rule in
     * its own words: it "depends DOWN on beam-core + the data-schemas foundation and must never require
     * the composition/tower/satellite tiers that are ADDITIVE to it." A literal keeps the DOWN edge.
     *
     * Emitting it here is what {@see Keyword} is for. Its docblock names `x-widget` as the example of a
     * keyword the declaring package does not itself interpret: the schema states the hint, the host
     * decides whether to honour it. Nothing in this package reads it.
     */
    private const WIDGET = 'x-widget';

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
        #[Keyword(self::WIDGET, 'date')]
        public string $anchor,
        public RecurrenceRuleData $rule,
        public SpawnData $spawn,
        #[Title('Expand until')]
        #[Description('Optional horizon the expander will not project past.')]
        #[Keyword(self::WIDGET, 'date')]
        public ?string $window = null,
        public array|Optional $overrides = new Optional,
    ) {}

    public static function schemaName(): string
    {
        return 'calendar/series';
    }

    /**
     * Version 2 — the shape this package ships, frozen over the engine-tier v1.
     *
     * v1 (`calendar/series/1`) was frozen from the engine tier this class was extracted from, whose
     * nested spawn template was `SeriesSpawnData { composition_id, definition_ref, instructions,
     * mode }`. This package's {@see SpawnData} names the reference neutrally (`targetRef`, wire key
     * `target_ref`) and projects its collapsed schema on the PHP property names, so the nested
     * `$defs` changed shape (beam-calendars `6deaee9`/`480398a`) while the top-level fields did not.
     *
     * The v1 → v2 payload step is the ladder's structural rung, and it is deliberately the identity on
     * a stored payload: the top-level surface is unchanged, and the nested spawn keys a stored row
     * carries (`composition_id`, `definition_ref`) are the READ vocabulary — `SpawnData::from()` reads
     * the mapped input names, and tower's `TowerSpawnDriver::readableSeriesSlots()` owns the
     * `composition_id` → `target_ref` translation. Renaming them in the migration would strand every
     * reader of the stored slots. `SeriesSchemaVersionTest` pins the step.
     */
    public static function schemaVersion(): int
    {
        return 2;
    }
}
