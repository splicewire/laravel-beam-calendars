<?php

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Calendars\Registries\RendererRegistry;
use Splicewire\Beam\Calendars\Render\CalendarExporter;

beforeEach(function () {
    $this->calendar = Calendar::create(['title' => 'Editorial, Inc.', 'slug' => 'editorial']);
    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.event',
        'anchor' => '2026-01-06',
        'payload' => ['title' => 'Launch; day one'],
    ]);
    $this->horizon = Horizon::between('2026-01-01', '2026-01-31');
    $this->exporter = app(CalendarExporter::class);
});

it('renders an ICS document with the right media type', function () {
    $doc = $this->exporter->export($this->calendar, 'ics', $this->horizon);

    expect($doc['contentType'])->toBe('text/calendar')
        ->and($doc['body'])->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and($doc['body'])->toEndWith("END:VCALENDAR\r\n")
        ->and($doc['body'])->toContain('BEGIN:VEVENT');
});

it('gives an all-day VEVENT an EXCLUSIVE DTEND on the following day', function () {
    // Same-day or omitted DTEND renders as a zero-length event in most clients.
    $body = $this->exporter->export($this->calendar, 'ics', $this->horizon)['body'];

    expect($body)->toContain('DTSTART;VALUE=DATE:20260106')
        ->and($body)->toContain('DTEND;VALUE=DATE:20260107');
});

it('escapes RFC-5545 TEXT specials without double-escaping its own backslashes', function () {
    $body = $this->exporter->export($this->calendar, 'ics', $this->horizon)['body'];

    expect($body)->toContain('SUMMARY:Launch\; day one')
        ->and($body)->toContain('X-WR-CALNAME:Editorial\, Inc.');
});

it('EXPANDS a series in the exported feed rather than exporting one event on its start date', function () {
    CalendarSeries::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'anchor' => '2026-01-05',
        'rule' => ['freq' => 'DAILY', 'count' => 4],
        'spawn' => ['mode' => SpawnMode::Generate->value, 'instructions' => 'Daily'],
    ]);

    $body = $this->exporter->export($this->calendar, 'ics', $this->horizon)['body'];

    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(5); // 4 virtual + 1 stored
});

it('keeps a series instance UID stable across materialization', function () {
    $series = CalendarSeries::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'anchor' => '2026-01-05',
        'rule' => ['freq' => 'DAILY', 'count' => 1],
        'spawn' => ['mode' => SpawnMode::Generate->value, 'instructions' => 'Daily'],
    ]);

    $before = $this->exporter->export($this->calendar, 'ics', $this->horizon)['body'];

    CalendarEvent::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'kind' => 'kind.series',
        'anchor' => '2026-01-05',
        'series_id' => $series->getKey(),
        'recurrence_id' => '2026-01-05',
    ]);

    $after = $this->exporter->export($this->calendar->refresh(), 'ics', $this->horizon)['body'];
    $uid = 'UID:'.$series->getKey().'-20260105@'.$this->calendar->getKey();

    // A subscriber must not see the instance vanish and reappear as an unrelated new event.
    expect($before)->toContain($uid)->and($after)->toContain($uid);
});

it('renders RSS with the right media type and a non-permalink guid', function () {
    $doc = $this->exporter->export($this->calendar, 'rss', $this->horizon);

    expect($doc['contentType'])->toBe('application/rss+xml')
        ->and($doc['body'])->toContain('<rss version="2.0">')
        ->and($doc['body'])->toContain('isPermaLink="false"')
        ->and($doc['body'])->toContain('Launch; day one'); // XML-escaped, not ICS-escaped
});

it('derives the format list from the registry rather than a second list beside it', function () {
    expect($this->exporter->formats())->toBe(['ics', 'rss']);

    app(RendererRegistry::class); // registry is read-through, so a config append is simply visible
    config(['beam.calendars.renderers' => [
        'ics' => Splicewire\Beam\Calendars\Render\IcsRenderer::class,
        'rss' => Splicewire\Beam\Calendars\Render\RssRenderer::class,
        'csv' => Splicewire\Beam\Calendars\Render\IcsRenderer::class,
    ]]);

    expect($this->exporter->formats())->toContain('csv');
});

it('answers an unknown format as a bad request that NAMES what is available', function () {
    try {
        $this->exporter->export($this->calendar, 'pdf', $this->horizon);
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['format'][0])->toContain('pdf')->toContain('ics|rss');
    }
});
