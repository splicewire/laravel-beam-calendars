<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/** Tenant scoping is enforced before projection; its internal token is never exposed here. */
#[ParticleResource(
    key: 'calendar-actions',
    backing: CalendarAction::class,
    data: CalendarActionRecordData::class,
    input: false,
    readOnly: true,
    editable: false,
    deletable: false,
)]
class CalendarActionRecordData extends BeamData
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<CalendarActionAttemptData>  $attempts
     */
    public function __construct(
        public string $id,
        public int $revision,
        public string $status,
        #[MapName('current_attempt_id')]
        public string $currentAttemptId,
        #[MapName('attempt_number')]
        public int $attemptNumber,
        #[MapName('due_at')]
        #[Sortable(default: true, name: 'due_at')]
        public string $dueAt,
        public string $timezone,
        public string $kind,
        public array $payload,
        public string $principal,
        public string $creator,
        public ?string $origin,
        #[MapName('correlation_id')]
        public ?string $correlationId,
        #[MapName('calendar_id')]
        #[Filterable(Exact::class, name: 'calendar_id')]
        public ?string $calendarId,
        #[MapName('series_id')]
        public ?string $seriesId,
        #[MapName('recurrence_id')]
        public ?string $recurrenceId,
        public array $attempts,
    ) {}

    public static function scope(Builder $query): Builder
    {
        if (! app()->bound(ActionContextProvider::class)) {
            return $query->whereRaw('1 = 0');
        }

        $context = app(ActionContextProvider::class)->current();

        return $query->where('tenant_token', $context->tenantToken)->where('principal', $context->principal);
    }

    public static function fromModel(CalendarAction $action): self
    {
        return new self(
            id: $action->id, revision: $action->revision, status: $action->status,
            currentAttemptId: $action->current_attempt_id, attemptNumber: $action->attempt_number,
            dueAt: \Splicewire\Beam\Calendars\Actions\ActionInstant::format($action->due_at), timezone: $action->timezone,
            kind: $action->kind, payload: $action->payload, principal: $action->principal, creator: $action->creator,
            origin: $action->origin, correlationId: $action->correlation_id,
            calendarId: $action->calendar_id, seriesId: $action->series_id, recurrenceId: $action->recurrence_id,
            attempts: $action->attempts->map(fn (CalendarActionAttempt $attempt): CalendarActionAttemptData => CalendarActionAttemptData::fromModel($attempt))->all(),
        );
    }
}
