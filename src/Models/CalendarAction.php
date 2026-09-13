<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Facades\Beam;

/** Mutable scheduled intent; writes are mediated by ActionService, never generic model CRUD. */
class CalendarAction extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['payload' => 'array', 'due_at' => 'immutable_datetime', 'revision' => 'integer', 'attempt_number' => 'integer'];
    }

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendar_actions', 'calendar_actions');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CalendarActionAttempt::class, 'action_id')->orderBy('number');
    }

    public function toActionData(): CalendarActionData
    {
        return new CalendarActionData(
            kind: $this->kind,
            payload: $this->payload,
            dueAt: \Splicewire\Beam\Calendars\Actions\ActionInstant::format($this->due_at),
            timezone: $this->timezone,
            calendarId: $this->calendar_id,
            seriesId: $this->series_id,
            recurrenceId: $this->recurrence_id,
            origin: $this->origin,
            correlationId: $this->correlation_id,
        );
    }
}
