<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Facades\Beam;

/** A stable execution identity. Terminal attempts retain their original request and outcome. */
class CalendarActionAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'request' => 'array', 'result' => 'array', 'blockers' => 'array',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'number' => 'integer', 'revision' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendar_action_attempts', 'calendar_action_attempts');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(CalendarAction::class, 'action_id');
    }
}
