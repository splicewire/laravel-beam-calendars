<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\Data;
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
class CalendarEventInputData extends Data implements MapsToModelAttributes
{
    /**
     * @param  array<string, mixed>|Optional  $payload
     */
    public function __construct(
        public string|Optional $calendar_id = new Optional,
        public string|Optional $channel = new Optional,
        public string|Optional $kind = new Optional,
        public string|Optional $anchor = new Optional,
        public array|Optional $payload = new Optional,
        public string|null|Optional $status = new Optional,
        /** Set only when pinning an instance of a series — see the projection's supersession rule. */
        public string|null|Optional $series_id = new Optional,
        public string|null|Optional $recurrence_id = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $attributes = [];

        foreach (['calendar_id', 'channel', 'kind', 'anchor', 'payload', 'status', 'series_id', 'recurrence_id'] as $field) {
            if (! $this->{$field} instanceof Optional) {
                $attributes[$field] = $this->{$field};
            }
        }

        return $attributes;
    }
}
