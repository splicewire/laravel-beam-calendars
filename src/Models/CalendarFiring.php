<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Facades\Beam;

/**
 * One row per (series, recurrence) that has been fired. The ledger, and the exactly-once mechanism.
 *
 * ⚠️ There is no `#[UseCascadePolicy]` here on purpose. A firing is an OPERATIONAL record, not
 * content: it is written by the scheduler, read by an operator, and never mounted as a particle
 * resource. Giving it a policy would imply a surface that does not exist and invite one to be built.
 */
class CalendarFiring extends Model
{
    use HasUuids;

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_FIRED = 'fired';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'fired_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendar_firings', 'calendar_firings');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(config('beam.calendars.models.series', CalendarSeries::class), 'series_id');
    }
}
