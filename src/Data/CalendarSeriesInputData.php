<?php

namespace Splicewire\Beam\Calendars\Data;

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
class CalendarSeriesInputData extends Data implements MapsToModelAttributes
{
    /**
     * @param  list<array<string, mixed>>|Optional  $overrides
     */
    public function __construct(
        public string|Optional $calendar_id = new Optional,
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

        foreach (['calendar_id', 'channel', 'anchor', 'window', 'overrides'] as $field) {
            if (! $this->{$field} instanceof Optional) {
                $attributes[$field] = $this->{$field};
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
