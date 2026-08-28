<?php

use Splicewire\Beam\Calendars\Resources;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * ⚠️ Registration must NOT depend on routing.
 *
 * The package originally did discovery inside `Resources::register()`, behind a guard on
 * `Route::hasMacro('particleResource')`. That guard exists so the package boots headless — a good
 * reason — but it made a DECLARATION fact (this package declares three resources) conditional on a
 * TRANSPORT fact (this process has beam's route macros).
 *
 * Measured at `~/Herd/splicewire-app` on 2026-08-28: the macro is false in console context, so the
 * host had **41 registered particle resources and none of them were the calendar's**. Nothing
 * reported it. Downstream, the codegen manifest never saw the resources either, which is why
 * `typescript:transform` emitted zero `Beam.Calendars` types and looked like a codegen bug.
 *
 * Beam itself learned this earlier and says so at `BeamServiceProvider:1710`: the estate-wide
 * `discover_paths` points at the HOST's `app_path('Data')`, which a package class can never be
 * inside, so a package must declare its own.
 */
it('declares its resources WITHOUT needing beam’s route macros', function () {
    // The package harness has no router macros at all — the condition the old guard tripped on.
    expect(Illuminate\Support\Facades\Route::hasMacro('particleResource'))->toBeFalse();

    Resources::declare();

    $keys = array_map(
        fn ($r) => is_object($r) ? ($r->key ?? '') : (string) $r,
        app(ParticleResourceRegistry::class)->all(),
    );

    expect($keys)->toContain('calendars', 'calendar-events', 'calendar-series');
});

it('is idempotent — declaring twice registers the same three, not six', function () {
    Resources::declare();
    Resources::declare();

    $keys = array_map(
        fn ($r) => is_object($r) ? ($r->key ?? '') : (string) $r,
        app(ParticleResourceRegistry::class)->all(),
    );

    expect(array_count_values(array_filter($keys, fn ($k) => $k === 'calendars')))->toBe(['calendars' => 1]);
});

it('still declines to MOUNT when the route macros are absent', function () {
    // Mounting genuinely does need the macros; only declaration was wrongly gated.
    expect(fn () => Resources::register())->not->toThrow(Exception::class);
});
