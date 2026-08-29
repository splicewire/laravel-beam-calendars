<?php

namespace Splicewire\Beam\Calendars\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Calendars\BeamCalendarsServiceProvider;
use Splicewire\Beam\Calendars\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createFixtureSchema();
        $this->runPackageMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            PopcornServiceProvider::class,

            // ⚠️ Testbench does NOT auto-discover: this harness boots exactly what this method
            // names, while `src/` freely imports anything it can autoload. This package declares
            // Data classes against spatie/laravel-data, and without this line `config('data')` is
            // NULL inside the suite — every hydration then fatals with an array-offset-on-null
            // before any assertion can run. A FATAL, not a failure, which is why it is worth naming.
            LaravelDataServiceProvider::class,

            // ⚠️ The FIFTH instance of the trap tower's TestCase documents four times: testbench does
            // not auto-discover. Without beam's own provider, ParticleResourceRegistry is not bound as
            // a singleton, so every app() call mints a fresh one and a registration lands in a
            // throwaway — the registry reads back EMPTY and the failure looks like discovery not
            // working. It is the binding, not the discovery.
            BeamServiceProvider::class,

            // ⚠️ The SIXTH instance of the same trap. `ResourceRegistry` is auto-resolvable, so
            // without this provider `app()` mints a FRESH one per call: the package's filter
            // registration would land in a throwaway and every read would see an empty registry —
            // green suite, no registrations, no error. It also binds `DataFilterManager`, which is
            // what `DataFilter::query()` resolves through.
            DataFiltersServiceProvider::class,

            BeamCalendarsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $c = $app['config'];
        $c->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $c->set('database.default', 'testing');
        $c->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $c->set('auth.providers.users.model', User::class);
        $c->set('permission-cascade.user_model', User::class);
        $c->set('permission-cascade.manage_spatie_teams', false);
        $c->set('permission.teams', false);

        // The particle surface needs laravel-beam's route macros, which are absent here, so
        // Resources::register() no-ops. The op HANDLERS are still tested directly — a mounted route
        // is a transport detail, and the handler is the thing with logic in it.
        $c->set('beam.calendars.register_resources', false);

        // Booting LaravelDataServiceProvider alone would be a FALSE GREEN. This package ships
        // `name_mapping_strategy.input => null`, but the host that will actually run this code sets
        // CamelCaseMapper — a DTO hydrates fine under testbench defaults and silently stops mapping
        // under the host's mapper. The harness mirrors the HOST, not the package default.
        $c->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        // Structure caching points at app_path('Data'), which does not exist under testbench — and
        // a reflection analysis cached across runs is exactly what a harness should not carry.
        $c->set('data.structure_caching.enabled', false);
    }

    /**
     * Runs the package's REAL migration stubs rather than a hand-built copy of them.
     *
     * The estate's usual package harness hand-writes an equivalent schema because these stubs used
     * to be Postgres-only. That works, and it means the fixture schema can drift away from the
     * migration with nothing to notice — a suite that passes against a table shape no host will
     * ever have. Making the stubs driver-portable was the cheaper fix, so this executes them.
     */
    protected function runPackageMigrations(): void
    {
        foreach ([
            'create_calendars_table',
            'create_calendar_series_table',
            'create_calendar_events_table',
            'create_calendar_firings_table',
        ] as $stub) {
            (require __DIR__.'/../database/migrations/shared/'.$stub.'.php.stub')->up();
        }
    }

    protected function createFixtureSchema(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });

        foreach ([
            'permissions' => fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name'), $t->timestamps()],
            'roles' => fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name'), $t->timestamps()],
        ] as $table => $define) {
            Schema::create($table, function (Blueprint $t) use ($define): void {
                $define($t);
                $t->unique(['name', 'guard_name']);
            });
        }

        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->string('model_id');
            $t->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->string('model_id');
            $t->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['permission_id', 'role_id']);
        });
    }
}
