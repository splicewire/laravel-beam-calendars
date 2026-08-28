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
    public static function register(array $opts = []): void
    {
        if (! class_exists(ParticleOperationRegistry::class) || ! Route::hasMacro('particleResource')) {
            return; // beam particle infra absent — nothing to mount.
        }

        $groupPrefix = $opts['group_prefix'] ?? config('beam.calendars.resources.group_prefix', 'resources');
        $middleware = $opts['middleware'] ?? config('beam.calendars.resources.middleware', ['web', 'auth']);

        app(AttributedParticleDiscovery::class)->discover([
            CalendarData::class,
            CalendarEventData::class,
            CalendarSeriesData::class,
        ]);

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
