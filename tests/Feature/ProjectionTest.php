<?php

use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Projection\CalendarProjection;
use Splicewire\Beam\Calendars\Projection\Horizon;

beforeEach(function () {
    $this->calendar = Calendar::create(['title' => 'Editorial', 'slug' => 'editorial']);

    $this->series = CalendarSeries::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'anchor' => '2026-01-05',
        'rule' => ['freq' => 'DAILY', 'count' => 5],
        'spawn' => ['mode' => SpawnMode::Generate->value, 'instructions' => 'Daily piece'],
    ]);

    $this->projection = new CalendarProjection;
    $this->horizon = Horizon::between('2026-01-01', '2026-01-31');
});

it('merges stored rows and virtual occurrences into one ordered list', function () {
    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.event',
        'anchor' => '2026-01-06',
        'payload' => ['title' => 'A standalone event'],
    ]);

    $events = $this->projection->events($this->calendar, $this->horizon);

    expect($events)->toHaveCount(6) // 5 virtual + 1 stored
        ->and(array_map(fn ($e) => $e->anchor, $events))->toBe([
            '2026-01-05', '2026-01-06', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09',
        ]);
});

it('lets a materialized row SUPERSEDE its virtual twin so the instance appears once', function () {
    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.series',
        'anchor' => '2026-01-06',
        'series_id' => $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
        'payload' => ['title' => 'Pinned'],
    ]);

    $events = $this->projection->events($this->calendar, $this->horizon);
    $onThatDay = array_values(array_filter($events, fn ($e) => $e->anchor === '2026-01-06'));

    expect($events)->toHaveCount(5)
        ->and($onThatDay)->toHaveCount(1)
        ->and($onThatDay[0]->virtual)->toBeFalse()
        ->and($onThatDay[0]->title)->toBe('Pinned');
});

it('still suppresses the virtual twin when the pinned row has been MOVED off its computed date', function () {
    // This is the case a date-matching supersession gets wrong, and the reason the pair is stored
    // as columns: the identity is the RECURRENCE, not the date the rule computed for it.
    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.series',
        'anchor' => '2026-01-20', // moved two weeks out
        'series_id' => $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
        'payload' => ['title' => 'Moved'],
    ]);

    $events = $this->projection->events($this->calendar, $this->horizon);
    $dates = array_map(fn ($e) => $e->anchor, $events);

    expect($events)->toHaveCount(5)
        ->and($dates)->toContain('2026-01-20')
        // 2026-01-06 was the computed date; its virtual twin must NOT reappear.
        ->and(array_count_values($dates)['2026-01-06'] ?? 0)->toBe(0);
});

it('suppresses a virtual twin whose pinned row sits OUTSIDE the requested horizon', function () {
    // The materialized-set query is deliberately unscoped by horizon. Scoping it would resurrect
    // exactly the duplicate the pin exists to remove.
    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.series',
        'anchor' => '2026-06-01', // far outside the window below
        'series_id' => $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
    ]);

    $events = $this->projection->events($this->calendar, Horizon::between('2026-01-01', '2026-01-10'));

    expect(array_map(fn ($e) => $e->anchor, $events))->not->toContain('2026-01-06');
});

it('marks virtual occurrences as having no write target', function () {
    $events = $this->projection->events($this->calendar, $this->horizon);

    expect($events[0]->virtual)->toBeTrue()
        ->and($events[0]->id)->toBeNull()
        ->and($events[0]->status)->toBe('upcoming')
        ->and($events[0]->recurrenceId)->toBe('2026-01-05');
});

it('applies the horizon START, which the expander does not bound', function () {
    $events = $this->projection->events($this->calendar, Horizon::between('2026-01-07', '2026-01-31'));

    expect(array_map(fn ($e) => $e->anchor, $events))->toBe(['2026-01-07', '2026-01-08', '2026-01-09']);
});

it('reports only DUE occurrences that have no row yet', function () {
    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.series',
        'anchor' => '2026-01-06',
        'series_id' => $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
    ]);

    $due = $this->projection->due($this->series, Horizon::upTo(Illuminate\Support\Carbon::parse('2026-01-07')));

    expect(array_map(fn ($o) => $o->recurrenceId, $due))->toBe(['2026-01-05', '2026-01-07']);
});
