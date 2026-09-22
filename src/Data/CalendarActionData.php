<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/** A complete action replacement. Its handler declares and validates the nested payload shape. */
class CalendarActionData extends BeamData
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        #[Description('Registered action handler key that determines how the action executes.')]
        public string $kind,
        #[Description('Input for the selected action handler, validated and prepared by that handler.')]
        public array $payload,
        #[MapName('due_at')]
        #[Description('Instant when the action becomes eligible to execute.')]
        public string $dueAt,
        #[Description('IANA timezone used to interpret local calendar scheduling for this action.')]
        public string $timezone,
        #[MapName('calendar_id')]
        #[Description('Calendar associated with the action, when it belongs to one.')]
        public ?string $calendarId = null,
        #[MapName('series_id')]
        #[Description('Source action series, when this action represents a recurring occurrence.')]
        public ?string $seriesId = null,
        #[MapName('recurrence_id')]
        #[Description('Occurrence identity within the source series; retained when the action is rescheduled.')]
        public ?string $recurrenceId = null,
        #[Description('Stable source identity used to deduplicate identical scheduling requests; server-reserved origins cannot be supplied.')]
        public ?string $origin = null,
        #[MapName('correlation_id')]
        #[Description('Caller correlation identifier retained with the action for tracing related work.')]
        public ?string $correlationId = null,
    ) {}
}
