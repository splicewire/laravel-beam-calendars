<?php

namespace Splicewire\Beam\Calendars\Scheduling;

use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Splicewire\Beam\Calendars\Contracts\SpawnDriver;

/**
 * The ONE place the configured {@see SpawnDriver} is reached, and it reaches BLIND: it resolves a
 * class-string through the container and never names a concrete driver. That is what lets an
 * engine supply one without this package being able to see the engine.
 *
 * Resolution accepts three shapes deliberately. `null` is the free-tier default — a calendar with
 * no engine behind it, which is a complete feature and not a broken install. An INSTANCE is
 * accepted so a test can bind a fake directly without a container round-trip. A class-string is
 * made through the container, so a driver may declare its own constructor dependencies and this
 * package never learns what they are.
 */
class SpawnDriverResolver
{
    public function __construct(private Container $container) {}

    /**
     * Null-tolerant. This is the ORDINARY sweep path: no driver configured means there is nothing
     * to spawn, which is a legitimate state, not an error.
     */
    public function resolve(): ?SpawnDriver
    {
        $configured = config('beam.calendars.spawn_driver');

        if ($configured === null || $configured === '') {
            return null;
        }

        if ($configured instanceof SpawnDriver) {
            return $configured;
        }

        if (is_string($configured)) {
            $driver = $this->container->make($configured);

            if (! $driver instanceof SpawnDriver) {
                // `get_debug_type($driver)` rather than `$configured::class` — the latter is a
                // TypeError on a string, so the guard would crash instead of reporting, which is
                // the one thing an error path must not do.
                throw new RuntimeException(sprintf(
                    '[beam.calendars.spawn_driver] is [%s], which resolved to %s and does not implement %s.',
                    $configured,
                    get_debug_type($driver),
                    SpawnDriver::class,
                ));
            }

            return $driver;
        }

        throw new RuntimeException(
            '[beam.calendars.spawn_driver] must be null, a class-string, or a SpawnDriver instance.'
        );
    }

    /**
     * The EXPLICIT path — someone asked for one occurrence to be fired by hand. Here a missing
     * driver is a programmer error rather than a passive default, so it throws.
     *
     * The two methods exist separately because collapsing them would force one of the two callers
     * to be wrong: a null-tolerant sweep that throws would break every free-tier host on its first
     * cron tick, and an explicit fire that silently no-ops would report success for doing nothing.
     */
    public function resolveOrFail(): SpawnDriver
    {
        return $this->resolve() ?? throw new RuntimeException(
            'No calendar spawn driver is configured — set [beam.calendars.spawn_driver] to a class '
            .'implementing '.SpawnDriver::class.'. The free tier records firings without spawning, '
            .'so this operation is unavailable rather than silently doing nothing.'
        );
    }
}
