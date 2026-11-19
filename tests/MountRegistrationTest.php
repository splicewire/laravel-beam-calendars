<?php

namespace Splicewire\Beam\Calendars\Tests;

use Splicewire\Beam\Calendars\Routing\CalendarsRoutes;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

class MountRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('beam.calendars.register_resources', true);
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class];
    }

    public function test_mount_after_provider_boot_does_not_supersede_resource_declarations(): void
    {
        $registry = app(ParticleResourceRegistry::class);
        $this->assertResourcesRegisteredOnce($registry);

        CalendarsRoutes::mount();

        $this->assertResourcesRegisteredOnce($registry);
    }

    public function test_mount_still_declares_resources_when_the_registry_has_no_package_declarations(): void
    {
        $registry = new ParticleResourceRegistry;
        $this->app->instance(ParticleResourceRegistry::class, $registry);
        $this->assertFalse($registry->has('calendars'));

        CalendarsRoutes::mount();

        $this->assertResourcesRegisteredOnce($registry);
    }

    private function assertResourcesRegisteredOnce(ParticleResourceRegistry $registry): void
    {
        foreach (['calendar-actions', 'calendars', 'calendar-events', 'calendar-action-series', 'calendar-series'] as $key) {
            $this->assertTrue($registry->has($key), $key.' must be registered');
            $this->assertSame([], $registry->superseded($key), $key.' must have no supersession record');
        }
    }
}
