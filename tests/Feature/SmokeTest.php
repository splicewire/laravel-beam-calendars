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
