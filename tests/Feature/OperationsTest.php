<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Calendars\Jobs\SweepCalendarJob;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Ops\ExportCalendar;
use Splicewire\Beam\Calendars\Ops\MaterializeOccurrence;
use Splicewire\Beam\Calendars\Ops\ProjectHorizon;
use Splicewire\Beam\Calendars\Ops\SkipOccurrence;
use Splicewire\Beam\Calendars\Ops\SweepCalendar;

beforeEach(function () {
    $this->calendar = Calendar::create(['title' => 'Ops', 'slug' => 'ops']);
    $this->series = CalendarSeries::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'anchor' => '2026-01-05',
        'rule' => ['freq' => 'DAILY', 'count' => 3],
        'spawn' => ['mode' => SpawnMode::Generate->value, 'instructions' => 'go'],
    ]);
});

function req(array $query = [], array $body = []): Request
{
    return Request::create('/', $body === [] ? 'GET' : 'POST', $query + $body);
}

it('projects the horizon as wire DTOs', function () {
    $out = ProjectHorizon::handle($this->calendar, req(['from' => '2026-01-01', 'to' => '2026-01-31']));

    expect($out)->toHaveCount(3)
        ->and($out[0]->anchor)->toBe('2026-01-05')
        ->and($out[0]->virtual)->toBeTrue()
        ->and($out[0]->id)->toBeNull()
        ->and($out[0]->calendarId)->toBe((string) $this->calendar->getKey());
});

it('exports through the operation with the renderer’s own media type', function () {
    $response = ExportCalendar::handle($this->calendar, req([
        'format' => 'ics', 'from' => '2026-01-01', 'to' => '2026-01-31',
    ]));

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($response->headers->get('Content-Disposition'))->toContain('ops.ics')
        ->and($response->getContent())->toContain('BEGIN:VCALENDAR');
});

it('returns a queueable JOB from the Task op, not a payload', function () {
    expect(SweepCalendar::handle($this->calendar, req()))->toBeInstanceOf(SweepCalendarJob::class);
});

it('answers the Task op with its DECLARED shape, not a bare queued flag', function () {
    // Without respond(), a Task answers `{queued: true}` and the output: declaration is a lie.
    $out = SweepCalendar::respond($this->calendar, true);

    expect($out->calendarId)->toBe((string) $this->calendar->getKey())
        ->and($out->queued)->toBeTrue()
        ->and($out->firedCount)->toBe(0);
});

// ── materialize / skip ───────────────────────────────────────────────────────────────────────

it('pins a virtual occurrence into a real row', function () {
    $out = MaterializeOccurrence::handle($this->calendar, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
        'title' => 'Pinned',
    ]));

    expect($out->recurrenceId)->toBe('2026-01-06')
        ->and($out->title)->toBe('Pinned')
        ->and(CalendarEvent::query()->count())->toBe(1);
});

it('is idempotent — pinning twice returns the same row', function () {
    $args = ['series_id' => (string) $this->series->getKey(), 'recurrence_id' => '2026-01-06'];

    $a = MaterializeOccurrence::handle($this->calendar, req([], $args));
    $b = MaterializeOccurrence::handle($this->calendar, req([], $args));

    expect($b->id)->toBe($a->id)->and(CalendarEvent::query()->count())->toBe(1);
});

it('lets a pin MOVE the instance off its computed date', function () {
    $out = MaterializeOccurrence::handle($this->calendar, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
        'anchor' => '2026-02-01',
    ]));

    expect($out->anchor)->toBe('2026-02-01')->and($out->recurrenceId)->toBe('2026-01-06');
});

it('writes a skip as an override ON THE SERIES, as a list', function () {
    SkipOccurrence::handle($this->calendar, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
    ]));

    $overrides = $this->series->refresh()->overrides;

    expect($overrides)->toBe([['recurrence_id' => '2026-01-06', 'action' => 'skip']])
        ->and(array_is_list($overrides))->toBeTrue();
});

it('does not duplicate an override when the same instance is skipped twice', function () {
    $args = ['series_id' => (string) $this->series->getKey(), 'recurrence_id' => '2026-01-06'];

    SkipOccurrence::handle($this->calendar, req([], $args));
    SkipOccurrence::handle($this->calendar, req([], $args));

    expect($this->series->refresh()->overrides)->toHaveCount(1);
});

it('REFUSES a series that belongs to another calendar', function () {
    $other = Calendar::create(['title' => 'Other', 'slug' => 'other']);

    MaterializeOccurrence::handle($other, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
    ]));
})->throws(ValidationException::class);

it('REFUSES a recurrence date the rule never generates', function () {
    // A well-formed date the series does not produce would otherwise write an override that can
    // never match anything — silently, with a 200.
    MaterializeOccurrence::handle($this->calendar, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-04',
    ]));
})->throws(ValidationException::class);

it('can still PIN an instance that has already been skipped — an override is not a one-way door', function () {
    // Regression: existence was originally validated against the OVERRIDE-APPLIED expansion, so a
    // skipped instance reported as "generated by no rule" and could never be restored or re-pinned.
    SkipOccurrence::handle($this->calendar, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
    ]));

    $out = MaterializeOccurrence::handle($this->calendar, req([], [
        'series_id' => (string) $this->series->getKey(),
        'recurrence_id' => '2026-01-06',
        'title' => 'Restored',
    ]));

    expect($out->recurrenceId)->toBe('2026-01-06')->and($out->title)->toBe('Restored');
});

// ── the declared `input:` axis (api-surface-coherence 121) ───────────────────────────────────

/**
 * ⚠️ These are the tests that make declaring `input:` safe rather than merely tidy.
 *
 * `ParticleOperationController::validateInput()` runs `$input::validate($request->all())` BEFORE the
 * handler, so a declared DTO that disagrees with the handler's own `$request->validate()` turns
 * every request the op has ever accepted into a 422 — and the handler tests above would not notice,
 * because they call `handle()` directly and never pass through the controller. What is pinned here
 * is the AGREEMENT between the two: the payloads the handler tests send must survive the gate.
 */
it('validates, under the declared input DTO, the exact payloads the handlers accept', function () {
    config(['data.name_mapping_strategy.input' => Spatie\LaravelData\Mappers\CamelCaseMapper::class]);

    $ref = ['series_id' => (string) $this->series->getKey(), 'recurrence_id' => '2026-01-06'];

    Splicewire\Beam\Calendars\Data\MaterializeInputData::validate($ref + ['title' => 'Pinned']);
    Splicewire\Beam\Calendars\Data\MaterializeInputData::validate($ref + ['anchor' => '2026-02-01']);
    Splicewire\Beam\Calendars\Data\MaterializeInputData::validate($ref);
    Splicewire\Beam\Calendars\Data\SkipInputData::validate($ref);
    // A sweep with no `at` is the common case — "fire what is due now" — so the declaration must
    // not make the single field required.
    Splicewire\Beam\Calendars\Data\SweepInputData::validate([]);
    Splicewire\Beam\Calendars\Data\SweepInputData::validate(['at' => '2026-01-06']);

    expect(true)->toBeTrue();
});

it('REFUSES an occurrence operation missing its recurrence coordinate', function () {
    config(['data.name_mapping_strategy.input' => Spatie\LaravelData\Mappers\CamelCaseMapper::class]);

    expect(fn () => Splicewire\Beam\Calendars\Data\SkipInputData::validate([
        'series_id' => (string) $this->series->getKey(),
    ]))->toThrow(ValidationException::class);
});
