<?php

namespace Splicewire\Beam\Calendars\Tests;

use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Splicewire\Beam\Calendars\Routing\CalendarsRoutes;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

class PartialMountRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaravelDataSchemasServiceProvider::class];
    }

    public function test_mount_preserves_provider_action_declarations_when_general_registration_is_disabled(): void
    {
        $this->assertFalse(config('beam.calendars.register_resources'));
        $registry = app(ParticleResourceRegistry::class);
        foreach (['calendar-actions', 'calendar-action-series'] as $key) {
            $this->assertTrue($registry->has($key));
            $this->assertSame([], $registry->superseded($key));
        }
        foreach (['calendars', 'calendar-events', 'calendar-series'] as $key) {
            $this->assertFalse($registry->has($key));
        }

        CalendarsRoutes::mount();

        foreach (['calendar-actions', 'calendars', 'calendar-events', 'calendar-action-series', 'calendar-series'] as $key) {
            $this->assertTrue($registry->has($key), $key.' must be registered');
            $this->assertSame([], $registry->superseded($key), $key.' must have no supersession record');
        }
    }
}
