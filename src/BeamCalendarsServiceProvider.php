<?php

namespace Splicewire\Beam\Calendars;

use Rushing\PermissionCascade\Support\CascadePolicyRegistrar;
use Rushing\Popcorn\Registries\RegistryIndex;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Calendars\Contracts\ChannelSource;
use Splicewire\Beam\Calendars\Doctor\BeamCalendarsMigrationsAudit;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Registries\ChannelRegistry;
use Splicewire\Beam\Calendars\Registries\EventKindRegistry;
use Splicewire\Beam\Calendars\Registries\RendererRegistry;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The calendar substrate: dated events, recurrence series, an exactly-once firing ledger, an ICS
 * and RSS export, and a declarative particle surface over all three — on beam-core, with no
 * composition engine, no scheduler vendor and no AI anywhere in the dependency graph.
 *
 * What an ENGINE adds sits behind two ports ({@see Contracts\SpawnDriver},
 * {@see ChannelSource}) and three registries, all of which this package seeds and none of which it
 * requires anyone to fill. A host with nothing bound has a complete, usable calendar; that is the
 * free tier, not a degraded mode.
 */
class BeamCalendarsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // Migrations ship PUBLISH-ONLY (spatie/laravel-package-tools defaults runsMigrations to
        // FALSE — no override here), the estate-wide convention.
        //
        // ⚠️ The declared ORDER is load-bearing. package-tools stamps timestamps one second apart
        // in listed order, and calendar_events references both a calendar and a series, so a
        // publish that ran them alphabetically would create the child before its parents.
        //
        // They live under `database/migrations/shared/` — the fleet's shared-by-default ruling:
        // migrations and models are central/tenant AGNOSTIC unless explicitly determined otherwise
        // and noted at the site. These four are deliberately agnostic, so a publish lands them in
        // the host's `database/migrations/shared/`, the path beam-tenancy's
        // registerSharedMigrationsPath() runs on BOTH passes.
        $package
            ->name('laravel-beam-calendars')
            ->hasConfigFile('beam/calendars')
            ->hasMigrations([
                'shared/create_calendars_table',
                'shared/create_calendar_series_table',
                'shared/create_calendar_events_table',
                'shared/create_calendar_firings_table',
            ]);
    }

    public function packageRegistered(): void
    {
        // The three registries are singletons so that a late registration — an engine's provider
        // booting after this one — lands on the same instance every reader resolves. They are
        // ConfigRegistry subclasses, so they also read THROUGH to the config repository on every
        // read rather than snapshotting, which is the half that actually makes late registration
        // visible; the singleton binding just avoids rebuilding them per resolve.
        $this->app->scoped(EventKindRegistry::class);
        $this->app->scoped(RendererRegistry::class);
        $this->app->scoped(ChannelRegistry::class);

        // The default channel source IS the registry. An engine rebinds this interface to something
        // tenant-aware; nothing else in the package ever reaches for a concrete channel source.
        $this->app->bind(ChannelSource::class, function ($app) {
            $configured = config('beam.calendars.channel_source');

            return $configured === null
                ? $app->make(ChannelRegistry::class)
                : $app->make($configured);
        });
    }

    public function packageBooted(): void
    {
        // The models' #[UseCascadePolicy] attributes are the ENTIRE authorization surface — the
        // registrar wires them onto the Gate. No Policy class ships in this package at all.
        CascadePolicyRegistrar::register(Calendar::class);
        CascadePolicyRegistrar::register(CalendarEvent::class);
        CascadePolicyRegistrar::register(CalendarSeries::class);

        // Mount the particle surface. Guarded internally on the beam particle infra, so this is a
        // no-op in a headless env or the standalone package test.
        if (config('beam.calendars.register_resources', true)) {
            Resources::register();
        }

        // The three registries this package owns, described from the OWNER's own boot — a registry
        // describes itself; nobody describes on another's behalf. Without this they resolve fine as
        // classes and are INVISIBLE to `popcorn:registries`, which is the one place an operator
        // looks to find out what a host can be extended with. Guarded because the index is
        // popcorn's, and a host predating it still boots.
        if ($this->app->bound(RegistryIndex::class)) {
            $index = $this->app->make(RegistryIndex::class);

            foreach ([EventKindRegistry::class, RendererRegistry::class, ChannelRegistry::class] as $registry) {
                $index->describe($this->app->make($registry), by: self::class);
            }
        }

        // Self-register into beam-core's install manifest so `splicewire:beam:install` publishes
        // this package's shared migrations with the rest of the stack, and into the doctor manifest
        // so the stub-drift audit covers it. Both `bound()`-guarded so an older host still boots.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-calendars',
                publishTags: ['beam-calendars-config', 'beam-calendars-migrations'],
                migrates: true,
            );
        }

        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-calendars',
                BeamCalendarsMigrationsAudit::class,
            );
        }
    }
}
