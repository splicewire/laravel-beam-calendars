<?php

if (PHP_SAPI !== 'cli') {
    if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit;
    }

}

// Test-only loopback host. No production route or authentication binding is installed.
$calendarFixtureRoot = dirname(__DIR__, 2);
$workflowFixtureRoot = getenv('BEAM_WORKFLOWS_FIXTURE_ROOT') ?: dirname($calendarFixtureRoot).'/laravel-beam-workflows';
$calendarFixtureDatabase = getenv('BEAM_CALENDAR_FIXTURE_DB') ?: sys_get_temp_dir().'/calendar-workflow-standalone-v2.sqlite';
define('CALENDAR_FIXTURE_ROOT', $calendarFixtureRoot);
define('WORKFLOW_FIXTURE_ROOT', $workflowFixtureRoot);
define('CALENDAR_FIXTURE_DATABASE', $calendarFixtureDatabase);
require $workflowFixtureRoot.'/vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionScheduler;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class StandaloneArticle extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'standalone_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'standalone-article';
    }
}

class StandaloneCalendarFixture extends Splicewire\Beam\Workflows\Tests\CalendarWorkflowTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.testing.database', CALENDAR_FIXTURE_DATABASE);
        $app['config']->set('app.debug', true);
        $app['config']->set('cors.allowed_origins', ['http://localhost:6019']);
        $app['config']->set('cors.supports_credentials', true);
    }

    public function schema(): void
    {
        $this->createActivityLogTable();
        $this->createDefinitionStoreTables();
        foreach (['create_workflow_transition_facts_table', 'create_workflow_action_receipts_table', 'create_workflow_reactions_tables'] as $name) {
            (require WORKFLOW_FIXTURE_ROOT.'/database/migrations/tenant/'.$name.'.php.stub')->up();
        }
        foreach (['create_calendar_actions_table', 'create_calendar_action_attempts_table', 'create_calendar_action_series_table'] as $name) {
            (require CALENDAR_FIXTURE_ROOT.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        if (! Schema::hasTable('standalone_articles')) {
            Schema::create('standalone_articles', function (Blueprint $table) {
                $table->id();
                $table->string('status')->default('draft');
                $table->uuid('workflow_version')->nullable();
            });
            StandaloneArticle::create(['status' => 'draft']);
        }
    }
}

class FixtureRunData extends Splicewire\Beam\Data\BeamData
{
    public function __construct(#[Spatie\LaravelData\Attributes\MapName('due_at')] public string $dueAt) {}
}
#[Splicewire\Beam\Particle\Attributes\ParticleOp(resource: 'calendar-actions', name: 'fixture-projection', subject: Splicewire\Beam\Particle\Subject\NoSubject::class, kind: Splicewire\Beam\Particle\OperationKind::Read, method: Splicewire\Beam\Routing\HttpMethod::Get, ability: false, input: false, output: Splicewire\Beam\Workflows\Data\WorkflowProjectionData::class)]
class FixtureProjection
{
    public static function handle(?object $model, Illuminate\Http\Request $request, mixed $actor): Splicewire\Beam\Workflows\Data\WorkflowProjectionData
    {
        return Splicewire\Beam\Workflows\Data\WorkflowProjectionData::from(app(Splicewire\Beam\Workflows\Control\WorkflowActuator::class)->projection(StandaloneArticle::findOrFail(1)));
    }
}
#[Splicewire\Beam\Particle\Attributes\ParticleOp(resource: 'calendar-actions', name: 'fixture-run', kind: Splicewire\Beam\Particle\OperationKind::Write, ability: false, input: FixtureRunData::class, output: Splicewire\Beam\Calendars\Data\CalendarActionRecordData::class)]
class FixtureRun
{
    public static function handle(Splicewire\Beam\Calendars\Models\CalendarAction $model, Illuminate\Http\Request $request, mixed $actor): Splicewire\Beam\Calendars\Data\CalendarActionRecordData
    {
        $input = FixtureRunData::from($request->all());
        $at = Splicewire\Beam\Calendars\Actions\ActionInstant::parse($input->dueAt);
        $previousMutableClock = Carbon\Carbon::getTestNow();
        $previousImmutableClock = Carbon\CarbonImmutable::getTestNow();
        Carbon\Carbon::setTestNow($at);
        Carbon\CarbonImmutable::setTestNow($at);
        try {
            app(ActionScheduler::class)->run($model->id, 'tenant:test', $at, expectedRevision: $model->revision);
        } finally {
            Carbon\Carbon::setTestNow($previousMutableClock);
            Carbon\CarbonImmutable::setTestNow($previousImmutableClock);
        }

        return Splicewire\Beam\Calendars\Data\CalendarActionRecordData::fromModel($model->fresh());
    }
}
#[Splicewire\Beam\Particle\Attributes\ParticleOp(resource: 'calendar-actions', name: 'fixture-transition', subject: Splicewire\Beam\Particle\Subject\NoSubject::class, kind: Splicewire\Beam\Particle\OperationKind::Write, ability: false, input: Splicewire\Beam\Workflows\Data\WorkflowTransitionRequestData::class, output: Splicewire\Beam\Workflows\Data\WorkflowTransitionAttemptData::class)]
class FixtureTransition
{
    public static function handle(?object $model, Illuminate\Http\Request $request, mixed $actor): Splicewire\Beam\Workflows\Data\WorkflowTransitionAttemptData
    {
        $input = Splicewire\Beam\Workflows\Data\WorkflowTransitionRequestData::from($request->all());
        $actuator = app(Splicewire\Beam\Workflows\Control\WorkflowActuator::class);
        $subject = StandaloneArticle::findOrFail(1);
        $result = $actuator->transition($subject, $input->transition, new Splicewire\Beam\Workflows\Control\TransitionContext('user:editor'));

        return Splicewire\Beam\Workflows\Data\WorkflowTransitionAttemptData::fromResult($result, $actuator->projection($subject->fresh()));
    }
}

if (! file_exists(CALENDAR_FIXTURE_DATABASE)) {
    touch(CALENDAR_FIXTURE_DATABASE);
}
$fixture = new StandaloneCalendarFixture('fixture');
$app = $fixture->createApplication();
$app->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
$fixture->schema();
$app->instance(ActionContextProvider::class, new class implements ActionContextProvider
{
    public function current(): ActionContext
    {
        return new ActionContext('user:editor', 'user:creator', 'tenant:test');
    }
});
$app->bind(WorkflowActionAuthority::class, fn () => new class implements WorkflowActionAuthority
{
    public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void
    {
        if ($context->principal !== 'user:editor' || $context->tenantToken !== 'tenant:test') {
            throw new Illuminate\Auth\Access\AuthorizationException('Unavailable fixture context.');
        }
    }
});
$app->make(Splicewire\Beam\Workflows\Definition\DefinitionStore::class)->ensureSystemLineage('standalone-article', 'Standalone Article', Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint::fromArray([
    'name' => 'standalone-article', 'places' => ['draft', 'published'], 'initial' => ['draft'],
    'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published'], ['name' => 'unpublish', 'from' => 'published', 'to' => 'draft']],
]));
$app->make(Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry::class)->bind('standalone-article', 'standalone-article');
$app->make(Splicewire\Beam\Workflows\Control\SubjectResolverRegistry::class)->register('article', fn ($id) => StandaloneArticle::find($id));
Illuminate\Support\Facades\Route::prefix('api/beam')->group(function () {
    Splicewire\Beam\Facades\Particle::ops('calendar-actions', 'calendar-actions', [FixtureProjection::class, FixtureRun::class, FixtureTransition::class]);
    Splicewire\Beam\Calendars\ActionResources::mount();
    Splicewire\Beam\Calendars\ActionSeriesResources::mount();
});
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'tick') {
        $attempts = app(Splicewire\Beam\Calendars\Actions\ActionSeriesService::class)->sweep('tenant:test', Carbon\CarbonImmutable::now('UTC'));
        echo json_encode(['attempts' => array_map(fn ($a) => ['id' => $a->id, 'status' => $a->status], $attempts), 'article_status' => StandaloneArticle::find(1)->status], JSON_PRETTY_PRINT)."\n";
    } else {
        echo 'Ready. Tower loaded: '.(class_exists(Splicewire\Tower\TowerServiceProvider::class) ? 'yes' : 'no')."\n";
    }
    exit;
}
$app->handleRequest(Illuminate\Http\Request::capture());
