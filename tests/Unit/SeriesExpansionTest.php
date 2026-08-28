<?php

use Illuminate\Support\Carbon;
use Splicewire\Beam\Calendars\Data\RecurrenceRuleData;
use Splicewire\Beam\Calendars\Data\SeriesData;
use Splicewire\Beam\Calendars\Enums\RecurrenceFrequency;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Calendars\Recurrence\SeriesExpander;

function series(array $rule, array $extra = []): SeriesData
{
    return SeriesData::from([
        'kind' => 'kind.series',
        'channel' => 'default',
        'anchor' => '2026-01-05', // a Monday
        'rule' => $rule,
        'spawn' => ['mode' => SpawnMode::Generate->value, 'instructions' => 'Write it'],
        ...$extra,
    ]);
}

it('expands a bounded daily rule to exactly `count` occurrences', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY', 'count' => 3]), 'series-1',
    );

    expect(array_map(fn ($o) => $o->anchor, $out))
        ->toBe(['2026-01-05', '2026-01-06', '2026-01-07']);
});

it('honours interval', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY', 'interval' => 3, 'count' => 3]), 'series-1',
    );

    expect(array_map(fn ($o) => $o->anchor, $out))
        ->toBe(['2026-01-05', '2026-01-08', '2026-01-11']);
});

it('fans a weekly rule across its BYDAY tokens', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'WEEKLY', 'byday' => ['MO', 'WE'], 'count' => 4]), 'series-1',
    );

    expect(array_map(fn ($o) => $o->anchor, $out))
        ->toBe(['2026-01-05', '2026-01-07', '2026-01-12', '2026-01-14']);
});

it('stops at the horizon even when the rule is unbounded', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY']), 'series-1', Carbon::parse('2026-01-08')->endOfDay(),
    );

    expect($out)->toHaveCount(4);
});

it('REFUSES to expand a rule with no count, until, window or horizon', function () {
    (new SeriesExpander)->expand(series(['freq' => 'DAILY']), 'series-1');
})->throws(InvalidArgumentException::class, 'unbounded series');

it('bounds by the series window when the rule itself is unbounded', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY'], ['window' => '2026-01-07']), 'series-1',
    );

    expect(array_map(fn ($o) => $o->anchor, $out))
        ->toBe(['2026-01-05', '2026-01-06', '2026-01-07']);
});

it('derives a deterministic recurrence id from the occurrence date', function () {
    expect(SeriesExpander::recurrenceId('2026-01-05'))->toBe('2026-01-05')
        ->and(SeriesExpander::recurrenceId(Carbon::parse('2026-01-05 23:59:59')))->toBe('2026-01-05');
});

it('projects the typed rule to an RRULE string without storing one', function () {
    $rule = RecurrenceRuleData::from([
        'freq' => RecurrenceFrequency::Weekly->value,
        'interval' => 2,
        'count' => 10,
        'byday' => ['MO', 'TH'],
    ]);

    expect(SeriesExpander::rrule($rule))->toBe('FREQ=WEEKLY;INTERVAL=2;COUNT=10;BYDAY=MO,TH');
});

it('omits an interval of 1 from the RRULE, because RFC-5545 defaults it', function () {
    expect(SeriesExpander::rrule(RecurrenceRuleData::from(['freq' => 'DAILY', 'count' => 2])))
        ->toBe('FREQ=DAILY;COUNT=2');
});

// ── overrides ────────────────────────────────────────────────────────────────────────────────

it('drops a skipped occurrence entirely (EXDATE)', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY', 'count' => 3], [
            'overrides' => [['recurrence_id' => '2026-01-06', 'action' => 'skip']],
        ]),
        'series-1',
    );

    expect(array_map(fn ($o) => $o->anchor, $out))->toBe(['2026-01-05', '2026-01-07']);
});

it('COUNTS a skipped occurrence against a count-bounded rule, per RFC-5545', function () {
    // This is the branch that reliably reads as a bug: skipping one instance does NOT extend the
    // series by one at the end. An excluded instance is generated and then removed.
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY', 'count' => 3], [
            'overrides' => [['recurrence_id' => '2026-01-06', 'action' => 'skip']],
        ]),
        'series-1',
    );

    expect($out)->toHaveCount(2)
        ->and(end($out)->anchor)->toBe('2026-01-07');
});

it('swaps the spawn of a replaced occurrence and leaves its siblings alone', function () {
    $out = (new SeriesExpander)->expand(
        series(['freq' => 'DAILY', 'count' => 2], [
            'overrides' => [[
                'recurrence_id' => '2026-01-06',
                'action' => 'replace',
                'spawn' => ['mode' => SpawnMode::Reference->value, 'target_ref' => 'fixed-1'],
            ]],
        ]),
        'series-1',
    );

    expect($out[0]->spawn->mode)->toBe(SpawnMode::Generate)
        ->and($out[1]->spawn->mode)->toBe(SpawnMode::Reference)
        ->and($out[1]->spawn->targetRef)->toBe('fixed-1');
});

it('keeps every occurrence pointing back at its source series', function () {
    $out = (new SeriesExpander)->expand(series(['freq' => 'DAILY', 'count' => 2]), 'series-42');

    expect(array_unique(array_map(fn ($o) => $o->seriesRef, $out)))->toBe(['series-42']);
});
