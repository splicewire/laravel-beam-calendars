<?php

use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Resources;
use Splicewire\Beam\Filters\ResourceFilterDefinition;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

it('derives filter definitions and backing models from the declared calendar resources', function (string $key, string $model): void {
    Resources::declare();

    $resolver = app(ResourceFilterDefinition::class);
    $definition = $resolver->definition($key);

    expect($definition)->not->toBeNull()
        ->and($definition->requireModel())->toBe($model)
        ->and($resolver->query($definition)->filterNames())->not->toBeEmpty();
})->with([
    ['calendars', Calendar::class],
    ['calendar-events', Splicewire\Beam\Calendars\Models\CalendarEvent::class],
    ['calendar-series', Splicewire\Beam\Calendars\Models\CalendarSeries::class],
]);

it('applies the resource authorization scope when composing its filtered list', function (): void {
    Resources::declare();

    $resource = app(ParticleResourceRegistry::class)->get('calendars');
    $query = app(ParticleListQuery::class)->forList($resource, ['title' => 'Private'], Illuminate\Http\Request::create('/', 'GET', ['filter' => ['title' => 'Private']]));

    expect($query->toSql())->not->toBe(Calendar::query()->toSql())
        ->and($query->toSql())->toContain('where')
        ->and($query->getBindings())->toContain('Private')
        ->and($query->count())->toBe(0);
});

it('declares calendar resources idempotently', function (): void {
    Resources::declare();
    Resources::declare();

    expect(app(ResourceFilterDefinition::class)->definition('calendars'))->not->toBeNull();
});
