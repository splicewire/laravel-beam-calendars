<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The write DTO for a calendar.
 *
 * Declares {@see MapsToModelAttributes} rather than relying on a bare `toModelAttributes()` method
 * being found by `method_exists` — that fallback exists only to keep older code working and is
 * being burned down, metered by the `beam.particle.undeclared-write-map` audit. Declaring the
 * interface is what makes this map a contract instead of a convention.
 *
 * ⚠️ Every optional field is `T|Optional` with `= new Optional`, NOT `?T = null`, and the
 * difference is behavioural rather than stylistic. With a nullable default, an ABSENT field and an
 * explicit `null` both arrive as `null` — so a write that omits `title` is indistinguishable from
 * one clearing it, and the map below cannot honour either intent without breaking the other. See
 * {@see RecurrenceRuleData} for the full mechanism.
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
 */
class CalendarInputData extends Data implements MapsToModelAttributes
{
    public function __construct(
        public string|Optional $title = new Optional,
        public string|Optional $slug = new Optional,
        public string|Optional $timezone = new Optional,
        public string|null|Optional $visibility = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $attributes = [];

        // Present-vs-absent is the ONLY test here. Never `get_object_vars()` and never a `!== null`
        // gate: the first sweeps Optional sentinels into the write as literal values, and the
        // second silently makes every field un-clearable.
        if (! $this->title instanceof Optional) {
            $attributes['title'] = $this->title;
        }
        if (! $this->slug instanceof Optional) {
            $attributes['slug'] = $this->slug;
        }
        if (! $this->timezone instanceof Optional) {
            $attributes['timezone'] = $this->timezone;
        }
        if (! $this->visibility instanceof Optional) {
            $attributes['visibility'] = $this->visibility;
        }

        return $attributes;
    }
}
