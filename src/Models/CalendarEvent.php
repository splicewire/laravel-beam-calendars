<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Splicewire\Beam\Facades\Beam;

/**
 * One stored, dated row. `kind` is the registry key and `payload` the typed shape that key
 * resolves to — this model never interprets the payload, it carries it.
 *
 * A row with `series_id` + `recurrence_id` set is a MATERIALIZED instance of a series: either one
 * the scheduler spawned, or one an editor pinned over a virtual twin. Either way its existence is
 * what makes the projection skip the virtual occurrence for that recurrence — see
 * {@see \Splicewire\Beam\Calendars\Projection\CalendarProjection}.
 *
 * Authorization CASCADES from the parent calendar rather than being declared per row. An event has
 * no independent audience: being able to see a calendar is what makes its events visible, and a
 * per-row visibility column would let the two disagree.
 */
#[UseCascadePolicy(BaseModelPolicy::class)]
class CalendarEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'anchor' => 'date',
        ];
    }

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendar_events', 'calendar_events');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(config('beam.calendars.models.calendar', Calendar::class), 'calendar_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(config('beam.calendars.models.series', CalendarSeries::class), 'series_id');
    }

    /** The cascade reads containment from here — an event's audience IS its calendar's. */
    public function visibilityAncestors(): iterable
    {
        return $this->calendar ? [$this->calendar] : [];
    }
}
