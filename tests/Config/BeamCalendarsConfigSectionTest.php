<?php

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Calendars\Config\BeamCalendarsSection;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionSchema;

/**
 * ADR-0245 Config Section for `beam.calendars`. The section is the declared shape of
 * config/beam/calendars.php: every key is host-ceilinged and deploy-only. Four keys are
 * host-extensible registries whose storage IS this config — `action-handlers`, `event-kinds`,
 * `renderers` and `channels` — so coverage must hold for a POPULATED map, not only the shipped
 * seed (empty for action-handlers, so an unshaped array would be vacuously "covered").
 */
it('validates the package default config through the acceptance gate', function () {
    $config = config('beam.calendars');

    $schema = app(ConfigSectionSchema::class)->for(BeamCalendarsSection::class);

    expect(app(AcceptanceGate::class)->accepts($config, $schema))->toBeTrue();
});

it('covers every shipped key with a clean Host-tier / Deploy-effect declarations walk', function () {
    $config = config('beam.calendars');

    // event-kinds / channels / renderers ship non-empty, so this is not vacuous for them; action-handlers
    // ships empty and is locked non-vacuously by the populated-map test below.
    expect(ConfigSectionCoverage::uncovered(BeamCalendarsSection::class, $config))->toBe([]);

    // Every key is a bare #[ConfigKey] = ceiling: Host, effect: Deploy, carries no secret → no finding.
    expect(ConfigSectionDeclarations::findings(BeamCalendarsSection::class))->toBe([]);
});

it('covers host-populated registry maps by their wire names and still passes the acceptance gate', function () {
    // The four host-extensible maps. A real host (or tower) appends entries beyond the shipped seed:
    //  - action-handlers (wire, #[MapName]) ships EMPTY — the strongest non-vacuous case;
    //  - event-kinds (wire, #[MapName]) ships four generic kinds, tower appends its own;
    //  - renderers (identifier key) ships ics/rss, a host adds csv/json;
    //  - channels (identifier key) ships `default`, a single-tenant host adds lanes — its value is an
    //    `array{label: string}` descended via #[MapValues(BeamCalendarsChannelConfig::class)].
    // Populate each under its WIRE name and assert BOTH readers see it: coverage keys by the wire name
    // (uncovered stays empty, descending into every populated map) and the acceptance gate still
    // validates the populated candidate. This is the non-vacuous lock on the four map shapes.
    $config = config('beam.calendars');

    $config['action-handlers'] = [
        'action.fake' => 'App\\Calendars\\FakeActionHandler',
    ];
    $config['event-kinds'] = array_merge($config['event-kinds'], [
        'kind.fake' => 'App\\Calendars\\FakeEventData',
    ]);
    $config['renderers'] = array_merge($config['renderers'], [
        'csv' => 'App\\Calendars\\FakeCsvRenderer',
    ]);
    $config['channels'] = array_merge($config['channels'], [
        'host' => ['label' => 'Host lane'],
    ]);

    expect(ConfigSectionCoverage::uncovered(BeamCalendarsSection::class, $config))->toBe([]);

    $schema = app(ConfigSectionSchema::class)->for(BeamCalendarsSection::class);
    expect(app(AcceptanceGate::class)->accepts($config, $schema))->toBeTrue();
});
