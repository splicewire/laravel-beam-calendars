<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Data\SweepInputData;
use Splicewire\Beam\Calendars\Data\SweepResultData;
use Splicewire\Beam\Calendars\Jobs\SweepCalendarJob;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * Fire this calendar's due occurrences — a Task op, so `handle()` returns the JOB and the framework
 * dispatches it.
 *
 * ⚠️ **`respond()` is mandatory for a Task and is the whole reason this class is more than three
 * lines.** Without it a Task answers with a bare `{ queued: true }`, which means the `output:`
 * declaration above — and therefore the generated client's type for this call — is simply false.
 * The declared shape has to be the shape that actually comes back.
 *
 * `ability: 'update'` rather than `view`: a sweep has side effects, so being able to READ a
 * calendar must not be enough to make it fire. Note that CLI invocation is ungated by policy — the
 * ability here exists for the HTTP and MCP transports, and a console sweep is authorised by being
 * able to run the console.
 */
#[ParticleOp(
    resource: 'calendars',
    name: 'sweep',
    kind: OperationKind::Task,
    model: Calendar::class,
    ability: 'update',
    input: SweepInputData::class,
    output: SweepResultData::class,
)]
class SweepCalendar
{
    public static function handle(Calendar $calendar, Request $request, mixed $actor = null): SweepCalendarJob
    {
        return new SweepCalendarJob($calendar, $request->input('at'));
    }

    /** The honest answer for a queued sweep: it is queued, and nothing has fired YET. */
    public static function respond(Calendar $calendar, bool $queued): SweepResultData
    {
        return new SweepResultData(
            calendarId: (string) $calendar->getKey(),
            queued: $queued,
            fired: [],
            firedCount: 0,
        );
    }
}
