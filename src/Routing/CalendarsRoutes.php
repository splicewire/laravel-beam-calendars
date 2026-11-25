<?php

namespace Splicewire\Beam\Calendars\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\ActionSeriesResources;
use Splicewire\Beam\Calendars\Data\CalendarData;
use Splicewire\Beam\Calendars\Data\CalendarEventData;
use Splicewire\Beam\Calendars\Data\CalendarSeriesData;
use Splicewire\Beam\Calendars\Resources;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\ApiRoot;

/**
 * The calendars package's routes at its own root (ADR-0243, owner-review-20261112 13d): `beam.calendars.api_root`,
 * default `beam/calendars`. The host calls these inside its authentication and tenant boundary, beside the legacy
 * `Resources::register()` / `ActionResources::mount()` it calls today, which stay until ticket 16. The package adds no
 * middleware here: the host's group is the boundary. Route names start `beam.calendars.`.
 *
 * A host that contributes its own verbs to these resources (a package declares a verb; the host decides whether to
 * expose it) names them in `$ops`, and they mount at this root beside the package's own (owner-review-20261112 61).
 * The names are bare op names, mounted only: each must already be registered on the resource, and an unregistered
 * name fails at mount, naming the fix (`Particle::ops()`).
 */
final class CalendarsRoutes
{
    /**
     * Calendars, events, series and the calendar ops: what `Resources::register()` mounts today.
     *
     * @param  array{idConstraint?: string, ops?: list<string>}  $opts  `ops`: host-contributed op names on `calendars`
     */
    public static function mount(array $opts = []): void
    {
        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent (a headless install), as Resources::register().
        }

        $registry = app(ParticleResourceRegistry::class);
        foreach ([
            'calendars' => CalendarData::class,
            'calendar-events' => CalendarEventData::class,
            'calendar-series' => CalendarSeriesData::class,
        ] as $key => $data) {
            if (! $registry->has($key)) {
                app(AttributedParticleDiscovery::class)->discover([$data]);
            }
        }
        if (! $registry->has('calendar-actions')) {
            ActionResources::declare();
        }
        if (! $registry->has('calendar-action-series')) {
            ActionSeriesResources::declare();
        }

        $idConstraint = $opts['idConstraint'] ?? 'uuid';
        $ops = $opts['ops'] ?? [];

        ApiRoot::mount(app(Router::class), 'beam.calendars.api_root', 'calendars', function (Router $router) use ($idConstraint, $ops): void {
            $router->name('beam.calendars.')->group(function () use ($idConstraint, $ops): void {
                Resources::routes($idConstraint);
                if ($ops !== []) {
                    Particle::ops('calendars', 'calendars', $ops);
                }
            });
        });
    }

    /**
     * The calendar-actions resource and its ops: what `ActionResources::mount()` mounts today. Separate because a
     * host mounts it only once it binds `ActionContextProvider`; a host that does not call it today gains no route.
     *
     * @param  list<string>  $ops  host-contributed op names on `calendar-actions`
     */
    public static function mountActions(string $uri = 'calendar-actions', array $ops = []): void
    {
        ApiRoot::mount(app(Router::class), 'beam.calendars.api_root', 'calendars', function (Router $router) use ($uri, $ops): void {
            $router->name('beam.calendars.')->group(function () use ($uri, $ops): void {
                ActionResources::mount($uri);
                if ($ops !== []) {
                    Particle::ops($uri, 'calendar-actions', $ops);
                }
            });
        });
    }

    /**
     * The calendar-action-series resource and its ops: what `ActionSeriesResources::mount()` mounts today. Optional for
     * the same reason as `mountActions()`: a host that does not mount action series today gains no route.
     */
    public static function mountActionSeries(string $uri = 'calendar-action-series'): void
    {
        ApiRoot::mount(app(Router::class), 'beam.calendars.api_root', 'calendars', function (Router $router) use ($uri): void {
            $router->name('beam.calendars.')->group(fn () => ActionSeriesResources::mount($uri));
        });
    }
}
