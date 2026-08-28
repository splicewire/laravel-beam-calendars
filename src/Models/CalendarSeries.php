<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Splicewire\Beam\Calendars\Data\SeriesData;
use Splicewire\Beam\Facades\Beam;

/**
 * A recurrence RULE. One row, however many instances it implies.
 *
 * Authorization cascades from the parent calendar, same as {@see CalendarEvent} — see its docblock.
 */
#[UseCascadePolicy(BaseModelPolicy::class)]
class CalendarSeries extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rule' => 'array',
            'spawn' => 'array',
            'overrides' => 'array',
            'anchor' => 'date',
            'window' => 'date',
        ];
    }

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendar_series', 'calendar_series');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(config('beam.calendars.models.calendar', Calendar::class), 'calendar_id');
    }

    /** Materialized instances — rows that supersede this series' virtual occurrences. */
    public function events(): HasMany
    {
        return $this->hasMany(config('beam.calendars.models.event', CalendarEvent::class), 'series_id');
    }

    public function firings(): HasMany
    {
        return $this->hasMany(config('beam.calendars.models.firing', CalendarFiring::class), 'series_id');
    }

    /**
     * The typed payload this row carries, reassembled for the expander.
     *
     * The columns are the queryable projection; THIS is the shape the recurrence model actually
     * reasons about, and it is rebuilt on read rather than cached so a payload migration is
     * visible immediately instead of after a cache bust nobody remembers to do.
     */
    public function toSeriesData(): SeriesData
    {
        return SeriesData::from([
            'kind' => 'kind.series',
            'channel' => $this->channel,
            'anchor' => $this->anchor?->toDateString(),
            'rule' => $this->rule ?? [],
            'spawn' => $this->spawn ?? [],
            'window' => $this->window?->toDateString(),
            'overrides' => $this->overrides ?? [],
        ]);
    }

    public function visibilityAncestors(): iterable
    {
        return $this->calendar ? [$this->calendar] : [];
    }
}
