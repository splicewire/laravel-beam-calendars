<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Authorization\RowAuthorization;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Data\BeamData;
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
/**
 * ⚠️ The wire keys are DECLARED below, which is what makes the camelCase property spelling a
 * style choice rather than a silent contract change.
 *
 * Under the host's global `input => CamelCaseMapper` / `output => null`, an UNDECLARED DTO
 * publishes whatever the global mapper happens to produce. This package shipped with neither
 * axis declared, so its read side emitted `calendar_id` while its write side demanded
 * `calendarId` — read one key, write another, with nothing reporting it. `WireNameTest` now
 * asserts the published keys directly.
 */
#[TypeScript]
class CalendarEventData extends BeamData
{
    public function __construct(
        public string $id,
        #[Filterable(Exact::class)]
        #[MapName('calendar_id')]
        public string $calendarId,
        #[Filterable(Exact::class)]
        public string $channel,
        #[Filterable(Exact::class)]
        public ?string $kind,
        #[Sortable(default: true)]
        #[Filterable(Exact::class)]
        public ?string $anchor,
        public ?string $title,
        #[Filterable(Exact::class)]
        public ?string $status,
        #[MapName('series_id')]
        public ?string $seriesId,
        #[MapName('recurrence_id')]
        public ?string $recurrenceId,
    ) {}

    /**
     * Visible events are the events of visible calendars — nothing narrower, nothing wider.
     *
     * The parent narrowing rides {@see RowAuthorization} — the row plane of authorization as one
     * named idiom (registry-kernel 72). This site previously called
     * `Gate::getPolicyFor($calendarModel)->scopeForUser(…)` with no null check and so fataled at any
     * host that binds no policy for the resolved calendar model; the idiom fails CLOSED there
     * instead, which for this `whereIn` subquery means an empty parent set and so no events.
     */
    public static function scope(Builder $query): Builder
    {
        $calendarModel = config('beam.calendars.models.calendar', Calendar::class);

        return $query->whereIn(
            'calendar_id',
            RowAuthorization::apply($calendarModel::query(), $calendarModel)->select('id'),
        );
    }

    public static function project(CalendarEvent $event): self
    {
        $payload = $event->payload ?? [];

        return new self(
            id: (string) $event->getKey(),
            calendarId: (string) $event->calendar_id,
            channel: (string) $event->channel,
            kind: $event->kind,
            anchor: $event->anchor?->toDateString(),
            title: is_string($payload['title'] ?? null) ? $payload['title'] : null,
            status: $event->status,
            seriesId: $event->series_id,
            recurrenceId: $event->recurrence_id,
        );
    }
}
