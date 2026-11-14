<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Calendars\ActionResources;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\ActionSeriesResources;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\CalendarActionAttemptData;
use Splicewire\Beam\Calendars\Data\CalendarActionSeriesData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Calendars\Tests\Fakes\LocalActionHandler;
use Splicewire\Beam\Localization\LocaleScope;

/*
 * localization 37 (SCOUT-37 ruling 3; SIGNATURES-37i): a blocked series' stored blocker renders for its reader, because
 * the status proves the sentence. Nothing stored changes, and attempt blockers (unproven) stay literal.
 */

const MATERIALIZATION_BLOCKED = 'Occurrence materialization is unavailable or no longer authorized. Restore the handler or authority, then resume this series.';

beforeEach(function () {
    app()->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
    config(['beam.calendars.action-handlers' => ['kind.local' => LocalActionHandler::class]]);
    $provider = new class implements ActionContextProvider
    {
        public ActionContext $context;

        public function current(): ActionContext
        {
            return $this->context;
        }
    };
    $provider->context = new ActionContext('editor:1', 'user:1', 'tenant:one');
    app()->instance(ActionContextProvider::class, $provider);
    Route::prefix('api/beam')->group(function () {
        ActionResources::mount();
        ActionSeriesResources::mount();
    });
    $this->input = [
        'action' => ['kind' => 'kind.local', 'payload' => ['message' => 'Recurring'], 'due_at' => '2026-09-18T13:00:00.000Z', 'timezone' => 'America/New_York', 'calendar_id' => 'calendar:standalone'],
        'rule' => ['freq' => 'DAILY', 'count' => 3],
    ];

    $this->lang = sys_get_temp_dir().'/calendars-series-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/vendor/beam-calendars/es', 0777, true);
    file_put_contents($this->lang.'/vendor/beam-calendars/es/series.php', '<?php return '.var_export([
        'materialization_blocked' => 'La materialización de ocurrencias no está disponible o ya no está autorizada. Restaura el controlador o la autoridad y reanuda esta serie.',
    ], true).';');
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config(['beam.localization.supported' => ['en', 'es'], 'app.locale' => 'en']);
    app()->setLocale('en');
});

it('renders a blocked series\' stored blocker for two readers, storage unchanged', function () {
    $id = $this->postJson('/api/beam/calendar-action-series/schedule', $this->input)->assertOk()->json('data.id');
    config(['beam.calendars.action-handlers' => []]);
    app(ActionSeriesService::class)->sweep('tenant:one', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    $series = CalendarActionSeries::find($id);
    $stored = json_encode($series->getRawOriginal('blockers'));

    $en = CalendarActionSeriesData::fromModel($series);
    $es = LocaleScope::run('es', fn () => CalendarActionSeriesData::fromModel($series));

    expect($series->status)->toBe('blocked')
        ->and($series->blockers)->toBe([MATERIALIZATION_BLOCKED])
        ->and($en->blockers)->toBe([MATERIALIZATION_BLOCKED], 'English is the stored sentence')
        ->and($es->blockers)->toBe(['La materialización de ocurrencias no está disponible o ya no está autorizada. Restaura el controlador o la autoridad y reanuda esta serie.'])
        ->and([$es->id, $es->revision, $es->status])->toBe([$en->id, $en->revision, $en->status])
        ->and(json_encode(CalendarActionSeries::find($id)->getRawOriginal('blockers')))->toBe($stored);
});

it('passes an active series\' empty blocker list through unchanged', function () {
    $id = $this->postJson('/api/beam/calendar-action-series/schedule', $this->input)->assertOk()->json('data.id');

    expect(LocaleScope::run('es', fn () => CalendarActionSeriesData::fromModel(CalendarActionSeries::find($id))->blockers))->toBe([]);
});

it('keeps every scheduler attempt sentence literal for another reader', function (string $status, string $sentence, array $result) {
    $attempt = (new CalendarActionAttempt)->forceFill([
        'id' => 'attempt-1', 'revision' => 1, 'number' => 1, 'status' => $status, 'blockers' => [$sentence], 'result' => $result,
        'request' => ['due_at' => '2026-09-18T13:00:00.000Z'],
    ]);

    expect(LocaleScope::run('es', fn () => CalendarActionAttemptData::fromModel($attempt)->blockers))->toBe([$sentence]);
})->with([
    'handler unavailable' => ['failed', 'The execution handler for this action kind is unavailable.', []],
    'principal unauthorized' => ['blocked', 'The execution principal is no longer authorized.', []],
    'handler failed' => ['failed', 'The action handler failed.', ['exception' => RuntimeException::class]],
]);

it('never rewrites a blocked row whose subject is not a series, or that has no subject', function (?string $subjectType) {
    $row = ['status' => 'blocked', 'subject_type' => $subjectType];

    $line = LocaleScope::run('es', fn () => app(Splicewire\Beam\Localization\Presentation\MessagePresenters::class)->present('calendar-series', $row, null, MATERIALIZATION_BLOCKED));

    expect($line->source)->toBe(Splicewire\Beam\Localization\Presentation\PresentedLine::LEGACY)
        ->and($line->text)->toBe(MATERIALIZATION_BLOCKED);
})->with([
    'a calendar action (not a series)' => [CalendarAction::class],
    'subjectless' => [null],
    'empty subject' => [''],
]);
