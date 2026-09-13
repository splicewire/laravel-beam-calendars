<?php

use Illuminate\Support\Carbon;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionScheduler;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Tests\Fakes\LocalActionHandler;

beforeEach(function () {
    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $this->context = new ActionContext('editor:1', 'user:1', 'tenant:one');
    $this->request = new CalendarActionData('kind.local', ['message' => 'Hello'], '2026-09-18T09:00:00-04:00', 'America/New_York');
});

it('executes a due action once and records its local result with the same durable attempt', function () {
    $action = app(ActionService::class)->schedule($this->request, $this->context);
    $scheduler = app(ActionScheduler::class);

    expect($scheduler->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T12:59:59Z')))->toBeNull();
    $attempt = $scheduler->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));

    expect($attempt->id)->toBe($action->current_attempt_id)
        ->and($attempt->status)->toBe('applied')
        ->and($attempt->result)->toBe(['attempt_id' => $attempt->id])
        ->and($action->refresh()->status)->toBe('applied')
        ->and($scheduler->run($action->id, 'tenant:one', Carbon::parse('2026-09-19T13:00:00Z')))->toBeNull()
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(1);
});

it('stores a canonical pending action at an exact instant with its first durable attempt', function () {
    $actions = app(ActionService::class);
    $action = $actions->schedule($this->request, $this->context);
    $stored = $actions->find($action->id, 'tenant:one');

    expect($stored->status)->toBe('pending')
        ->and($stored->revision)->toBe(1)
        ->and($stored->due_at->toIso8601String())->toBe('2026-09-18T13:00:00+00:00')
        ->and($stored->timezone)->toBe('America/New_York')
        ->and($stored->principal)->toBe('editor:1')
        ->and($stored->payload)->toBe(['message' => 'Hello', 'prepared' => true])
        ->and($stored->attempts)->toHaveCount(1)
        ->and($stored->attempts->first()->status)->toBe('pending');
});

it('revises pending intent and makes stale edit and cancel requests lose without changing the winner', function () {
    $actions = app(ActionService::class);
    $action = $actions->schedule($this->request, $this->context);
    $replacement = clone $this->request;
    $replacement->dueAt = '2026-09-19T09:00:00-04:00';
    $edited = $actions->edit($action->id, 1, $replacement, $this->context);

    expect($edited->revision)->toBe(2)
        ->and($edited->attempts->first()->request['due_at'])->toBe('2026-09-19T13:00:00+00:00')
        ->and($edited->due_at->toIso8601String())->toBe('2026-09-19T13:00:00+00:00');
    expect(fn () => $actions->edit($action->id, 1, $this->request, $this->context))
        ->toThrow(Splicewire\Beam\Calendars\Actions\ActionConflict::class);
    expect(fn () => $actions->cancel($action->id, 1, $this->context))
        ->toThrow(Splicewire\Beam\Calendars\Actions\ActionConflict::class);

    $cancelled = $actions->cancel($action->id, 2, $this->context);
    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->attempts->first()->status)->toBe('cancelled')
        ->and(app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-20T13:00:00Z')))->toBeNull();
});

it('rejects dates without an instant and invalid calendar dates instead of normalizing them', function (string $instant, string $timezone) {
    $this->request->dueAt = $instant;
    $this->request->timezone = $timezone;

    expect(fn () => app(ActionService::class)->schedule($this->request, $this->context))
        ->toThrow(Illuminate\Validation\ValidationException::class);
})->with([
    ['2026-09-18', 'America/New_York'],
    ['2026-09-18T09:00:00', 'America/New_York'],
    ['2026-02-30T09:00:00-05:00', 'America/New_York'],
    ['2026-09-18T09:00:00-04:00', 'not/a/timezone'],
]);

it('records a failed handler and an unavailable handler without committing partial effects', function () {
    $actions = app(ActionService::class);
    $first = $actions->schedule($this->request, $this->context);
    $second = $actions->schedule($this->request, $this->context);
    config(['beam.calendars.action_handlers' => ['kind.local' => Splicewire\Beam\Calendars\Tests\Fakes\FailingLocalActionHandler::class]]);
    $failed = app(ActionScheduler::class)->run($first->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));
    config(['beam.calendars.action_handlers' => []]);
    $unavailable = app(ActionScheduler::class)->run($second->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));

    expect($failed->status)->toBe('failed')
        ->and($unavailable->status)->toBe('failed')
        ->and($unavailable->blockers)->toBe(['The execution handler for this action kind is unavailable.'])
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(0);
});

