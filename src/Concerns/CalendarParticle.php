<?php

namespace Splicewire\Beam\Calendars\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;

/**
 * The shared calendar member set.
 *
 * ⚠️ **A trait, not a base class**, and that is the whole point: it leaves no `CalendarBase`
 * successor behind. An engine that wants its own Calendar model — with its own relations, its own
 * casts, its own extra traits — declares an ordinary Model over the same table and `use`s this.
 * A base class would have forced every consumer into one inheritance chain and made a second
 * consumer with different needs impossible without breaking the first.
 *
 * ⚠️ The fillable/casts merge happens in `initializeCalendarParticle()` rather than in trait
 * PROPERTIES. Eloquent calls `initialize{TraitName}()` automatically at construction, and it has
 * to be used here because a trait property whose default differs from the composing class's is a
 * fatal composition error — declaring `protected $casts = [...]` in this trait would make the
 * trait unusable by any model that declares its own.
 */
trait CalendarParticle
{
    public function initializeCalendarParticle(): void
    {
        $this->mergeFillable([
            'slug', 'title', 'timezone', 'visibility',
            'payload', 'meta', 'schema_ref', 'schema_id', 'head_version',
        ]);

        $this->mergeCasts([
            'payload' => 'array',
            'meta' => 'array',
        ]);
    }

    /** Stored, dated rows on this calendar. Virtual occurrences are NOT here — they are projected. */
    public function events(): HasMany
    {
        return $this->hasMany(config('beam.calendars.models.event', CalendarEvent::class), 'calendar_id')
            ->orderBy('anchor');
    }

    /** Recurrence rules on this calendar. One row each, however long they run. */
    public function series(): HasMany
    {
        return $this->hasMany(config('beam.calendars.models.series', CalendarSeries::class), 'calendar_id')
            ->orderBy('anchor');
    }

    /**
     * The lanes this calendar actually uses, in channel-source order.
     *
     * Derived, never a column — which is the same reason event count and next-occurrence are
     * accessors: a derived fact that becomes a column becomes a migration the first time anyone
     * changes how it is derived.
     *
     * @return list<string>
     */
    public function lanesInUse(): array
    {
        return $this->events()->distinct()->pluck('channel')
            ->merge($this->series()->distinct()->pluck('channel'))
            ->unique()->values()->all();
    }
}
