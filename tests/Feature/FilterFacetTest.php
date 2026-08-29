<?php

use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Reflection\FilterReflector;
use Splicewire\Beam\Calendars\Data\CalendarData;
use Splicewire\Beam\Calendars\Data\CalendarEventData;
use Splicewire\Beam\Calendars\Data\CalendarSeriesData;

/**
 * The declared list facets of the three framed calendar resources.
 *
 * ⚠️ These DTOs shipped with a BARE `#[Filterable]`, and `Filterable::__construct()` has always
 * required the operator class-string — there is no default. Nothing in this package's suite ever
 * INSTANTIATED the attribute, so PHP never evaluated the argument list and the suite stayed green
 * while every consumer that reflects these classes died with an `ArgumentCountError`: the flagship's
 * OpenAPI parameter-coverage test, and the JSON-Schema generator behind frame's edit form (3 of the
 * flagship's 44 particle resources could not produce a schema at all, and all three were these).
 *
 * The lesson is the shape rather than the bug: an attribute is inert source text until something
 * calls `newInstance()`, so "the class loads" and "the class is green" both prove nothing about it.
 * This test instantiates every one of them through the reflector the runtime actually uses.
 */
it('instantiates every declared filter facet', function (string $class, array $expected): void {
    $filters = (new FilterReflector)->allowedFilters($class);

    expect(array_map(fn ($filter) => $filter->getName(), $filters))->toBe($expected);
})->with([
    'calendars' => [CalendarData::class, ['title', 'slug', 'visibility']],
    'calendar-events' => [CalendarEventData::class, ['calendarId', 'channel', 'kind', 'anchor', 'status']],
    'calendar-series' => [CalendarSeriesData::class, ['calendarId', 'channel', 'anchor']],
]);

/**
 * Every facet narrows by exact equality — the operator is asserted rather than left implied,
 * because `filter[…]`'s semantics are a published wire contract and widening one later (an
 * `Exact` becoming a `Partial`) is a contract change that should have to edit this line.
 */
it('declares every calendar facet as an exact match', function (string $class): void {
    $properties = (new ReflectionClass($class))->getProperties();

    $operators = [];

    foreach ($properties as $property) {
        foreach ($property->getAttributes(Filterable::class) as $attribute) {
            $operators[$property->getName()] = $attribute->newInstance()->operator;
        }
    }

    expect($operators)->not->toBeEmpty()
        ->and(array_unique(array_values($operators)))->toBe([Rushing\DataFilters\Operators\Exact::class]);
})->with([
    CalendarData::class,
    CalendarEventData::class,
    CalendarSeriesData::class,
]);

/**
 * The wire key is the camelCase property; the COLUMN stays snake. `calendarId` is the one facet
 * in this package where the two differ, so it is the one that proves the mapping is live.
 */
it('narrows calendarId by the snake column', function (): void {
    $names = array_map(
        fn ($filter) => $filter->getName(),
        (new FilterReflector)->allowedFilters(CalendarEventData::class),
    );

    expect($names)->toContain('calendarId')->not->toContain('calendar_id');
});