it('recovers the same pending attempt when the transaction aborts after local execution', function () {
    $action = app(ActionService::class)->schedule($this->request, $this->context);
    $connection = Illuminate\Support\Facades\DB::connection();
    expect(fn () => $connection->transaction(function () use ($connection, $action) {
        app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'), $connection);
        throw new RuntimeException('Abort before commit.');
    }))->toThrow(RuntimeException::class, 'Abort before commit.');

    expect($action->refresh()->status)->toBe('pending')
        ->and($action->attempts->first()->status)->toBe('pending')
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(0);
    $recovered = app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));
    expect($recovered->id)->toBe($action->current_attempt_id)
        ->and($recovered->status)->toBe('applied');
});

it('isolates due actions and mutations by the host-derived tenant and caller authority', function () {
    $actions = app(ActionService::class);
    $action = $actions->schedule($this->request, $this->context);
    $foreign = new ActionContext('editor:1', 'user:1', 'tenant:two');
    $unauthorized = new ActionContext('visitor:2', 'user:2', 'tenant:one');

    expect(app(ActionScheduler::class)->sweep('tenant:two', Carbon::parse('2026-09-18T13:00:00Z')))->toBe([]);
    expect(fn () => $actions->cancel($action->id, 1, $foreign))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => $actions->cancel($action->id, 1, $unauthorized))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect(fn () => $actions->schedule($this->request, $unauthorized))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect($action->refresh()->status)->toBe('pending');
});

it('projects action outcomes and stable attempt identity without exposing internal tenant scope', function () {
    $action = app(ActionService::class)->schedule($this->request, $this->context);
    $record = Splicewire\Beam\Calendars\Data\CalendarActionRecordData::from($action)->toArray();

    expect($record)->toHaveKeys(['id', 'revision', 'current_attempt_id', 'due_at', 'attempts'])
        ->not->toHaveKeys(['tenant_token', 'intent_hash'])
        ->and($record['attempts'][0]['id'])->toBe($action->current_attempt_id)
        ->and($record['attempts'][0]['due_at'])->toBe('2026-09-18T13:00:00+00:00');
});

it('keeps blocked outcomes and rolls back their local writes until an explicit idempotent retry', function () {
    $actions = app(ActionService::class);
    $action = $actions->schedule($this->request, $this->context);
    config(['beam.calendars.action_handlers' => ['kind.local' => Splicewire\Beam\Calendars\Tests\Fakes\BlockedLocalActionHandler::class]]);
    $blocked = app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));

    expect($blocked->status)->toBe('blocked')
        ->and($blocked->blockers)->toBe(['Approval is still required.'])
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(0)
        ->and(app(ActionScheduler::class)->sweep('tenant:one', Carbon::parse('2026-09-19T13:00:00Z')))->toBe([]);

    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $retry = $actions->retry($action->id, 2, $this->context, Carbon::parse('2026-09-19T13:00:00Z'), 'retry-123');
    $duplicate = $actions->retry($action->id, 2, $this->context, Carbon::parse('2026-09-19T13:00:00Z'), 'retry-123');

    expect($retry->id)->not->toBe($blocked->id)
        ->and($duplicate->id)->toBe($retry->id)
        ->and($action->refresh()->attempts)->toHaveCount(2)
        ->and($blocked->refresh()->status)->toBe('blocked');
    $completed = app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-19T13:00:00Z'));
    expect($completed->id)->toBe($retry->id)
        ->and($completed->status)->toBe('applied')
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(1);
});

it('deduplicates source intent even after completion and rejects another meaning for the same origin', function () {
    $actions = app(ActionService::class);
    $this->request->origin = 'fixture:message:1';
    $action = $actions->schedule($this->request, $this->context);
    app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));
    $duplicate = $actions->schedule($this->request, $this->context);

    expect($duplicate->id)->toBe($action->id)->and($duplicate->status)->toBe('applied');
    $this->request->payload = ['message' => 'A different intent'];
    expect(fn () => $actions->schedule($this->request, $this->context))
        ->toThrow(Splicewire\Beam\Calendars\Actions\ActionConflict::class);
});

