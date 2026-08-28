<?php

namespace Splicewire\Beam\Calendars\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Scheduling\CalendarScheduler;

/**
 * Sweeps one calendar's due occurrences off the request thread.
 *
 * Queued because a sweep calls out to whatever the engine bound as a SpawnDriver, once per due
 * occurrence — a catch-up sweep over a long-dormant series can be hundreds of those, and none of
 * them belong in an HTTP request's lifetime.
 *
 * The exactly-once guarantee lives in the database, not here, so this job being retried, duplicated
 * or run concurrently with another sweep is safe by construction rather than by queue configuration.
 *
 * ⚠️ A queued job needs a WORKER. On a host with a queue connection configured and nothing
 * consuming it, dispatching this succeeds and the sweep silently never runs — there is no error
 * anywhere, just occurrences that stay un-fired. That failure mode is the reason the console sweep
 * runs the scheduler directly rather than dispatching this.
 */
class SweepCalendarJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Calendar $calendar,
        public ?string $at = null,
    ) {}

    public function handle(CalendarScheduler $scheduler): void
    {
        $scheduler->sweep(
            $this->at !== null ? Carbon::parse($this->at) : null,
            [$this->calendar],
        );
    }
}
