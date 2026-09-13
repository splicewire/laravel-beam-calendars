<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Data\BeamData;

class CalendarActionAttemptData extends BeamData
{
    /**
     * @param  list<string>  $blockers
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $id,
        public int $revision,
        public int $number,
        public string $status,
        public array $blockers,
        public array $result,
        #[MapName('due_at')]
        public string $dueAt,
        #[MapName('started_at')]
        public ?string $startedAt,
        #[MapName('completed_at')]
        public ?string $completedAt,
    ) {}

    public static function fromModel(CalendarActionAttempt $attempt): self
    {
        return new self(
            id: $attempt->id, revision: $attempt->revision, number: $attempt->number,
            status: $attempt->status, blockers: $attempt->blockers ?? [], result: $attempt->result ?? [],
            dueAt: $attempt->request['due_at'], startedAt: $attempt->started_at === null ? null : \Splicewire\Beam\Calendars\Actions\ActionInstant::format($attempt->started_at),
            completedAt: $attempt->completed_at === null ? null : \Splicewire\Beam\Calendars\Actions\ActionInstant::format($attempt->completed_at),
        );
    }
}
