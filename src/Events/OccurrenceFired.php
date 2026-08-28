<?php

namespace Splicewire\Beam\Calendars\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Splicewire\Beam\Calendars\Models\CalendarFiring;
use Splicewire\Beam\Calendars\Recurrence\Occurrence;

/**
 * A due occurrence was claimed and resolved.
 *
 * This is what makes the free tier a complete feature rather than a stub: with no SpawnDriver
 * bound, the scheduler still claims, records and announces every firing, so a host can listen and
 * do its own work without buying an engine — and without this package learning what that work is.
 *
 * Dispatched AFTER the firing row is marked fired, so a listener that reads the ledger sees a
 * consistent state rather than a row still marked `claimed`.
 */
class OccurrenceFired
{
    use Dispatchable;

    public function __construct(
        public readonly Occurrence $occurrence,
        public readonly CalendarFiring $firing,
    ) {}
}
