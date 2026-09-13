<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/** A complete action replacement. Its handler declares and validates the nested payload shape. */
class CalendarActionData extends BeamData
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $kind,
        public array $payload,
        #[MapName('due_at')]
        public string $dueAt,
        public string $timezone,
        #[MapName('calendar_id')]
        public ?string $calendarId = null,
        #[MapName('series_id')]
        public ?string $seriesId = null,
        #[MapName('recurrence_id')]
        public ?string $recurrenceId = null,
        public ?string $origin = null,
        #[MapName('correlation_id')]
        public ?string $correlationId = null,
    ) {}
}
