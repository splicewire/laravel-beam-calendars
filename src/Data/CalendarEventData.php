<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The `calendar-events` particle resource — the STORED rows only.
 *
 * ⚠️ This resource is deliberately not the calendar view. A listing of rows omits every virtual
 * occurrence a series implies, which for most calendars is the majority of what a person expects
 * to see. The read that answers "what is on this calendar" is the `project` operation
 * ({@see \Splicewire\Beam\Calendars\Ops\ProjectHorizon}), and this resource exists for the
 * different question of what has actually been written down.
 *
 * Scoping runs through the parent calendar's cascade — an event has no independent audience.
 */
#[ParticleResource(
    key: 'calendar-events',
    backing: CalendarEvent::class,
    data: self::class,
    input: CalendarEventInputData::class,
    filterable: true,
    label: 'Calendar events',
    singularLabel: 'Calendar event',
    group: 'Calendars',
    icon: 'calendar',
    section: 'calendars',
)]
class CalendarEventData extends Data
{
    public function __construct(
        public string $id,
        #[Filterable]
        public string $calendar_id,
        #[Filterable]
        public string $channel,
        #[Filterable]
        public ?string $kind,
        #[Sortable(default: true)]
        #[Filterable]
        public ?string $anchor,
        public ?string $title,
        #[Filterable]
        public ?string $status,
        public ?string $series_id,
        public ?string $recurrence_id,
    ) {}

    /** Visible events are the events of visible calendars — nothing narrower, nothing wider. */
    public static function scope(Builder $query): Builder
    {
        $calendarModel = config('beam.calendars.models.calendar', Calendar::class);

        return $query->whereIn(
            'calendar_id',
            Gate::getPolicyFor($calendarModel)
                ->scopeForUser($calendarModel::query(), Auth::user())
                ->select('id'),
        );
    }

    public static function project(CalendarEvent $event): self
    {
        $payload = $event->payload ?? [];

        return new self(
            id: (string) $event->getKey(),
            calendar_id: (string) $event->calendar_id,
            channel: (string) $event->channel,
            kind: $event->kind,
            anchor: $event->anchor?->toDateString(),
            title: is_string($payload['title'] ?? null) ? $payload['title'] : null,
            status: $event->status,
            series_id: $event->series_id,
            recurrence_id: $event->recurrence_id,
        );
    }
}
