<?php

namespace Splicewire\Beam\Calendars\Tests\Fakes;

use Splicewire\Beam\Calendars\Contracts\SpawnDriver;
use Splicewire\Beam\Calendars\Recurrence\Occurrence;

/**
 * A driver that records what it was asked to do.
 *
 * The port exists precisely so this package can be exercised with no engine present, so a fake
 * driver is not a compromise here — it is the intended free-tier shape, exercised directly.
 *
 * `$claims` lets a test make the driver decline an occurrence, which is the branch that proves
 * drivability is declared BY THE DRIVER rather than assumed by the substrate.
 */
class FakeSpawnDriver implements SpawnDriver
{
    /** @var list<Occurrence> */
    public array $spawned = [];

    public function __construct(
        public bool $claims = true,
        public ?\Throwable $throws = null,
    ) {}

    public function canSpawn(Occurrence $occurrence): bool
    {
        return $this->claims;
    }

    public function spawn(Occurrence $occurrence): array
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $this->spawned[] = $occurrence;

        return ['spawned' => $occurrence->recurrenceId];
    }
}
