<?php

namespace Splicewire\Beam\Calendars\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\Resources;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Routing\ApiRoot;

/**
 * The calendars package's routes at its own root (ADR-0243, owner-review-20261112 13d): `beam.calendars.api_root`,
 * default `beam/calendars`. The host calls these inside its authentication and tenant boundary, beside the legacy
 * `Resources::register()` / `ActionResources::mount()` it calls today, which stay until ticket 16. The package adds no
 * middleware here: the host's group is the boundary. Route names start `beam.calendars.`.
 */
final class CalendarsRoutes
{
    /** Calendars, events, series and the calendar ops: what `Resources::register()` mounts today. */
    public static function mount(array $opts = []): void
    {
        Resources::declare();

        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent (a headless install), as Resources::register().
        }

        $idConstraint = $opts['idConstraint'] ?? 'uuid';

        ApiRoot::mount(app(Router::class), 'beam.calendars.api_root', 'calendars', function (Router $router) use ($idConstraint): void {
            $router->name('beam.calendars.')->group(fn () => Resources::routes($idConstraint));
        });
    }

    /**
     * The calendar-actions resource and its ops: what `ActionResources::mount()` mounts today. Separate because a
     * host mounts it only once it binds `ActionContextProvider`; a host that does not call it today gains no route.
     */
    public static function mountActions(string $uri = 'calendar-actions'): void
    {
        ApiRoot::mount(app(Router::class), 'beam.calendars.api_root', 'calendars', function (Router $router) use ($uri): void {
            $router->name('beam.calendars.')->group(fn () => ActionResources::mount($uri));
        });
    }
}
