<?php

use Splicewire\Beam\Calendars\Contracts\ChannelSource;
use Splicewire\Beam\Calendars\Registries\ChannelRegistry;
use Splicewire\Beam\Calendars\Registries\EventKindRegistry;
use Splicewire\Beam\Calendars\Registries\RendererRegistry;

it('boots the package and seeds its three registries', function () {
    expect(app(EventKindRegistry::class)->declaredKeys())
        ->toContain('kind.event', 'kind.series', 'kind.ref', 'kind.decommission');

    expect(app(RendererRegistry::class)->formats())->toContain('ics', 'rss');

    // The default channel source IS the registry — nothing else in the package names a concrete one.
    expect(app(ChannelSource::class))->toBeInstanceOf(ChannelRegistry::class);
    expect(app(ChannelSource::class)->ids())->toBe(['default']);
});

it('exposes a lane’s full declared metadata, not only its label', function () {
    // A label-only port forces any consumer wanting order or colour back to the host's storage
    // directly — which is the second reader the port exists to prevent.
    config(['beam.calendars.channels' => [
        'editorial' => ['label' => 'Editorial', 'color' => 'slate', 'order' => 2],
    ]]);

    $source = app(ChannelSource::class);

    expect($source->ids())->toBe(['editorial'])
        ->and($source->label('editorial'))->toBe('Editorial')
        ->and($source->meta('editorial'))->toBe(['label' => 'Editorial', 'color' => 'slate', 'order' => 2])
        ->and($source->meta('gone'))->toBe([])
        ->and($source->label('gone'))->toBeNull();
});
