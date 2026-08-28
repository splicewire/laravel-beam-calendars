<?php

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Data\EventData;
use Splicewire\Beam\Calendars\Registries\EventKindRegistry;
use Splicewire\Beam\Calendars\Write\KindValidator;

it('seeds the four generic kinds and nothing engine-specific', function () {
    $keys = app(EventKindRegistry::class)->declaredKeys();

    expect($keys)->toBe(['kind.event', 'kind.series', 'kind.ref', 'kind.decommission'])
        // The free tier must not know these exist — they are contributed by the engine.
        ->not->toContain('kind.disclosure-ref')
        ->not->toContain('kind.run-circuit');
});

it('lets a LATE registrant contribute a kind, which is how the engine adds without being named', function () {
    // Simulates tower's provider booting after this package's own. The registry reads through to
    // the config repository on every read, so a snapshot-at-construction would fail this.
    config(['beam.calendars.event_kinds' => array_merge(
        config('beam.calendars.event_kinds'),
        ['kind.run-circuit' => EventData::class],
    )]);

    expect(app(EventKindRegistry::class)->declaredKeys())->toContain('kind.run-circuit')
        ->and(app(EventKindRegistry::class)->tryResolve('kind.run-circuit'))->toBe(EventData::class);
});

it('validates a payload through the class its kind resolves to', function () {
    $out = app(KindValidator::class)->validate('kind.event', [
        'channel' => 'default',
        'anchor' => '2026-01-06',
        'title' => 'Launch',
    ]);

    expect($out['kind'])->toBe('kind.event')->and($out['title'])->toBe('Launch');
});

it('REFUSES an unknown kind on write, naming the ones that are registered', function () {
    try {
        app(KindValidator::class)->validate('kind.nope', ['channel' => 'default']);
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['kind'][0])
            ->toContain('kind.nope')
            // Naming the registered set is what tells a caller their engine is not installed.
            ->toContain('kind.event');
    }
});

it('refuses a payload that does not satisfy its declared kind', function () {
    app(KindValidator::class)->validate('kind.event', ['channel' => 'default']); // no anchor, no title
})->throws(Exception::class);
