<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionScheduler;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\ActionSeriesResources;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Calendars\Tests\Fakes\BlockedLocalActionHandler;
use Splicewire\Beam\Calendars\Tests\Fakes\RevocableLocalActionHandler;

beforeEach(function () {
    app()->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
    RevocableLocalActionHandler::$revoked = false;
    RevocableLocalActionHandler::$denyReplacement = false;
    config(['beam.calendars.action_handlers' => ['kind.local' => RevocableLocalActionHandler::class]]);
    app()->instance(ActionContextProvider::class, new class implements ActionContextProvider
    {
        public function current(): ActionContext
        {
            return new ActionContext('editor:1', 'user:1', 'tenant:one');
        }
    });
    Route::prefix('api/beam')->group(function () {
        ActionResources::mount();
        ActionSeriesResources::mount();
    });
    $this->actionInput = [
        'kind' => 'kind.local', 'payload' => ['message' => 'Original'],
        'due_at' => '2026-09-18T13:00:00Z', 'timezone' => 'UTC',
    ];
    $this->seriesInput = ['action' => $this->actionInput, 'rule' => ['freq' => 'DAILY', 'count' => 3]];
});

afterEach(function () {
    RevocableLocalActionHandler::$revoked = false;
    RevocableLocalActionHandler::$denyReplacement = false;
});

/** Snapshot stored rows, including revisions, overrides, prepared data and attempt identities. */
function calendarAuthoritySnapshot(): array
{
    return array_map(
        fn (string $model): array => DB::table((new $model)->getTable())->orderBy('id')->get()->toArray(),
        [CalendarAction::class, CalendarActionAttempt::class, CalendarActionSeries::class],
    );
}

it('refuses revoked concrete authority before mutation and accepts the same request when restored', function (string $resource, string $operation) {
    $input = $resource === 'calendar-actions' ? $this->actionInput : $this->seriesInput;
    $url = '/api/beam/'.$resource.'/schedule';
    $body = $input;

    if ($operation !== 'schedule') {
        $id = $this->postJson($url, $input)->assertOk()->json('data.id');
        $url = '/api/beam/'.$resource.'/'.$id.'/'.$operation;
        $body = ['expected_revision' => 1];
        if (in_array($operation, ['pin', 'skip', 'replace'], true)) {
            $body['recurrence_id'] = '2026-09-19';
        }
        if (in_array($operation, ['reschedule', 'replace'], true)) {
            $body['action'] = array_replace($this->actionInput, ['due_at' => '2026-09-19T13:00:00Z']);
        }
        if ($operation === 'retry') {
            config(['beam.calendars.action_handlers' => ['kind.local' => BlockedLocalActionHandler::class]]);
            app(ActionScheduler::class)->run($id, 'tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
            expect(CalendarAction::findOrFail($id)->status)->toBe('blocked');
            $body = ['expected_revision' => 2, 'due_at' => '2026-09-19T13:00:00Z', 'idempotency_key' => 'restored-retry'];
        }
        if ($operation === 'resume') {
            config(['beam.calendars.action_handlers' => []]);
            app(ActionSeriesService::class)->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
            expect(CalendarActionSeries::findOrFail($id)->status)->toBe('blocked');
            $body['expected_revision'] = 2;
        }
        config(['beam.calendars.action_handlers' => ['kind.local' => RevocableLocalActionHandler::class]]);
    }

    $before = calendarAuthoritySnapshot();
    RevocableLocalActionHandler::$revoked = true;
    $this->postJson($url, $body)->assertForbidden();
    expect(calendarAuthoritySnapshot())->toEqual($before);

    RevocableLocalActionHandler::$revoked = false;
    $this->postJson($url, $body)->assertOk();
    expect(calendarAuthoritySnapshot())->not->toEqual($before);

    if ($operation === 'retry') {
        $after = calendarAuthoritySnapshot();
        RevocableLocalActionHandler::$revoked = true;
        $this->postJson($url, $body)->assertForbidden();
        expect(calendarAuthoritySnapshot())->toEqual($after);
    }
})->with([
    'action schedule' => ['calendar-actions', 'schedule'],
    'action reschedule' => ['calendar-actions', 'reschedule'],
    'action cancel' => ['calendar-actions', 'cancel'],
    'action retry' => ['calendar-actions', 'retry'],
    'series schedule' => ['calendar-action-series', 'schedule'],
    'series cancel' => ['calendar-action-series', 'cancel'],
    'series resume' => ['calendar-action-series', 'resume'],
    'occurrence pin' => ['calendar-action-series', 'pin'],
    'occurrence skip' => ['calendar-action-series', 'skip'],
    'occurrence replace' => ['calendar-action-series', 'replace'],
]);

it('authorizes a replacement subject independently of the allowed original', function (string $mode) {
    if ($mode === 'action') {
        $id = $this->postJson('/api/beam/calendar-actions/schedule', $this->actionInput)->assertOk()->json('data.id');
        $url = '/api/beam/calendar-actions/'.$id.'/reschedule';
        $body = ['expected_revision' => 1];
    } else {
        $id = $this->postJson('/api/beam/calendar-action-series/schedule', $this->seriesInput)->assertOk()->json('data.id');
        $body = ['expected_revision' => 1, 'recurrence_id' => '2026-09-19'];
        if ($mode === 'pinned occurrence') {
            $this->postJson('/api/beam/calendar-action-series/'.$id.'/pin', $body)->assertOk();
        }
        $url = '/api/beam/calendar-action-series/'.$id.'/replace';
    }
    $body['action'] = array_replace($this->actionInput, [
        'payload' => ['message' => 'Replacement'], 'due_at' => '2026-09-19T13:00:00Z',
    ]);
    $before = calendarAuthoritySnapshot();
    RevocableLocalActionHandler::$denyReplacement = true;
    $this->postJson($url, $body)->assertForbidden();
    expect(calendarAuthoritySnapshot())->toEqual($before);

    RevocableLocalActionHandler::$denyReplacement = false;
    $this->postJson($url, $body)->assertOk()->assertJsonPath('data.payload.message', 'Replacement');
    expect(calendarAuthoritySnapshot())->not->toEqual($before);
})->with(['action', 'unpinned occurrence', 'pinned occurrence']);