it('registers dot-bearing action kinds through the public registry and honors late replacement', function () {
    $registry = app(Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry::class);
    $registry->register('kind.workflow-transition', LocalActionHandler::class);
    expect($registry->handler('kind.workflow-transition'))->toBeInstanceOf(LocalActionHandler::class);
    $registry->register('beam.calendars.action_handlers.kind.workflow-transition', Splicewire\Beam\Calendars\Tests\Fakes\BlockedLocalActionHandler::class);
    expect($registry->handler('kind.workflow-transition'))->toBeInstanceOf(Splicewire\Beam\Calendars\Tests\Fakes\BlockedLocalActionHandler::class);
});

it('preserves fractional instants and does not sweep them before their precise due time', function () {
    $this->request->dueAt = '2026-09-18T13:00:00.123456Z';
    $action = app(ActionService::class)->schedule($this->request, $this->context);
    expect($action->refresh()->toActionData()->dueAt)->toBe('2026-09-18T13:00:00.123456+00:00')
        ->and(app(ActionScheduler::class)->sweep('tenant:one', Carbon::parse('2026-09-18T13:00:00.123455Z')))->toBe([])
        ->and(app(ActionScheduler::class)->sweep('tenant:one', Carbon::parse('2026-09-18T13:00:00.123456Z')))->toHaveCount(1);
});

it('discards queued work for an older revision even when the replacement is already due', function () {
    $actions = app(ActionService::class);
    $action = $actions->schedule($this->request, $this->context);
    $replacement = clone $this->request;
    $replacement->payload = ['message' => 'New intent'];
    $actions->edit($action->id, 1, $replacement, $this->context);
    expect(app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'), expectedRevision: 1))->toBeNull()
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(0);
    expect(app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'), expectedRevision: 2)->status)->toBe('applied');
});

it('rolls back subject effects when a model observer vetoes recording the applied receipt', function () {
    $action = app(ActionService::class)->schedule($this->request, $this->context);
    Splicewire\Beam\Calendars\Models\CalendarActionAttempt::saving(fn ($attempt) => $attempt->status !== 'applied');

    expect(fn () => app(ActionScheduler::class)->run($action->id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z')))
        ->toThrow(RuntimeException::class, 'Calendar action persistence was refused');
    expect($action->refresh()->status)->toBe('pending')
        ->and($action->attempts->first()->status)->toBe('pending')
        ->and(Splicewire\Beam\Calendars\Tests\Fixtures\User::count())->toBe(0);
});

it('reserves durable source origins for internal adapters and preserves origin during edits', function () {
    config(['beam.calendars.reserved_action_origins' => ['action-series:', 'transition:', 'composition-cell:']]);
    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $service = app(ActionService::class);
    $context = new ActionContext('editor:1', 'user:1', 'tenant:one');
    $data = new CalendarActionData('kind.local', [], '2026-09-18T13:00:00Z', 'UTC');
    foreach (['action-series:source:date', 'transition:fact:binding', 'composition-cell:cell:revision:1'] as $origin) {
        $data->origin = $origin;
        expect(fn () => $service->schedule($data, $context))->toThrow(Illuminate\Validation\ValidationException::class);
    }
    $data->origin = 'client-intent:1';
    $action = $service->schedule($data, $context);
    $changed = $action->toActionData();
    $changed->origin = 'transition:stolen';
    expect(fn () => $service->edit($action->id, 1, $changed, $context))->toThrow(Splicewire\Beam\Calendars\Actions\ActionConflict::class);
});

it('restores previously durable intent and records revoked authority before any effects', function () {
    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $service = app(ActionService::class);
    $context = new ActionContext('revoked:principal', 'user:creator', 'tenant:one');
    $data = new CalendarActionData('kind.local', ['prepared' => true], '2026-09-18T13:00:00Z', 'UTC', origin: 'durable-source:1');
    $action = $service->restorePreparedIntent($data, $context, DB::connection());
    expect($service->restorePreparedIntent($data, $context, DB::connection())->id)->toBe($action->id);
    $attempt = app(ActionScheduler::class)->run($action->id, 'tenant:one', \Carbon\CarbonImmutable::parse($data->dueAt));
    expect($attempt->status)->toBe('blocked')->and($attempt->blockers)->not->toBeEmpty()->and(DB::table('users')->count())->toBe(0);
});
