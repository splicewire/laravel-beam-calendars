<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Tests\Fakes\LocalActionHandler;

beforeEach(function () {
    app()->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $this->contextProvider = new class implements ActionContextProvider
    {
        public ActionContext $context;

        public function current(): ActionContext
        {
            return $this->context;
        }
    };
    $this->contextProvider->context = new ActionContext('editor:1', 'user:1', 'tenant:one');
    app()->instance(ActionContextProvider::class, $this->contextProvider);
    Route::prefix('api/beam')->group(fn () => ActionResources::mount());
    $this->payload = [
        'kind' => 'kind.local', 'payload' => ['message' => 'Hello'],
        'due_at' => '2026-09-18T09:00:00-04:00', 'timezone' => 'America/New_York',
    ];
});

it('schedules through the mounted particle and reads only the current principal and tenant', function () {
    $response = $this->postJson('/api/beam/calendar-actions/schedule', $this->payload + [
        'principal' => 'attacker', 'creator' => 'attacker', 'tenant_token' => 'tenant:two',
    ])->assertOk();
    $id = $response->json('data.id');

    $response->assertJsonPath('data.principal', 'editor:1')->assertJsonPath('data.creator', 'user:1');
    $this->getJson('/api/beam/calendar-actions')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/beam/calendar-actions/'.$id)->assertOk()->assertJsonPath('data.id', $id);

    $this->contextProvider->context = new ActionContext('editor:1', 'user:1', 'tenant:two');
    $this->getJson('/api/beam/calendar-actions')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/beam/calendar-actions/'.$id)->assertNotFound();
    $this->postJson('/api/beam/calendar-actions/'.$id.'/cancel', ['expected_revision' => 1])->assertNotFound();

    $this->contextProvider->context = new ActionContext('visitor:2', 'user:2', 'tenant:one');
    $this->getJson('/api/beam/calendar-actions')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/beam/calendar-actions/schedule', $this->payload)->assertForbidden();
});

it('declares an exact calendar filter and never exposes raw action CRUD writes', function () {
    $first = new CalendarActionData('kind.local', ['message' => 'One'], '2026-09-18T13:00:00Z', 'UTC', calendarId: '00000000-0000-4000-8000-000000000001');
    $second = clone $first;
    $second->calendarId = '00000000-0000-4000-8000-000000000002';
    $actions = app(ActionService::class);
    $action = $actions->schedule($first, $this->contextProvider->current());
    $actions->schedule($second, $this->contextProvider->current());
    $this->getJson('/api/beam/calendar-actions?filter[calendar_id]='.$first->calendarId)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $action->id);
    $this->postJson('/api/beam/calendar-actions', $this->payload)->assertMethodNotAllowed();
    $this->patchJson('/api/beam/calendar-actions/'.$action->id, ['status' => 'applied'])->assertMethodNotAllowed();
    $this->deleteJson('/api/beam/calendar-actions/'.$action->id)->assertMethodNotAllowed();
});

it('reschedules and cancels through declared operations with revision conflicts', function () {
    $id = $this->postJson('/api/beam/calendar-actions/schedule', $this->payload)->assertOk()->json('data.id');
    $replacement = $this->payload;
    $replacement['due_at'] = '2026-09-19T09:00:00-04:00';
    $this->postJson('/api/beam/calendar-actions/'.$id.'/reschedule', ['expected_revision' => 1, 'action' => $replacement])
        ->assertOk()->assertJsonPath('data.revision', 2);
    $this->postJson('/api/beam/calendar-actions/'.$id.'/cancel', ['expected_revision' => 1])->assertConflict();
    $this->postJson('/api/beam/calendar-actions/'.$id.'/cancel', ['expected_revision' => 2])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('retries through the declared operation with browser ISO instants and one idempotent attempt', function () {
    $id = $this->postJson('/api/beam/calendar-actions/schedule', $this->payload)->assertOk()->json('data.id');
    config(['beam.calendars.action_handlers' => ['kind.local' => Splicewire\Beam\Calendars\Tests\Fakes\BlockedLocalActionHandler::class]]);
    app(Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($id, 'tenant:one', Carbon::parse('2026-09-18T13:00:00Z'));
    $body = ['expected_revision' => 2, 'due_at' => '2026-09-19T13:00:00.000Z', 'idempotency_key' => 'browser-retry'];
    $attempt = $this->postJson('/api/beam/calendar-actions/'.$id.'/retry', $body)->assertOk()->json('data.id');
    $this->postJson('/api/beam/calendar-actions/'.$id.'/retry', $body)->assertOk()->assertJsonPath('data.id', $attempt);
    $body['due_at'] = '2026-09-20T13:00:00.000Z';
    $this->postJson('/api/beam/calendar-actions/'.$id.'/retry', $body)->assertConflict();
});
