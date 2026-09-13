<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\ActionSeriesResources;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
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
    Route::prefix('api/beam')->group(function () {
        ActionResources::mount();
        ActionSeriesResources::mount();
    });
    $this->input = [
        'action' => ['kind' => 'kind.local', 'payload' => ['message' => 'Recurring'], 'due_at' => '2026-09-18T13:00:00.000Z', 'timezone' => 'America/New_York', 'calendar_id' => 'calendar:standalone'],
        'rule' => ['freq' => 'DAILY', 'count' => 3],
    ];
});

it('authors and projects through real routes without execution then pins one independent action', function () {
    $id = $this->postJson('/api/beam/calendar-action-series/schedule', $this->input)->assertOk()->json('data.id');
    $this->getJson('/api/beam/calendar-action-series?filter[calendar_id]=calendar:standalone')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/beam/calendar-action-series/'.$id.'/project?from=2026-09-18&through=2026-09-20')
        ->assertOk()->assertJsonCount(3, 'data.occurrences');
    expect(CalendarAction::query()->count())->toBe(0)->and(DB::table('users')->count())->toBe(0);
    $body = ['expected_revision' => 1, 'recurrence_id' => '2026-09-19'];
    $action = $this->postJson('/api/beam/calendar-action-series/'.$id.'/pin', $body)->assertOk()->json('data.id');
    $this->postJson('/api/beam/calendar-action-series/'.$id.'/pin', $body)->assertOk()->assertJsonPath('data.id', $action);
    expect(CalendarAction::query()->count())->toBe(1)->and(DB::table('users')->count())->toBe(0);
    $this->postJson('/api/beam/calendar-action-series/'.$id.'/skip', $body)->assertOk()->assertJsonPath('data.revision', 2);
    $this->getJson('/api/beam/calendar-actions/'.$action)->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson('/api/beam/calendar-action-series/'.$id.'/cancel', ['expected_revision' => 1])->assertConflict();
    $this->postJson('/api/beam/calendar-action-series/'.$id.'/cancel', ['expected_revision' => 2])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('scopes recurring reads and all mutation subjects to host principal and tenant', function () {
    $id = $this->postJson('/api/beam/calendar-action-series/schedule', $this->input + ['tenant_token' => 'evil', 'principal' => 'evil'])->assertOk()->json('data.id');
    expect(CalendarActionSeries::find($id)->tenant_token)->toBe('tenant:one');
    foreach ([new ActionContext('editor:1', 'user:1', 'tenant:two'), new ActionContext('visitor:2', 'user:2', 'tenant:one')] as $context) {
        $this->contextProvider->context = $context;
        $this->getJson('/api/beam/calendar-action-series')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/beam/calendar-action-series/'.$id)->assertNotFound();
        $this->postJson('/api/beam/calendar-action-series/'.$id.'/cancel', ['expected_revision' => 1])->assertNotFound();
    }
    $this->postJson('/api/beam/calendar-action-series/schedule', $this->input)->assertForbidden();
    $this->postJson('/api/beam/calendar-action-series', $this->input)->assertMethodNotAllowed();
});

it('reports unavailable materialization and resumes explicitly through the mounted surface', function () {
    $id = $this->postJson('/api/beam/calendar-action-series/schedule', $this->input)->assertOk()->json('data.id');
    config(['beam.calendars.action_handlers' => []]);
    app(ActionSeriesService::class)->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    $this->getJson('/api/beam/calendar-action-series/'.$id)->assertOk()->assertJsonPath('data.status', 'blocked')->assertJsonCount(1, 'data.blockers');
    expect(CalendarAction::query()->count())->toBe(0);
    config(['beam.calendars.action_handlers' => ['kind.local' => LocalActionHandler::class]]);
    $this->postJson('/api/beam/calendar-action-series/'.$id.'/resume', ['expected_revision' => 2])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.status', 'active');
    $this->postJson('/api/beam/calendar-action-series/'.$id.'/resume', ['expected_revision' => 2])->assertConflict();
    app(ActionSeriesService::class)->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    expect(DB::table('users')->count())->toBe(1);
});
