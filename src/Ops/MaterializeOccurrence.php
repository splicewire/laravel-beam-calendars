<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Splicewire\Beam\Calendars\Data\CalendarEventData;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Ops\Concerns\ResolvesOccurrences;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * Pin one virtual instance of a series into a real row — RFC-5545 RECURRENCE-ID.
 *
 * This is how a recurring thing gets edited without breaking the recurrence: the series keeps its
 * rule, and one instance gains an independent row that the projection then prefers over the virtual
 * twin. The supersession is a lookup on `(series_id, recurrence_id)`, which is why the pinned row
 * can be MOVED to another date and still suppress its original — the identity is the recurrence,
 * not the date.
 *
 * Idempotent: pinning an already-pinned instance returns the existing row rather than creating a
 * second one, which the partial unique index would refuse anyway.
 */
#[ParticleOp(
    resource: 'calendars',
    name: 'materialize',
    kind: OperationKind::Write,
    model: Calendar::class,
    ability: 'update',
    output: CalendarEventData::class,
)]
class MaterializeOccurrence
{
    use ResolvesOccurrences;

    public static function handle(Calendar $calendar, Request $request, mixed $actor = null): CalendarEventData
    {
        $data = $request->validate([
            'series_id' => ['required', 'string'],
            'recurrence_id' => ['required', 'string'],
            'anchor' => ['nullable', 'date'],
            'title' => ['nullable', 'string'],
        ]);

        $series = self::seriesOn((string) $calendar->getKey(), $data['series_id']);
        $occurrence = self::occurrenceOf($series, $data['recurrence_id']);

        $event = CalendarEvent::query()->firstOrNew([
            'series_id' => $series->getKey(),
            'recurrence_id' => $occurrence->recurrenceId,
        ]);

        $event->forceFill([
            'id' => $event->getKey() ?? (string) Str::uuid(),
            'calendar_id' => $calendar->getKey(),
            'channel' => $occurrence->channel,
            'kind' => 'kind.series',
            // The pin may MOVE the instance; absent an explicit anchor it lands on its computed date.
            'anchor' => $data['anchor'] ?? $occurrence->anchor,
            'payload' => array_filter([
                'title' => $data['title'] ?? null,
                'spawn' => $occurrence->spawn->toArray(),
            ], static fn (mixed $v): bool => $v !== null),
        ])->save();

        return CalendarEventData::project($event->refresh());
    }
}
