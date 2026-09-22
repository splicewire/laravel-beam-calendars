<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;
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
class CalendarSeriesInputData extends BeamData implements MapsToModelAttributes
{
    /**
     * @param  list<array<string, mixed>>|Optional  $overrides
     */
    public function __construct(
        #[MapName('calendar_id')]
        #[Description('Calendar in which the recurring events appear.')]
        public string|Optional $calendarId = new Optional,
        #[Description('Registered calendar lane used for occurrences of this series.')]
        public string|Optional $channel = new Optional,
        #[Description('Start date from which the recurrence rule is expanded.')]
        public string|Optional $anchor = new Optional,
        #[Description('Recurrence rule that selects the dates in this series.')]
        public RecurrenceRuleData|Optional $rule = new Optional,
        #[Description('Event content or target reference produced for each occurrence.')]
        public SpawnData|Optional $spawn = new Optional,
        #[Description('Last date through which this series may be expanded; send null to remove this bound.')]
        public string|null|Optional $window = new Optional,
        #[Description('Occurrence overrides identified by recurrence_id, with skip or replace actions and optional replacement spawn content.')]
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
