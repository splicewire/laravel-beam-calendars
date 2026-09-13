<?php

namespace Splicewire\Beam\Calendars;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Support\CascadePolicyRegistrar;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Calendars\Actions\ActionPolicy;
use Splicewire\Beam\Calendars\Actions\ActionSeriesPolicy;
use Splicewire\Beam\Calendars\Contracts\ChannelSource;
use Splicewire\Beam\Calendars\Doctor\BeamCalendarsMigrationsAudit;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry;
use Splicewire\Beam\Calendars\Registries\ChannelRegistry;
use Splicewire\Beam\Calendars\Registries\EventKindRegistry;
use Splicewire\Beam\Calendars\Registries\RendererRegistry;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;

/**
 * The calendar substrate: dated events, recurrence series, an exactly-once firing ledger, an ICS
 * and RSS export, and a declarative particle surface over all three — on beam-core, with no
 * composition engine, no scheduler vendor and no AI anywhere in the dependency graph.
 *
 * Informational calendars use the spawn and channel ports plus their kind/renderer registries.
 * Executable actions add a separate transactional handler and trusted host-context port. Both
 * extension paths are optional; a host with nothing bound still has a usable calendar.
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
        // and noted at the site. These tables are deliberately agnostic, so a publish lands them in
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
                'shared/create_calendar_actions_table',
                'shared/create_calendar_action_attempts_table',
                'shared/create_calendar_action_series_table',
            ]);
    }

    public function packageRegistered(): void
    {
        // The registries are scoped so that a late registration — an engine's provider
        // booting after this one — lands on the same instance every reader resolves. They are
        // ConfigRegistry subclasses, so they also read THROUGH to the config repository on every
        // read rather than snapshotting, which is the half that actually makes late registration
        // visible; the scoped binding avoids rebuilding them per resolve within a request/job.
        $this->app->scoped(EventKindRegistry::class);
        $this->app->scoped(ActionHandlerRegistry::class);
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
        // Informational calendar models use cascade policy attributes. Executable action models
        // use the separate host-context policies registered below.
        CascadePolicyRegistrar::register(Calendar::class);
        CascadePolicyRegistrar::register(CalendarEvent::class);
        CascadePolicyRegistrar::register(CalendarSeries::class);

        // ⛔ The morph aliases for those same three models — ADR-0118, whose amended decision 5 is
        // "registration follows ownership": the package that declares a model's policy registers that
        // model's alias, from its own provider. This package declared three policies and shipped ZERO
        // `morphMap()` calls, so all three were leaking their FQCN out of `getMorphClass()` and
        // `PermissionNamer` was slugging it into `splicewirebeamcalendarsmodelscalendar.view` — the exact
        // defect ADR-0118 exists to prevent. They were 3 of the 5 policied-but-unaliased models estate-wide.
        //
        // Additive (`morphMap($map, true)`), never `enforceMorphMap` — 20+ class-string morphs elsewhere in
        // the estate would orphan, which `beam-particle-rename` 03 rejected explicitly and that rejection
        // still stands.
        //
        // The keys are the SNAKE_CASE SHORT NAME of the class, not the table: these live on `beam_calendars`
        // / `beam_calendar_events` / `beam_calendar_series`, and the `beam_` table prefix is not alias
        // vocabulary. Measured against the estate's existing 106 aliases, where `EvidenceItem` on table
        // `determination_evidence` is keyed `evidence_item`.
        //
        // Safe to add: zero live polymorphic rows store any of these three classes or these three keys
        // (swept across all 827 `*_type` columns in 19 schemas), no subclass of any of them exists, and all
        // three keys are free in the booted map.
        Relation::morphMap([
            'calendar' => Calendar::class,
            'calendar_event' => CalendarEvent::class,
            'calendar_series' => CalendarSeries::class,
        ], true);

        // DECLARE the particle surface — registration only, never mounting. Guarded internally on the
        // beam particle infra, so this is a no-op in a headless env or the standalone package test.
        //
        // ⚠️ **This called `Resources::register()` until registry-kernel ticket 70, and the difference
        // is 21 routes.** While the mount half sat behind a `Route::hasMacro('particleResource')` probe
        // that api-surface-coherence 93 had turned into an unconditional early return, the two calls
        // were indistinguishable. Deleting that dead probe made them differ: `register()` publishes
        // `resources/calendars*` under this package's `['web','auth']` default, BESIDE the
        // `api/v1/calendars` surface the flagship already serves from `routes/tenant.php` under a
        // seven-layer tenancy stack — two live roots for one resource, one of them with no tenancy
        // initialization at all.
        //
        // Mounting is the HOST's call here, which `routes/tenant.php` says in terms. It stays that way
        // until [registry-kernel 71] settles how a package NAMES a tenancy stack it cannot know —
        // beam-tenancy registers no middleware alias or group today, and eight of the nine hosts that
        // install this package have no tenancy at all. `Resources::register([...])` remains available
        // for a host that wants the package to mount with its own prefix and middleware.
        if (config('beam.calendars.register_resources', true)) {
            Resources::declare();
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

        ActionResources::declare();
        ActionSeriesResources::declare();
        Gate::policy(CalendarActionSeries::class, ActionSeriesPolicy::class);
        Relation::morphMap(['calendar_action_series' => CalendarActionSeries::class], true);
        Gate::policy(CalendarAction::class, ActionPolicy::class);
        Relation::morphMap(['calendar_action' => CalendarAction::class], true);

        $this->registerNavSection();
    }

    /**
     * Seat this package's own `calendars` section — the half of its nav declaration that could not be
     * written until {@see NavSectionRegistry} existed.
     *
     * ## What was broken
     *
     * {@see Data\CalendarData}, {@see Data\CalendarEventData} and {@see Data\CalendarSeriesData} have all
     * declared `section: 'calendars'` since they were written. That says which section they attach UNDER;
     * it cannot bring the section into being, and no host in the estate seats the string `calendars`. So
     * all three were correctly declared and invisible — 3 of the 11 unseated sections measured across the
     * family on 2026-09-05. The seat below is the missing half, and it is declarable HERE because it is a
     * fact this package knows: the section's own key, label and icon are ours. Ordering stays advisory
     * and the host may reorder or supersede it wholesale.
     *
     * The rule (api-surface-coherence 142): *"A fact is declarable when the declaring party is the one
     * that knows it. When only the host can know it, a list is the honest form — and the list must
     * compose."*
     *
     * ## Both realms, and that is not sloppiness
     *
     * Which realm the calendar resources live in is the HOST's `config/frame.realms` list, not this
     * package's — the same beam-ux resources sit in `operator` at the flagship and in `tenant` at the
     * beam starter, and this package ships no opinion either way. A package that guessed one realm would
     * be invisible at every host that chose the other. Declaring both is safe because
     * `FrameNavContribution` DROPS a contributed seat with no children, so the realm where these
     * resources do not live renders nothing rather than a dead header.
     *
     * `user` is deliberately not among them: a calendar admin list is not an account-settings surface,
     * and a seat nobody would want is not made harmless by being dropped.
     *
     * ## ⚠️ The key is `calendars`, plural, and renaming it to `calendar` was RULED AGAINST
     *
     * The flagship already has a `calendar` seat and it is a curated STANDALONE page
     * (`~/Herd/splicewire-app/app/Frame/RouteContextBuilder.php:66`, `TENANT_STANDALONE`). Aligning the
     * two strings would attach these three admin list rows INTO that page's seat — a silent
     * regression at the one host that has a calendar surface today. The two words are two things.
     *
     * ## Ungated, written out
     *
     * `entitlement: null, permission: null` are passed explicitly because an omission and a decision must
     * not be spelled the same. The seat carries no gate of its own: the resources under it are
     * `viewAny`-gated one by one and an empty seat is dropped, so an unauthorized reader loses the
     * section because its contents went — the same answer a seat-level gate gives, with one fewer place
     * for the two to disagree.
     *
     * ⚠️ Gate vocabulary here is a permission token or beam-core's `entitlement:{key}` Gate plane, and
     * nothing else. `Splicewire\Tower\Navigation\Gates\EntitlementNavGateStage` constructor-injects
     * classes that are absent at a bare beam host, so naming it would turn a hidden seat into a
     * container failure at nav-build time.
     *
     * `bound()`-guarded, exactly as the two manifest registrations above are: whether beam-core is new
     * enough to bind the registry is a fact about the host, and such a check reports an absence rather
     * than fataling a boot.
     */
    protected function registerNavSection(): void
    {
        if (! $this->app->bound(NavSectionRegistry::class)) {
            return;
        }

        $sections = $this->app->make(NavSectionRegistry::class);

        foreach (['operator', 'tenant'] as $realm) {
            $sections->register(
                new NavSection(
                    key: 'calendars',
                    realm: $realm,
                    label: 'Calendars',
                    icon: 'CalendarDays',
                    href: '/calendars',
                    // Between beam-ux's `authoring` (30) and `ops` (80) — calendars are content a
                    // reader works in, not operations they supervise. Advisory: the host may reorder.
                    order: 50,
                    entitlement: null,
                    permission: null,
                ),
                by: 'splicewire/laravel-beam-calendars',
            );
        }
    }
}
