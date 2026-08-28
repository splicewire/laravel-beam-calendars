<?php

use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;
use Splicewire\Beam\Calendars\Data\CalendarEventInputData;
use Splicewire\Beam\Calendars\Data\CalendarSeriesInputData;
use Splicewire\Beam\Calendars\Data\MaterializeInputData;
use Splicewire\Beam\Calendars\Data\ProjectedEventData;
use Splicewire\Beam\Calendars\Data\SkipInputData;

/**
 * The package's WIRE CONTRACT, pinned.
 *
 * ⚠️ These assertions are about published keys, not about PHP style. They exist because the
 * package shipped with neither axis declared, and the two disagreed: with the host's global
 * `input => CamelCaseMapper` and `output => null`, `ProjectedEventData` EMITTED `calendar_id`
 * while `CalendarEventInputData` DEMANDED `calendarId`. Read one, write the other.
 *
 * Nothing reported it. The estate has an audit for "you did not declare your column map" and
 * none for "you did not declare your wire name" — the strictly more load-bearing of the two —
 * so this test is the only thing standing between the contract and the next global mapper change.
 *
 * The direction is snake_case on both axes, matching the engine tier this was extracted from and
 * the packaged TS calendar surface, which maps the projection DTO "with zero casing translation".
 *
 * Because both axes are now DECLARED, the PHP property spelling is free — which is what makes the
 * camelCase properties a pure style choice rather than a silent contract change. Renaming a
 * property must not move a key here; if it does, the attribute is missing.
 */
function wireIn(string $class, string $property): ?string
{
    // Mirror the host: a package harness defaults to no mapper, which is the exact condition
    // under which a missing declaration looks fine and then changes meaning inside a real app.
    config(['data.name_mapping_strategy.input' => CamelCaseMapper::class]);

    foreach (app(DataConfig::class)->getDataClass($class)->properties as $p) {
        if ($p->name === $property) {
            return $p->inputMappedName ?? $p->name;
        }
    }

    return null;
}

function wireOut(string $class, string $property): ?string
{
    foreach (app(DataConfig::class)->getDataClass($class)->properties as $p) {
        if ($p->name === $property) {
            return $p->outputMappedName ?? $p->name;
        }
    }

    return null;
}

it('publishes snake_case WRITE keys, under the host mapper that would otherwise camel them', function (string $class, string $property) {
    expect(wireIn($class, $property))->toBe('calendar_id');
})->with([
    [CalendarEventInputData::class, 'calendarId'],
    [CalendarSeriesInputData::class, 'calendarId'],
]);

it('publishes snake_case for every multi-word write key', function () {
    expect(wireIn(CalendarEventInputData::class, 'seriesId'))->toBe('series_id')
        ->and(wireIn(CalendarEventInputData::class, 'recurrenceId'))->toBe('recurrence_id');
});

it('publishes snake_case READ keys, so read and write name the same field the same way', function () {
    expect(wireOut(ProjectedEventData::class, 'calendarId'))->toBe('calendar_id')
        ->and(wireOut(ProjectedEventData::class, 'seriesRef'))->toBe('series_ref')
        ->and(wireOut(ProjectedEventData::class, 'recurrenceId'))->toBe('recurrence_id');
});

it('round-trips a snake_case payload through a write DTO', function () {
    config(['data.name_mapping_strategy.input' => CamelCaseMapper::class]);

    $data = CalendarEventInputData::from([
        'calendar_id' => 'cal-1',
        'channel' => 'default',
        'kind' => 'kind.event',
        'anchor' => '2026-01-05',
        'series_id' => 's-1',
        'recurrence_id' => '2026-01-05',
    ]);

    expect($data->toModelAttributes())->toBe([
        'calendar_id' => 'cal-1',
        'channel' => 'default',
        'kind' => 'kind.event',
        'anchor' => '2026-01-05',
        'series_id' => 's-1',
        'recurrence_id' => '2026-01-05',
    ]);
});

/**
 * The OPERATION input DTOs are on the same contract as the resource write DTOs — added when
 * api-surface-coherence 121 gave `materialize`/`skip` an explicit `input:`. They are the shapes
 * whose keys the handlers' own `$request->validate()` calls already spell in snake_case, so an
 * undeclared camel mapping here would 422 every request the op has ever accepted.
 */
it('publishes snake_case keys for the occurrence operation inputs', function () {
    expect(wireIn(MaterializeInputData::class, 'seriesId'))->toBe('series_id')
        ->and(wireIn(MaterializeInputData::class, 'recurrenceId'))->toBe('recurrence_id')
        ->and(wireIn(SkipInputData::class, 'seriesId'))->toBe('series_id')
        ->and(wireIn(SkipInputData::class, 'recurrenceId'))->toBe('recurrence_id');
});
