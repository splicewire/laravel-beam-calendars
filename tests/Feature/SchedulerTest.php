<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Calendars\Contracts\SpawnDriver;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Calendars\Events\OccurrenceFired;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarFiring;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Scheduling\CalendarScheduler;
use Splicewire\Beam\Calendars\Scheduling\SpawnDriverResolver;
use Splicewire\Beam\Calendars\Tests\Fakes\FakeSpawnDriver;

beforeEach(function () {
    $this->calendar = Calendar::create(['title' => 'Ops', 'slug' => 'ops']);
    $this->series = CalendarSeries::create([
        'calendar_id' => $this->calendar->getKey(),
        'channel' => 'default',
        'anchor' => '2026-01-05',
        'rule' => ['freq' => 'DAILY', 'count' => 3],
        'spawn' => ['mode' => SpawnMode::Generate->value, 'instructions' => 'go'],
    ]);
    $this->now = Carbon::parse('2026-01-07 12:00:00');
});

function scheduler(): CalendarScheduler
{
    return app(CalendarScheduler::class);
}

it('fires every due occurrence once', function () {
    $driver = new FakeSpawnDriver;
    config(['beam.calendars.spawn_driver' => $driver]);

    $fired = scheduler()->sweep($this->now, [$this->calendar]);

    expect($fired)->toHaveCount(3)
        ->and($driver->spawned)->toHaveCount(3)
        ->and(CalendarFiring::query()->where('status', CalendarFiring::STATUS_FIRED)->count())->toBe(3);
});

it('is IDEMPOTENT — a second sweep fires nothing and spawns nothing', function () {
    $driver = new FakeSpawnDriver;
    config(['beam.calendars.spawn_driver' => $driver]);

    scheduler()->sweep($this->now, [$this->calendar]);
    $second = scheduler()->sweep($this->now, [$this->calendar]);

    expect($second)->toBe([])
        ->and($driver->spawned)->toHaveCount(3)
        ->and(CalendarFiring::query()->count())->toBe(3);
});

it('fires only occurrences at or before now, so a catch-up sweep does not run the future', function () {
    config(['beam.calendars.spawn_driver' => new FakeSpawnDriver]);

    $fired = scheduler()->sweep(Carbon::parse('2026-01-06 00:00:00'), [$this->calendar]);

    expect($fired)->toHaveCount(2);
});

it('records and announces a firing with NO driver bound — the free tier still fires', function () {
    Event::fake([OccurrenceFired::class]);
    config(['beam.calendars.spawn_driver' => null]);

    $fired = scheduler()->sweep($this->now, [$this->calendar]);

    expect($fired)->toHaveCount(3)
        ->and(CalendarFiring::query()->where('status', CalendarFiring::STATUS_FIRED)->count())->toBe(3);

    Event::assertDispatchedTimes(OccurrenceFired::class, 3);
});

it('leaves an occurrence unfired when the driver DECLINES it, without consuming the claim as fired', function () {
    $driver = new FakeSpawnDriver(claims: false);
    config(['beam.calendars.spawn_driver' => $driver]);

    $fired = scheduler()->sweep($this->now, [$this->calendar]);

    // The claim is still taken and resolved — declining is not failing — but nothing was spawned.
    expect($driver->spawned)->toBe([])
        ->and($fired)->toHaveCount(3)
        ->and(CalendarFiring::query()->where('status', CalendarFiring::STATUS_FIRED)->count())->toBe(3);
});

it('marks a firing FAILED when the driver throws, and does not abort the rest of the sweep', function () {
    config(['beam.calendars.spawn_driver' => new FakeSpawnDriver(throws: new RuntimeException('boom'))]);

    $fired = scheduler()->sweep($this->now, [$this->calendar]);

    expect($fired)->toBe([])
        ->and(CalendarFiring::query()->where('status', CalendarFiring::STATUS_FAILED)->count())->toBe(3)
        ->and(CalendarFiring::query()->first()->result['error'])->toBe('boom');
});

it('KEEPS the failed claim, so the next sweep does not silently retry forever', function () {
    config(['beam.calendars.spawn_driver' => new FakeSpawnDriver(throws: new RuntimeException('boom'))]);
    scheduler()->sweep($this->now, [$this->calendar]);

    $driver = new FakeSpawnDriver;
    config(['beam.calendars.spawn_driver' => $driver]);
    $second = scheduler()->sweep($this->now, [$this->calendar]);

    expect($second)->toBe([])
        ->and($driver->spawned)->toBe([])
        ->and(CalendarFiring::query()->count())->toBe(3);
});

it('refuses the claim a second time at the DATABASE, not in PHP', function () {
    // The guarantee has to survive two processes that both passed an application-level check.
    config(['beam.calendars.spawn_driver' => new FakeSpawnDriver]);
    scheduler()->sweep($this->now, [$this->calendar]);

    CalendarFiring::query()->create([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'series_id' => $this->series->getKey(),
        'recurrence_id' => '2026-01-05',
    ]);
})->throws(Illuminate\Database\QueryException::class);

// ── the resolver ─────────────────────────────────────────────────────────────────────────────

it('resolves no driver by default, which is the free tier and not an error', function () {
    config(['beam.calendars.spawn_driver' => null]);

    expect(app(SpawnDriverResolver::class)->resolve())->toBeNull();
});

it('resolves a class-string through the container so a driver can have dependencies', function () {
    config(['beam.calendars.spawn_driver' => FakeSpawnDriver::class]);

    expect(app(SpawnDriverResolver::class)->resolve())->toBeInstanceOf(SpawnDriver::class);
});

it('THROWS on the explicit path when nothing is configured', function () {
    config(['beam.calendars.spawn_driver' => null]);

    app(SpawnDriverResolver::class)->resolveOrFail();
})->throws(RuntimeException::class, 'No calendar spawn driver is configured');

it('rejects a configured class that does not implement the port', function () {
    config(['beam.calendars.spawn_driver' => Calendar::class]);

    app(SpawnDriverResolver::class)->resolve();
})->throws(RuntimeException::class);
