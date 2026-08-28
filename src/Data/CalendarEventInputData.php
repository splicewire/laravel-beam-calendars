<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The write DTO for a stored calendar event.
 *
 * `kind` and `payload` travel together and the pair is validated against the kind registry by
 * {@see \Splicewire\Beam\Calendars\Write\KindValidator} — a kind nobody registered is refused
 * rather than stored and discovered later by whatever tries to read it.
 *
 * See {@see CalendarInputData} for why every optional field is `T|Optional` and not `?T = null`.
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
class CalendarEventInputData extends BeamData implements MapsToModelAttributes
{
    /**
     * @param  array<string, mixed>|Optional  $payload
     */
    public function __construct(
        #[MapName('calendar_id')]
        public string|Optional $calendarId = new Optional,
        public string|Optional $channel = new Optional,
        public string|Optional $kind = new Optional,
        public string|Optional $anchor = new Optional,
        public array|Optional $payload = new Optional,
        public string|null|Optional $status = new Optional,
        /** Set only when pinning an instance of a series — see the projection's supersession rule. */
        #[MapName('series_id')]
        public string|null|Optional $seriesId = new Optional,
        #[MapName('recurrence_id')]
        public string|null|Optional $recurrenceId = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $attributes = [];

        // property => COLUMN, spelled out. The two used to be the same string and the map was a
        // loop over one list; now that the properties are camelCase and the columns are not, the
        // translation is the map's actual job rather than an accident of matching spellings.
        $columns = [
            'calendarId' => 'calendar_id',
            'channel' => 'channel',
            'kind' => 'kind',
            'anchor' => 'anchor',
            'payload' => 'payload',
            'status' => 'status',
            'seriesId' => 'series_id',
            'recurrenceId' => 'recurrence_id',
        ];

        foreach ($columns as $property => $column) {
            if (! $this->{$property} instanceof Optional) {
                $attributes[$column] = $this->{$property};
            }
        }

        return $attributes;
    }
}
