<?php

namespace Splicewire\Beam\Calendars\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\BeamCalendarsServiceProvider;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry;

class ActionServiceBindingTest extends TestCase
{
    private function container(): Container
    {
        $app = new Container;
        $app->instance(ConfigRepository::class, new Repository);

        return $app;
    }

    public function test_the_calendar_provider_advertises_its_resolvable_action_service(): void
    {
        $app = $this->container();

        // Loading the class alone must not enable an optional calendar consumer.
        self::assertTrue(class_exists(ActionService::class));
        self::assertFalse($app->bound(ActionService::class));

        (new BeamCalendarsServiceProvider($app))->packageRegistered();

        self::assertTrue($app->bound(ActionService::class));
        self::assertInstanceOf(ActionService::class, $app->make(ActionService::class));
        // Installing calendars still does not fabricate a host's trusted actor context.
        self::assertFalse($app->bound(ActionContextProvider::class));
    }

    public function test_the_package_default_preserves_an_existing_host_service(): void
    {
        $app = $this->container();
        $custom = new ActionService(new ActionHandlerRegistry(new Repository));
        $app->instance(ActionService::class, $custom);

        (new BeamCalendarsServiceProvider($app))->packageRegistered();

        self::assertSame($custom, $app->make(ActionService::class));
    }

    public function test_a_later_host_binding_can_replace_the_package_default(): void
    {
        $app = $this->container();
        (new BeamCalendarsServiceProvider($app))->packageRegistered();
        $custom = new ActionService(new ActionHandlerRegistry(new Repository));
        $app->instance(ActionService::class, $custom);

        self::assertSame($custom, $app->make(ActionService::class));
    }
}
