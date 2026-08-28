<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The write DTO for a recurrence series.
 *
 * `rule` and `spawn` are typed nested DTOs on the way in and plain arrays on the column — the
 * typing is what gets them validated, the array is what gets them stored. See
 * {@see CalendarInputData} for why the optional fields are `T|Optional`.
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
class CalendarSeriesInputData extends Data implements MapsToModelAttributes
{
    /**
     * @param  list<array<string, mixed>>|Optional  $overrides
     */
    public function __construct(
        #[MapName('calendar_id')]
        public string|Optional $calendarId = new Optional,
        public string|Optional $channel = new Optional,
        public string|Optional $anchor = new Optional,
        public RecurrenceRuleData|Optional $rule = new Optional,
        public SpawnData|Optional $spawn = new Optional,
        public string|null|Optional $window = new Optional,
        public array|Optional $overrides = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $attributes = [];

        // property => COLUMN, spelled out — see CalendarEventInputData for why.
        $columns = [
            'calendarId' => 'calendar_id',
            'channel' => 'channel',
            'anchor' => 'anchor',
            'window' => 'window',
            'overrides' => 'overrides',
        ];

        foreach ($columns as $property => $column) {
            if (! $this->{$property} instanceof Optional) {
                $attributes[$column] = $this->{$property};
            }
        }

        // The two nested DTOs serialise to the JSON columns. `toArray()` rather than the object so
        // an Optional inside the rule is dropped on the way to storage instead of persisted as a
        // sentinel that would then fail to hydrate.
        if (! $this->rule instanceof Optional) {
            $attributes['rule'] = $this->rule->toArray();
        }
        if (! $this->spawn instanceof Optional) {
            $attributes['spawn'] = $this->spawn->toArray();
        }

        return $attributes;
    }
}
