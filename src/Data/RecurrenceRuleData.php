<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Calendars\Enums\RecurrenceFrequency;
use Splicewire\Beam\Data\Data;

/**
 * A typed recurrence rule — the *truth* a {@see SeriesData} carries.
 *
 * RFC-5545-shaped but a Data class, never a raw RRULE string. The string is an OUTPUT projection
 * the ICS renderer derives, while this typed shape rides the write-validation and
 * SchemaIdentity/PayloadMigrator path every other stored payload rides. Storing the string instead
 * would put the recurrence — the single most consequential field here — outside all of it.
 *
 * End is bounded by `count` OR `until`, never both; that is enforced when the rule EXPANDS, not by
 * the write schema, which enforces only `freq` presence and its enum.
 *
 * ⚠️ Only `freq` is required and the rest are `Optional` **with no `= null` default** — this is the
 * three-state shape, and it is deliberate twice over.
 *
 * First, nesting: the typed-payload validator projects this nested class straight through the
 * JSON-Schema generator, which marks a promoted nullable-default parameter REQUIRED. `Optional` is
 * how a nested field is genuinely omittable on write.
 *
 * Second, and the reason not to "tidy" this later: `public ?T $x = null` cannot express "clear
 * this". `DefaultValuesDataPipe` checks `hasDefaultValue` BEFORE `type->isOptional`, so an absent
 * field arrives as `null` rather than `Optional`, and a write gate testing `!== null` can set a
 * value but can never unset one — silently, with a 200. Every reader of these fields must stay
 * three-state aware; a `??`, `?:` or `(string)` collapses the third state and reintroduces the bug.
 *
 * This is a nested VALUE OBJECT, not a versioned document: it deliberately does NOT implement
 * SchemaIdentity. The versioned document is the whole {@see SeriesData} cell, which reconciles as
 * ONE payload (migration atomicity) — a nested SchemaIdentity would emit its own `$id`, re-rooting
 * the enum `$ref` resolution and orphaning it.
 */
#[Title('Repeats')]
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
class RecurrenceRuleData extends Data
{
    /**
     * @param  list<string>|Optional  $byday
     */
    public function __construct(
        #[Title('Frequency')]
        #[Description('How often it repeats.')]
        public RecurrenceFrequency $freq,
        #[Title('Every')]
        #[Description('Repeat every N periods (default 1).')]
        public int|Optional $interval = new Optional,
        #[Title('Occurrences')]
        #[Description('Stop after this many (leave blank for no limit).')]
        public int|Optional $count = new Optional,
        #[Title('Until')]
        #[Description('Stop on this date (leave blank for no end).')]
        public string|Optional $until = new Optional,
        #[Title('On days')]
        #[Description('RFC-5545 weekday tokens (MO/TU/…) for weekly schedules.')]
        #[ArrayItems('string')]
        public array|Optional $byday = new Optional,
    ) {}
}
