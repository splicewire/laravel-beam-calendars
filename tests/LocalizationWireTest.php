<?php

namespace Splicewire\Beam\Calendars\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionInstant;
use Splicewire\Beam\Calendars\Actions\ActionLocalTime;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Enums\RecurrenceFrequency;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Ops\ExportCalendar;
use Splicewire\Beam\Calendars\Ops\MaterializeOccurrence;
use Splicewire\Beam\Calendars\Tests\Fakes\LocalActionHandler;
use Splicewire\Beam\Calendars\Write\KindValidator;
use Splicewire\Beam\Localization\PseudoLocale;

/** Real operation/helper responses captured unchanged at base and candidate. */
class LocalizationWireTest extends TestCase
{
    private bool $pseudo = false;

    private array $snapshots = [];

    private array $pseudoObservations = [];

    private array $pseudoFailures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(\Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
        config(['app.debug' => false, 'beam.calendars.action-handlers' => ['kind.local' => LocalActionHandler::class]]);
        $this->app->instance(ActionContextProvider::class, new class implements ActionContextProvider
        {
            public function current(): ActionContext
            {
                return new ActionContext('editor:1', 'user:1', 'tenant:one');
            }
        });
        Route::prefix('loc25')->group(function () {
            ActionResources::mount();
            \Splicewire\Beam\Calendars\ActionSeriesResources::mount();
        });
        $calendar = Calendar::create(['title' => 'Localization', 'slug' => 'localization']);
        $series = CalendarSeries::create([
            'calendar_id' => $calendar->id, 'channel' => 'default', 'anchor' => '2026-01-05',
            'rule' => ['freq' => 'DAILY', 'count' => 2],
            'spawn' => ['mode' => 'generate', 'instructions' => 'Authored instructions'],
        ]);
        Route::post('/loc25/retry-service', fn (Request $r) => app(\Splicewire\Beam\Calendars\Actions\ActionService::class)->retry($r->input('id'), 1, app(ActionContextProvider::class)->current(), \Carbon\CarbonImmutable::parse('2026-09-19T13:00:00Z'), $r->input('idempotency_key')));
        Route::get('/loc25/instant', fn (Request $r) => ActionInstant::format(ActionInstant::parse($r->query('value'))));
        Route::get('/loc25/timezone', fn () => ActionLocalTime::atDate(now(), '2026-01-05', 'Invalid/Zone'));
        Route::get('/loc25/export', fn (Request $r) => ExportCalendar::handle($calendar, $r));
        Route::post('/loc25/materialize', fn (Request $r) => MaterializeOccurrence::handle($calendar, $r));
        Route::post('/loc25/missing-occurrence', function (Request $r) use ($calendar, $series) {
            $r->merge(['series_id' => $series->id, 'recurrence_id' => '2026-02-01']);

            return MaterializeOccurrence::handle($calendar, $r);
        });
        Route::get('/loc25/kind', fn () => app(KindValidator::class)->validate('kind.unknown', []));
        Route::get('/loc25/labels', fn () => response()->json([
            ...array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], RecurrenceFrequency::cases()),
            ...array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], SpawnMode::cases()),
        ]));
    }

    public function test_english_wire_snapshots(): void
    {
        $this->journey();
        $path = getenv('LOCALIZATION_CALENDARS_SNAPSHOTS');
        if ($path) {
            file_put_contents($path, json_encode($this->snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    #[Group('localization-pseudo')]
    public function test_pseudolocale_wire_journey(): void
    {
        $this->pseudo = true;
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale('en-XA');
        $this->journey();
    }

    private function capture(string $name, $response, int $status, string $code, ?string $field = null): void
    {
        $response->assertStatus($status);
        $body = $response->json();
        $this->snapshots[$name] = ['status' => $response->status(), 'contentType' => $response->headers->get('Content-Type'), 'authenticateHeader' => $response->headers->get('WWW-Authenticate'), 'body' => $body];
        if ($this->pseudo) {
            $actual = $field === null ? ($body['code'] ?? null) : data_get($body, 'codes.'.$field.'.0');
            $marked = PseudoLocale::isMarked($body['message']);
            $fieldMarked = $field === null || PseudoLocale::isMarked(data_get($body, 'errors.'.$field.'.0'));
            $this->pseudoObservations[$code] = ['actualCode' => $actual, 'messageMarked' => $marked, 'fieldMarked' => $fieldMarked];
            if ($actual !== $code || ! $marked || ! $fieldMarked) {
                $this->pseudoFailures[] = $code;
            }
        }
    }

    private function journey(): void
    {
        $this->capture('instant_offset', $this->getJson('/loc25/instant?value=2026-01-01'), 422, 'calendar_instant_offset_required', 'due_at');
        $this->capture('instant_invalid', $this->getJson('/loc25/instant?value=2026-02-31T12:00:00Z'), 422, 'calendar_instant_invalid', 'due_at');
        $this->capture('timezone', $this->getJson('/loc25/timezone'), 422, 'calendar_timezone_invalid', 'timezone');
        $this->capture('export', $this->getJson('/loc25/export?format=missing&from=2026-01-01&to=2026-01-31'), 422, 'calendar_export_unsupported', 'format');
        $this->capture('kind', $this->getJson('/loc25/kind'), 422, 'calendar_kind_unknown', 'kind');
        $this->capture('series', $this->postJson('/loc25/materialize', ['series_id' => 'missing', 'recurrence_id' => '2026-01-05']), 422, 'calendar_series_calendar_mismatch', 'series_id');
        $this->capture('occurrence', $this->postJson('/loc25/missing-occurrence'), 422, 'calendar_occurrence_missing', 'recurrence_id');
        $payload = ['kind' => 'kind.local', 'payload' => ['message' => 'Authored payload'], 'due_at' => '2026-09-18T13:00:00Z', 'timezone' => 'UTC'];
        $this->capture('origin_reserved', $this->postJson('/loc25/calendar-actions/schedule', $payload + ['origin' => 'action-series:reserved']), 422, 'calendar_origin_reserved', 'origin');
        $missing = $payload;
        $missing['kind'] = 'kind.missing';
        $this->capture('handler', $this->postJson('/loc25/calendar-actions/schedule', $missing), 422, 'calendar_handler_unavailable', 'kind');
        $id = $this->postJson('/loc25/calendar-actions/schedule', $payload)->assertOk()->json('data.id');
        $this->capture('stale_revision', $this->postJson('/loc25/calendar-actions/'.$id.'/cancel', ['expected_revision' => 99]), 409, 'calendar_action_changed');
        $this->postJson('/loc25/calendar-actions/'.$id.'/cancel', ['expected_revision' => 1])->assertOk();
        // Exercise the service guard directly only where the public DTO's generic required rule
        // would short-circuit a malformed retry key before the domain guard can answer.
        $this->capture('retry_key_invalid', $this->postJson('/loc25/retry-service', ['id' => $id, 'idempotency_key' => str_repeat('k', 256)]), 422, 'calendar_retry_key_invalid', 'idempotency_key');
        $originPayload = $payload + ['origin' => 'localization:origin'];
        $originId = $this->postJson('/loc25/calendar-actions/schedule', $originPayload)->assertOk()->json('data.id');
        $changedOrigin = $originPayload;
        $changedOrigin['origin'] = 'localization:other';
        $this->capture('origin_immutable', $this->postJson('/loc25/calendar-actions/'.$originId.'/reschedule', ['expected_revision' => 1, 'action' => $changedOrigin]), 409, 'calendar_origin_immutable');
        $changedIntent = $originPayload;
        $changedIntent['payload']['message'] = 'Different authored intent';
        $this->capture('origin_used', $this->postJson('/loc25/calendar-actions/schedule', $changedIntent), 409, 'calendar_origin_used');
        $input = ['action' => $payload, 'rule' => ['freq' => 'DAILY', 'count' => 2]];
        $both = $input;
        $both['rule']['until'] = '2026-09-30';
        $this->capture('rule_limit', $this->postJson('/loc25/calendar-action-series/schedule', $both), 422, 'calendar_rule_limit_exclusive', 'rule');
        $seriesId = $this->postJson('/loc25/calendar-action-series/schedule', $input)->assertOk()->json('data.id');
        $this->capture('rule_occurrence', $this->postJson('/loc25/calendar-action-series/'.$seriesId.'/pin', ['expected_revision' => 1, 'recurrence_id' => '2026-09-25']), 422, 'calendar_rule_occurrence_missing', 'recurrence_id');
        $pinId = $this->postJson('/loc25/calendar-action-series/'.$seriesId.'/pin', ['expected_revision' => 1, 'recurrence_id' => '2026-09-18'])->assertOk()->json('data.id');
        $replacement = $payload + ['origin' => 'action-series:'.$seriesId.':2026-09-18', 'series_id' => '00000000-0000-4000-8000-000000000099', 'recurrence_id' => '2026-09-18'];
        $this->capture('series_identity', $this->postJson('/loc25/calendar-actions/'.$pinId.'/reschedule', ['expected_revision' => 1, 'action' => $replacement]), 409, 'calendar_series_identity_immutable');
        $this->postJson('/loc25/calendar-action-series/'.$seriesId.'/skip', ['expected_revision' => 1, 'recurrence_id' => '2026-09-19'])->assertOk();
        $this->capture('occurrence_skipped', $this->postJson('/loc25/calendar-action-series/'.$seriesId.'/pin', ['expected_revision' => 2, 'recurrence_id' => '2026-09-19']), 409, 'calendar_occurrence_skipped');
        $this->capture('series_stale', $this->postJson('/loc25/calendar-action-series/'.$seriesId.'/cancel', ['expected_revision' => 1]), 409, 'calendar_series_changed');
        $retryId = $this->postJson('/loc25/calendar-actions/schedule', $payload)->assertOk()->json('data.id');
        config(['beam.calendars.action-handlers' => ['kind.local' => Fakes\BlockedLocalActionHandler::class]]);
        app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($retryId, 'tenant:one', \Carbon\CarbonImmutable::parse('2026-09-18T13:00:00Z'));
        $retry = ['expected_revision' => 2, 'due_at' => '2026-09-19T13:00:00Z', 'idempotency_key' => 'localization-retry'];
        $this->postJson('/loc25/calendar-actions/'.$retryId.'/retry', $retry)->assertOk();
        $retry['due_at'] = '2026-09-20T13:00:00Z';
        $this->capture('retry_key_used', $this->postJson('/loc25/calendar-actions/'.$retryId.'/retry', $retry), 409, 'calendar_retry_key_used');
        config(['beam.calendars.action-handlers' => ['kind.local' => LocalActionHandler::class]]);
        $this->assertCount(19, $this->snapshots, 'Every immediate refusal must run through its actual journey.');
        $labels = $this->getJson('/loc25/labels')->assertOk()->json();
        $this->assertSame(['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY', 'generate', 'reference'], array_column($labels, 'value'));
        if ($this->pseudo) {
            foreach ($labels as $row) {
                if (! PseudoLocale::isMarked($row['label'])) {
                    $this->pseudoFailures[] = 'label:'.$row['value'];
                }
            }
        } else {
            $this->assertSame(['Daily', 'Weekly', 'Monthly', 'Yearly', 'Generate a new item', 'Reference a fixed item'], array_column($labels, 'label'));
            $this->snapshots['labels'] = $labels;
        }
        if ($this->pseudo) {
            $report = getenv('LOCALIZATION_CALENDARS_PSEUDO_REPORT');
            if ($report) {
                file_put_contents($report, json_encode(['observations' => $this->pseudoObservations, 'failures' => $this->pseudoFailures], JSON_PRETTY_PRINT)."\n");
            }
            $this->assertSame([], $this->pseudoFailures, 'All actual refusals and runtime labels follow the locale.');
        }
    }
}
