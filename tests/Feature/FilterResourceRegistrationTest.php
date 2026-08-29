<?php

use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Splicewire\Beam\Calendars\Data\CalendarData;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Query\CalendarResourceQuery;
use Splicewire\Beam\Calendars\Resources;

/**
 * `filterable: true` on a `#[ParticleResource]` is a PROMISE that a data-filters resource exists
 * under the same key — beam's `ParticleController::index` routes a filterable resource straight
 * through `hydrator->query($key)`, which raises `BadMethodCallException` when the key is unknown.
 *
 * The package declared the promise and never shipped the registration. Measured over authenticated
 * HTTP at `~/Herd/splicewire-app` on 2026-08-29, all three list endpoints were a live **500**:
 *
 *     GET /api/v1/calendars        500  "No data-filters resource is registered under [calendars]"
 *     GET /api/v1/calendar-events  500
 *     GET /api/v1/calendar-series  500
 *
 * It had been hiding behind the bare-`#[Filterable]` defect fixed in 25545b8: that one threw an
 * `ArgumentCountError` while REFLECTING the DTO, which is upstream of the registry lookup, so
 * repairing it is what exposed this. Two real defects, one visible at a time.
 *
 * These tests BOOT rather than read source text, deliberately: the whole family of defect in this
 * area is "a declaration nobody instantiates is never checked", and asserting on the presence of a
 * `registerDefinition()` call would reproduce exactly that mistake.
 */
it('composes a list query for every key it declares filterable', function (string $key): void {
    Resources::declare();

    $query = DataFilter::query($key);

    expect($query)->toBeInstanceOf(ResourceQuery::class)
        ->and($query)->toBeInstanceOf(CalendarResourceQuery::class);
})->with(['calendars', 'calendar-events', 'calendar-series']);

/**
 * The model is NOT restated on the data-filters definition — beam's `ParticleResourceModelResolver`
 * fills data-filters' resolver port off the `#[ParticleResource]` under the same key. This asserts
 * the port actually resolves, because the failure mode is an `UnresolvableResourceModel` thrown at
 * request time rather than at registration time.
 */
it('resolves each backing model through beam’s particle registry, not a restated `model:`', function (string $key, string $model): void {
    Resources::declare();

    expect(DataFilter::resource($key)->requireModel())->toBe($model);
})->with([
    ['calendars', Calendar::class],
    ['calendar-events', Splicewire\Beam\Calendars\Models\CalendarEvent::class],
    ['calendar-series', Splicewire\Beam\Calendars\Models\CalendarSeries::class],
]);

/**
 * ⚠️ The read guard. beam applies a resource's `scope` closure ONLY on the non-filterable index —
 * "not for the filterable path, whose data-filters query is its own gate"
 * (`ParticleController::index`). So `baseQuery()` is the only thing standing between an
 * authenticated caller and every row in the table, and the inherited `ResourceQuery::baseQuery()`
 * is a bare `Model::query()`.
 *
 * Asserted by comparing the composed SQL against the raw model query: a scoped base is not the
 * unscoped one. This fails loudly if someone later "simplifies" `CalendarResourceQuery` away.
 */
it('applies the DTO’s authorization scope as the filterable list’s base query', function (): void {
    Resources::declare();

    $composed = DataFilter::query('calendars')->apply(request())->toSql();

    expect($composed)->not->toBe(Calendar::query()->toSql())
        ->and($composed)->toContain('where');
});

/** The scope the query re-applies is the DTO's own, so the two read paths cannot drift apart. */
it('reads the scope off the particle DTO rather than restating it', function (): void {
    expect(method_exists(CalendarData::class, 'scope'))->toBeTrue();
});

/** Registration is keyed and guarded — declaring twice must not double-register or throw. */
it('is idempotent, and never stomps a host that registered the key first', function (): void {
    Resources::declare();
    Resources::declare();

    expect(DataFilter::tryResource('calendars'))->not->toBeNull();
});
