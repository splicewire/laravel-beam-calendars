<?php

use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarEvent;
use Splicewire\Beam\Calendars\Models\CalendarFiring;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Render\IcsRenderer;
use Splicewire\Beam\Calendars\Render\RssRenderer;

return [
    // Host-overridable model bindings (the model-binding seam). A host that subclasses these
    // points the config at its own class; the services + resources resolve through here.
    'models' => [
        'calendar' => Calendar::class,
        'event' => CalendarEvent::class,
        'series' => CalendarSeries::class,
        'firing' => CalendarFiring::class,
    ],

    /*
     * The SPAWN PORT. `null` is the free tier: a due series records its firing and emits an
     * event, and nothing else happens. An engine (tower) sets this to a class-string that
     * implements Contracts\SpawnDriver and gets to decide what a firing DOES — dispatch a
     * circuit, decommission a composition, whatever it owns.
     *
     * Resolved blind through the container by Scheduling\SpawnDriverResolver, so the driver may
     * declare its own constructor dependencies and this package never learns what they are.
     */
    'spawn_driver' => null,

    /*
     * The CHANNEL SOURCE PORT. `null` reads the `channels` registry below. A multi-tenant host
     * binds Contracts\ChannelSource to resolve lanes per tenant instead.
     */
    'channel_source' => null,

    /*
     * ── Registry storage ──────────────────────────────────────────────────────────────────
     * These three arrays ARE the storage for the package's popcorn registries — not a seed that
     * some private array then owns. Nothing else ever writes them, so the registries are
     * ConfigRegistry subclasses reading through to this repository on every read. That is
     * load-bearing: tower registers its own kinds in packageBooted(), AFTER this package's
     * provider has already run, and a constructor snapshot would freeze them out.
     */

    // Event kinds. A registry MISS is the "unknown kind" condition — there is no class_exists
    // probe and no package interrogating whether a sibling is installed. Tower appends
    // `kind.disclosure-ref` and `kind.run-circuit` from its own provider; a host may append its
    // own kind here with no code in this package changing.
    'event_kinds' => [
        'kind.event' => Splicewire\Beam\Calendars\Data\EventData::class,
        'kind.series' => Splicewire\Beam\Calendars\Data\SeriesData::class,
        'kind.ref' => Splicewire\Beam\Calendars\Data\RefData::class,
        'kind.decommission' => Splicewire\Beam\Calendars\Data\DecommissionData::class,
    ],

    // The lane vocabulary. The `default` seed is what keeps a single-channel calendar
    // collapse-invariant — one lane is a document, two lanes is a timeline.
    'channels' => [
        'default' => ['label' => 'Default'],
    ],

    // Export renderers, keyed by format. Seeded with the two this package ships; a host adds
    // `csv`/`json` here without touching the package.
    'renderers' => [
        'ics' => IcsRenderer::class,
        'rss' => RssRenderer::class,
    ],

    // Mount the calendars/calendar-events/calendar-series particle resources. A host that wires
    // them itself (or a headless site) sets false.
    'register_resources' => true,

    // Route group for the mounted resources (host convention).
    'resources' => [
        'group_prefix' => 'resources',
        'middleware' => ['web', 'auth'],
    ],

    // How far the projection will expand an unbounded rule when the caller names no horizon.
    'default_horizon_days' => 90,

    // Table-prefix note: prefixing is beam core's job — the models and the migration stubs call
    // Beam::tableFor() directly. Table names are deliberately ABSENT from this file: a published
    // config may not name the Beam facade (config resolves pre-boot, so `config:cache` throws),
    // and the static form is silently sort-order dependent — `beam/calendars.php` sorts before
    // `beam/core.php`, so `table_prefix` would not yet be loaded and a retrofit host would get
    // wrongly-prefixed tables with no error at all.
];
