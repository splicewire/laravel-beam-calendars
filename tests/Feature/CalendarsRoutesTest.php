<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\ActionSeriesResources;
use Splicewire\Beam\Calendars\Resources;
use Splicewire\Beam\Calendars\Routing\CalendarsRoutes;

// owner-review-20261112 13d (ADR-0243): the package mounts at its own root, beam/calendars, beside the mount the host
// makes today. The host's group is the boundary; legacy routes keep their URIs and names until ticket 16.

beforeEach(function () {
    app()->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
});

$match = fn (string $uri, string $method = 'GET') => app(Router::class)->getRoutes()->match(Request::create($uri, $method));
$uris = fn () => array_map(fn ($route) => $route->uri(), iterator_to_array(app(Router::class)->getRoutes()));

it('registers the default root beside the legacy mount, inside the host boundary', function () use ($match) {
    Resources::register(['group_prefix' => 'resources', 'middleware' => []]);
    $legacy = $match('/resources/calendars');

    Route::domain('workspace.example')->middleware('auth')->group(fn () => CalendarsRoutes::mount());
    $current = $match('http://workspace.example/beam/calendars/calendars');

    expect($current->getActionName())->toBe($legacy->getActionName())
        ->and($current->gatherMiddleware())->toContain('auth')
        ->and($current->getDomain())->toBe('workspace.example')
        ->and($current->getName())->toStartWith('beam.calendars.')
        ->and($match('/resources/calendars'))->toBe($legacy);

    // The whole table, not a sample: every legacy route (calendars, events, series and the calendar ops) answers at
    // the package root with the same method, path and action, and nothing extra appears there.
    expect(routeTable('beam/calendars/'))->toBe(routeTable('resources/'))
        ->and(count(routeTable('beam/calendars/')))->toBeGreaterThan(15);

    $this->getJson('http://workspace.example/beam/calendars/calendars')->assertUnauthorized();
});

/** method + path-under-root => action, for every route whose URI starts with $root; sorted. */
function routeTable(string $root): array
{
    $table = [];
    foreach (app(Router::class)->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), $root)) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $table[$method.' '.substr($route->uri(), strlen($root))] = $route->getActionName();
        }
    }
    ksort($table);

    return $table;
}

it('mounts calendar-actions only when the host asks, as it does today', function () use ($match, $uris) {
    Route::prefix('api/beam')->group(fn () => ActionResources::mount());
    CalendarsRoutes::mount();

    expect($uris())->not->toContain('beam/calendars/calendar-actions');

    CalendarsRoutes::mountActions();
    $current = $match('/beam/calendars/calendar-actions');

    expect($current->getActionName())->toBe($match('/api/beam/calendar-actions')->getActionName())
        ->and($current->getName())->toStartWith('beam.calendars.');
});

// review-r1 REVISE at 05a4305: the package also declares calendar-action-series and ships
// ActionSeriesResources::mount(), an optional host mount like calendar-actions.
it('mounts calendar-action-series at the package root only when the host asks, with its whole table', function () use ($uris) {
    Route::prefix('api/beam')->group(fn () => ActionSeriesResources::mount());
    CalendarsRoutes::mount();
    CalendarsRoutes::mountActions();

    expect(array_filter($uris(), fn (string $uri) => str_starts_with($uri, 'beam/calendars/calendar-action-series')))->toBeEmpty();

    Route::domain('workspace.example')->middleware('auth')->group(fn () => CalendarsRoutes::mountActionSeries());
    $series = array_filter(routeTable('beam/calendars/'), fn (string $key) => str_contains($key, 'calendar-action-series'), ARRAY_FILTER_USE_KEY);
    $legacy = array_filter(routeTable('api/beam/'), fn (string $key) => str_contains($key, 'calendar-action-series'), ARRAY_FILTER_USE_KEY);

    // The whole series table (index, show and the seven ops) by method, path and action.
    expect($series)->toBe($legacy)
        ->and(count($series))->toBeGreaterThanOrEqual(9);

    $index = app(Router::class)->getRoutes()->match(Request::create('http://workspace.example/beam/calendars/calendar-action-series'));
    expect($index->gatherMiddleware())->toContain('auth')
        ->and($index->getDomain())->toBe('workspace.example')
        ->and($index->getName())->toStartWith('beam.calendars.');
    $this->getJson('http://workspace.example/beam/calendars/calendar-action-series')->assertUnauthorized();
});

it('moves the action-series mount with an overridden root', function () use ($uris) {
    Route::prefix('api/beam')->group(fn () => ActionSeriesResources::mount());
    config(['beam.calendars.api_root' => '/custom/cal/']);

    CalendarsRoutes::mountActionSeries();

    $custom = array_filter(routeTable('custom/cal/'), fn (string $key) => str_contains($key, 'calendar-action-series'), ARRAY_FILTER_USE_KEY);
    $legacy = array_filter(routeTable('api/beam/'), fn (string $key) => str_contains($key, 'calendar-action-series'), ARRAY_FILTER_USE_KEY);
    expect($custom)->toBe($legacy)
        ->and(array_filter($uris(), fn (string $uri) => str_starts_with($uri, 'beam/calendars/')))->toBeEmpty();
});

it('moves only the package root when the host overrides it', function () use ($match, $uris) {
    Resources::register(['group_prefix' => 'resources', 'middleware' => []]);
    config(['beam.calendars.api_root' => '/custom/cal/']);

    CalendarsRoutes::mount();

    expect($match('/custom/cal/calendars')->getActionName())->toBe($match('/resources/calendars')->getActionName())
        ->and($uris())->not->toContain('beam/calendars/calendars');
});
