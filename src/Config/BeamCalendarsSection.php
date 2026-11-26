<?php

namespace Splicewire\Beam\Calendars\Config;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * The `beam.calendars` Config Section (ADR-0245), the declared shape of `config/beam/calendars.php`.
 *
 * beam-calendars owns calendars, their events, recurrence series and the action surface. Every key is
 * deploy-only and host-ceilinged (a bare #[ConfigKey] is `ceiling: Host, effect: Deploy`): the package ships
 * the value and nothing below the host may override it until a key is opted in. Four keys are host-extensible
 * registries whose storage IS this config — `action-handlers`, `event-kinds`, `renderers` and `channels` —
 * so each carries `#[MapValues(...)]` and its coverage must hold for a POPULATED map, not only the shipped seed.
 */
#[ConfigRoot(root: 'beam.calendars', title: 'Beam calendars')]
class BeamCalendarsSection extends ConfigSection
{
    public function __construct(
        #[Title('Models'), Description('The model-binding seam: class-strings for the calendar, event, series and firing Eloquent models. A host that subclasses one points the config at its own class and the services and resources resolve through here.'), ConfigKey]
        public BeamCalendarsModelsConfig $models,
        #[Title('Spawn driver'), Description('The spawn port. `null` is the free tier: a due series records its firing and emits an event and nothing else happens. An engine (tower) sets this to a class-string implementing `Contracts\SpawnDriver`, resolved blind through the container, that decides what a firing DOES (dispatch a circuit, decommission a composition). Host-ceilinged so only a host or an engine it installs may bind it.'), ConfigKey]
        public ?string $spawn_driver,
        #[MapName('action-handlers'), Title('Action handlers'), Description('The executable action kinds and their local transactional execution handlers: a map of action kind -> class-string implementing `Contracts\ActionHandler`. Storage for the `ActionHandlerRegistry`; ships empty, and a host or server adapter registers kinds here. Executable actions are separate from informational event kinds and from the spawn driver. An unregistered kind is simply an absent entry.'), ConfigKey]
        public array $actionHandlers,
        #[Title('Reserved action origins'), Description('Source namespaces owned by server adapters that public action scheduling cannot claim. Ships with `action-series:`; a host adds prefixes it wants to reserve. A registry miss is not involved — this is a deny-list of origin prefixes.'), ConfigKey, ArrayItems('string')]
        public array $reserved_action_origins,
        #[Title('Channel source'), Description('The channel-source port. `null` reads the `channels` registry below. A multi-tenant host binds `Contracts\ChannelSource` to a class-string that resolves lanes per tenant instead. Host-ceilinged.'), ConfigKey]
        public ?string $channel_source,
        #[MapName('event-kinds'), Title('Event kinds'), Description('The vocabulary of what can sit on a date: a map of dotted kind key -> class-string of the typed `Data\...` payload each resolves to. Storage for the `EventKindRegistry`; this package seeds `kind.event`, `kind.series`, `kind.ref` and `kind.decommission`, splicewire/tower appends its own, and a host may append more. A registry MISS is the unknown-kind condition, so no participant probes whether a sibling is installed.'), ConfigKey]
        public array $eventKinds,
        #[Title('Channels'), Description('The lane vocabulary: a map of lane id -> `{label}` display metadata (the `ChannelRegistry` storage). The `default` seed keeps a single-channel calendar collapse-invariant — one lane is a document, two is a timeline. A multi-tenant host binds `channel_source` instead of extending this; a single-tenant host may add lanes here.'), ConfigKey]
        public array $channels,
        #[Title('Renderers'), Description('Export renderers keyed by format token: a map of format -> class-string implementing `Contracts\CalendarRenderer` (the `RendererRegistry` storage). Ships `ics` and `rss`; the format token and the advertised export format are the same string, so a host adds `csv`/`json` here without touching the package.'), ConfigKey]
        public array $renderers,
        #[Title('Register resources'), Description('When true the package mounts the calendars, calendar-events and calendar-series particle resources. A host that wires them itself, or a headless site, sets false.'), ConfigKey]
        public bool $register_resources,
        #[Title('API root'), Description('The package\'s own API root (ADR-0243). `CalendarsRoutes::mount()` and `::mountActions()` register here, inside the host\'s boundary; the legacy `resources.group_prefix` mount stays beside it until ticket 16.'), ConfigKey]
        public string $api_root,
        #[Title('Resources'), Description('The route-group prefix and middleware the mounted particle resources inherit (the legacy `group_prefix` mount). The empty defaults inherit the prefix and middleware of the host route group that mounts them.'), ConfigKey]
        public BeamCalendarsResourcesConfig $resources,
        #[Title('Default horizon days'), Description('How far the projection expands an unbounded recurrence rule when the caller names no horizon. Ships at 90 days.'), ConfigKey]
        public int $default_horizon_days,
    ) {
    }
}
