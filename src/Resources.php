<?php

namespace Splicewire\Beam\Calendars;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Calendars\Data\CalendarData;
use Splicewire\Beam\Calendars\Data\CalendarEventData;
use Splicewire\Beam\Calendars\Data\CalendarSeriesData;
use Splicewire\Beam\Calendars\Ops\ExportCalendar;
use Splicewire\Beam\Calendars\Ops\MaterializeOccurrence;
use Splicewire\Beam\Calendars\Ops\ProjectHorizon;
use Splicewire\Beam\Calendars\Ops\SkipOccurrence;
use Splicewire\Beam\Calendars\Ops\SweepCalendar;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * Register + mount the calendar particle surface. Reads are the declarative resources discovered
 * here; every operation is a single-purpose class in `src/Ops/` (ADR-0160/HTTP-10 — one class per
 * operation, so the logic lives ONCE and the declaration sits next to it).
 *
 * ⚠️ The guard is not defensive clutter — it is what lets this package be a package. Without beam's
 * route macros present (a headless environment, or this package's own testbench suite, which has no
 * router configured for particles) `Particle::mount()` would fatal at boot. Returning early means
 * the package boots and its unit tests exercise projection, expansion and the op handlers directly,
 * while a real host with beam installed gets the full surface.
 */
class Resources
{
    /**
     * DECLARE the three resources into the registries.
     *
     * ⚠️ **Separate from {@see register()}, and that separation is the whole point.** Declaration is a
     * fact about this package — it declares three resources whether or not anything routes them.
     * Mounting is a fact about the process — it needs beam's route macros.
     *
     * They used to be one method behind one guard, and the guard was `Route::hasMacro()`. Measured at
     * `~/Herd/splicewire-app` on 2026-08-28: that macro is **false in console context**, so the host
     * had 41 registered particle resources and **none of them were this package's**. Nothing reported
     * it, and downstream the codegen manifest never saw them either — which presented as
     * `typescript:transform` emitting zero types and looked for hours like a codegen bug.
     *
     * Beam hit the same thing and says so at `BeamServiceProvider:1710`: the estate-wide
     * `discover_paths` points at the HOST's `app_path('Data')`, which a package class can never be
     * inside, so a package must declare its own classes explicitly.
     *
     * Idempotent — registration is keyed, so declaring twice registers the same three.
     */
    public static function declare(): void
    {
        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent (a headless install).
        }

        app(AttributedParticleDiscovery::class)->discover([
            CalendarData::class,
            CalendarEventData::class,
            CalendarSeriesData::class,
        ]);
    }

    /**
     * MOUNT the declared surface onto HTTP.
     *
     * This one may legitimately no-op: mounting needs beam's route macros, and a headless environment
     * or the package's own suite has none. What must NOT no-op with it is {@see declare()}.
     */
    public static function register(array $opts = []): void
    {
        self::declare();

        if (! class_exists(ParticleOperationRegistry::class) || ! Route::hasMacro('particleResource')) {
            return; // no router macros — declared but not mounted, which is a valid state.
        }

        $groupPrefix = $opts['group_prefix'] ?? config('beam.calendars.resources.group_prefix', 'resources');
        $middleware = $opts['middleware'] ?? config('beam.calendars.resources.middleware', ['web', 'auth']);

        Route::middleware($middleware)->prefix($groupPrefix)->group(function (): void {
            Particle::mount('calendars', 'calendars');
            Particle::mount('calendar-events', 'calendar-events');
            Particle::mount('calendar-series', 'calendar-series');

            // `Particle::ops()` both DISCOVERS (registers) the attributed ops and mounts them at
            // `calendars/{id}/op/{name}`, so there is no separate registration step to forget.
            Particle::ops('calendars', 'calendars', [
                ProjectHorizon::class,
                ExportCalendar::class,
                SweepCalendar::class,
                MaterializeOccurrence::class,
                SkipOccurrence::class,
            ]);
        });
    }
}
