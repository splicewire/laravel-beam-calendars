<?php

use Rushing\Popcorn\Laravel\PopcornManager;
use Splicewire\Beam\Calendars\Registries\ChannelRegistry;
use Splicewire\Beam\Calendars\Registries\EventKindRegistry;
use Splicewire\Beam\Calendars\Registries\RendererRegistry;

/**
 * The registries have to be REACHABLE through the popcorn index, not merely resolvable as classes.
 * `popcorn:registries` is what an operator reads to find out what a host can be extended with, and
 * a registry that resolves fine but never routes is invisible there.
 */
it('declares each registry with a domain-first, vendor-free dotted root', function (string $class, string $root) {

    $declaration = app($class)->declaration();

    expect((string) $declaration->rootKey())->toBe($root);
})->with([
    [EventKindRegistry::class, 'beam.calendars.event_kinds'],
    [RendererRegistry::class, 'beam.calendars.renderers'],
    [ChannelRegistry::class, 'beam.calendars.channels'],
]);

it('routes a key to the registry that owns it', function () {
    $popcorn = app(PopcornManager::class);

    expect($popcorn->routeTo('beam.calendars.event_kinds.kind.event'))->not->toBeNull()
        ->and($popcorn->routeTo('beam.calendars.renderers.ics'))->not->toBeNull();
});

it('describes the purpose of every registry, because a bare root does not explain itself', function (string $class) {
    expect(app($class)->declaration()->description)->not->toBeNull()->not->toBe('');
})->with([EventKindRegistry::class, RendererRegistry::class, ChannelRegistry::class]);
