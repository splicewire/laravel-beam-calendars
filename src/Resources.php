<?php

namespace Splicewire\Beam\Calendars;

use Illuminate\Support\Facades\Route;
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

        // This package's own declaration roots, scanned rather than named: `src/Data` holds the three
        // `#[ParticleResource]` DTOs, `src/Ops` the five `#[ParticleOp]` classes. The ops used to reach
        // the registry ONLY at route-mount time (`Particle::ops()` discovers as it mounts), so in a
        // console context — where nothing mounts — this package declared three things and beam's op
        // registry knew of none of them. Declaring both here makes the declaration true of the process
        // rather than of the request, which is the same correction the `declare()`/`register()` split
        // above already made for the resources.
        app(AttributedParticleDiscovery::class)->discover(paths: [
            __DIR__.'/Data',
            __DIR__.'/Ops',
        ]);

    }

    /**
     * MOUNT the declared surface onto HTTP.
     *
     * ⚠️ **The `Route::hasMacro('particleResource')` half of this guard was deleted by registry-kernel
     * ticket 70, and the docblock it stood on was wrong by then.** api-surface-coherence 93 deleted all
     * six particle route macros on 2026-08-27 and moved their bodies into
     * {@see \Splicewire\Beam\Particle\Mount\ParticleMounter}, which takes an INJECTED `Router` — so
     * the headless-fatal this guard existed to prevent cannot happen any more. Measured, not reasoned:
     * running this body past the guard in a package testbench with no particle macros mounts the full
     * surface and does not fatal. `class_exists()` answers the only live question, which is whether beam
     * is installed at all.
     *
     * Left as a warning rather than deleted silently, because the guard read as correct for two days
     * while being an unconditional early return: 93 converted the CALL SITES below and did not re-read
     * the guard three lines above them. A guard is not a call site.
     */
    public static function register(array $opts = []): void
    {
        self::declare();

        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent (a headless install).
        }

        // ⚠️ **Both default to EMPTY (registry-kernel 71), and that is the design rather than an
        // omission.** Laravel MERGES a nested group's middleware into its parent's rather than
        // replacing it, so a package that names its own stack APPENDS to the host's instead of
        // choosing: called from inside the flagship's tenant group, the former `['web','auth']` default
        // produced `api, TenancyInit, auth:sanctum, web, auth` — a session-starting `web` on an API
        // route and the session guard beside `auth:sanctum`. Declaring nothing makes central-vs-tenant
        // fall out of WHERE the host calls from, which is the one place that knows. An empty group is
        // inert: measured to inherit the ambient stack exactly, with no stray path segment.
        $groupPrefix = $opts['group_prefix'] ?? config('beam.calendars.resources.group_prefix', '');
        $middleware = $opts['middleware'] ?? config('beam.calendars.resources.middleware', []);
        // A fact about THIS package's models, not about the host: `Calendar`, `CalendarEvent`,
        // `CalendarSeries` and `CalendarFiring` all `use HasUuids`. The flagship was passing
        // `->idConstraint('uuid')` by hand because the package never asserted what it already knew
        // (registry-kernel 70 Q1). Overridable for a host that keys these differently.
        $idConstraint = $opts['idConstraint'] ?? 'uuid';

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () use ($idConstraint): void {
            Particle::mount('calendars', 'calendars')->idConstraint($idConstraint);
            Particle::mount('calendar-events', 'calendar-events')->idConstraint($idConstraint);
            Particle::mount('calendar-series', 'calendar-series')->idConstraint($idConstraint);

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
