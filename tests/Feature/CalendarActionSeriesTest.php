<?php

use Carbon\CarbonImmutable;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\Data\ActionSeriesInputData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Tests\Fakes\LocalActionHandler;

beforeEach(function () {
    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $this->context = new ActionContext('editor:1', 'user:1', 'tenant:one');
    $this->seriesInput = ActionSeriesInputData::from([
        'action' => ['kind' => 'kind.local', 'payload' => ['message' => 'Recurring'], 'due_at' => '2026-09-18T09:00:00-04:00', 'timezone' => 'America/New_York', 'calendar_id' => 'calendar:standalone'],
        'rule' => ['freq' => 'DAILY', 'count' => 3],
    ]);
});

it('freezes a template and expands independent occurrence identities without writes', function () {
    $service = app(ActionSeriesService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    expect($series->template['payload']['prepared'])->toBeTrue();
    $occurrences = $service->project($series, '2026-09-18', '2026-09-22');
    expect($occurrences)->toHaveCount(3)
        ->and($occurrences[0]->recurrenceId)->toBe('2026-09-18')
        ->and($occurrences[1]->action->origin)->toBe('action-series:'.$series->id.':2026-09-19')
        ->and(CalendarAction::query()->count())->toBe(0);
});

it('pins without executing, skips only one COUNT slot and delivers remaining identities once', function () {
    $service = app(ActionSeriesService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    $pin = $service->pin($series->id, 1, '2026-09-18', $this->context);
    expect($pin->status)->toBe('pending')->and(DB::table('users')->count())->toBe(0);
    $service->skip($series->id, 1, '2026-09-19', $this->context);
    $attempts = $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-22T13:00:00Z'));
    expect($attempts)->toHaveCount(2)->and(DB::table('users')->count())->toBe(2)
        ->and(CalendarAction::query()->count())->toBe(2);
    expect($service->sweep('tenant:one', CarbonImmutable::parse('2026-09-23T13:00:00Z')))->toBe([]);
    expect($service->project($series->fresh(), '2026-09-18', '2026-09-22'))->toHaveCount(2);
});

it('replaces and reschedules a single occurrence without changing its source identity', function () {
    $service = app(ActionSeriesService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    $replacement = clone $this->seriesInput->action;
    $replacement->dueAt = '2026-09-25T15:00:00Z';
    $replacement->payload = ['message' => 'Replacement'];
    $action = $service->replace($series->id, 1, '2026-09-19', $replacement, $this->context);
    expect($action->recurrence_id)->toBe('2026-09-19')->and($action->payload['message'])->toBe('Replacement');
    expect($service->project($series->fresh(), '2026-09-18', '2026-09-20'))->toHaveCount(2);
    expect($service->project($series->fresh(), '2026-09-25', '2026-09-25'))->toHaveCount(1);
    $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-20T15:00:00Z'));
    expect($action->fresh()->status)->toBe('pending')->and(DB::table('users')->count())->toBe(2);
    $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-25T15:00:00Z'));
    expect($action->fresh()->status)->toBe('applied')->and(DB::table('users')->count())->toBe(3);
});

it('cancels only pending occurrences and stops future expansion without rewriting applied history', function () {
    $service = app(ActionSeriesService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    $pending = $service->pin($series->id, 1, '2026-09-20', $this->context);
    $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    $service->cancel($series->id, 1, $this->context);
    expect($pending->fresh()->status)->toBe('cancelled')
        ->and(CalendarAction::query()->where('status', 'applied')->count())->toBe(1);
    expect($service->sweep('tenant:one', CarbonImmutable::parse('2026-09-30T13:00:00Z')))->toBe([]);
});

it('keeps the server-prepared template frozen when later definitions change', function () {
    config(['beam.calendars.action_handlers' => ['kind.local' => Splicewire\Beam\Calendars\Tests\Fakes\FrozenTemplateActionHandler::class]]);
    $service = app(ActionSeriesService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    config(['test.definition_version' => 2]);
    $action = $service->pin($series->id, 1, '2026-09-19', $this->context);
    expect($action->payload['definition_version'])->toBe(1);
});

it('does not resurrect cancelled occurrences or allow a reschedule to erase recurrence identity', function () {
    $service = app(ActionSeriesService::class);
    $actions = app(Splicewire\Beam\Calendars\Actions\ActionService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    $action = $service->pin($series->id, 1, '2026-09-18', $this->context);
    $replacement = $action->toActionData();
    $replacement->seriesId = null;
    expect(fn () => $actions->edit($action->id, 1, $replacement, $this->context))->toThrow(Splicewire\Beam\Calendars\Actions\ActionConflict::class);
    $actions->cancel($action->id, 1, $this->context);
    $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    expect(CalendarAction::query()->count())->toBe(1)->and($action->fresh()->status)->toBe('cancelled')->and(DB::table('users')->count())->toBe(0);
});

it('rolls back series delivery and local effects together after an outer transaction abort', function () {
    $service = app(ActionSeriesService::class);
    $series = $service->schedule($this->seriesInput, $this->context);
    $action = $service->pin($series->id, 1, '2026-09-18', $this->context);
    $attemptId = $action->current_attempt_id;
    try {
        DB::transaction(function () use ($service) {
            $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
            throw new RuntimeException('simulated outer abort');
        });
    } catch (RuntimeException) {
    }
    expect($action->fresh()->status)->toBe('pending')->and(DB::table('users')->count())->toBe(0);
    $service->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    expect($action->fresh()->current_attempt_id)->toBe($attemptId)->and(DB::table('users')->count())->toBe(1);
});

it('uses local wall time across DST and preserves occurrence dates with explicit fold and gap policy', function (string $instant, string $date, string $expected) {
    $actual = Splicewire\Beam\Calendars\Actions\ActionLocalTime::atDate(CarbonImmutable::parse($instant), $date, 'America/New_York');
    expect($actual->format('Y-m-d\TH:i:sP'))->toBe($expected);
    $days = (int) CarbonImmutable::parse($instant)->setTimezone('America/New_York')->startOfDay()->diffInDays(CarbonImmutable::parse($date, 'America/New_York')->startOfDay());
    expect(Splicewire\Beam\Calendars\Actions\ActionLocalTime::shiftDays(CarbonImmutable::parse($instant), $days, 'America/New_York')->equalTo($actual))->toBeTrue();
})->with([
    ['2026-03-07T02:30:00-05:00', '2026-03-08', '2026-03-08T07:30:00+00:00'],
    ['2026-10-31T01:30:00-04:00', '2026-11-01', '2026-11-01T05:30:00+00:00'],
    ['2026-03-07T09:00:00-05:00', '2026-03-09', '2026-03-09T13:00:00+00:00'],
]);
