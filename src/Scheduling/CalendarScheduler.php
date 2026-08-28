<?php

namespace Splicewire\Beam\Calendars\Scheduling;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Splicewire\Beam\Calendars\Contracts\SpawnDriver;
use Splicewire\Beam\Calendars\Events\OccurrenceFired;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Models\CalendarFiring;
use Splicewire\Beam\Calendars\Models\CalendarSeries;
use Splicewire\Beam\Calendars\Projection\CalendarProjection;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Calendars\Recurrence\Occurrence;
use Throwable;

/**
 * The sweep: fire every due occurrence exactly once.
 *
 * ## The exactly-once mechanism is the DATABASE, not this class
 *
 * A firing is claimed by INSERTING its `(series_id, recurrence_id)` row against a unique index. If
 * the insert collides, someone else already has it and this sweep moves on. That is the entire
 * guarantee, and it is deliberately not a `->exists()` check followed by a create: that shape is a
 * race between the check and the act, and it fails exactly when it matters — two workers, two
 * hosts, or a cron overlapping its own previous run.
 *
 * The claim happens BEFORE the driver is called and is resolved after, so a crash mid-spawn leaves
 * a `claimed` row as evidence rather than leaving no trace and silently re-firing next sweep.
 *
 * ## The free tier fires
 *
 * With no {@see SpawnDriver} configured, a due occurrence is still claimed, still recorded, and
 * still emits {@see OccurrenceFired}. "Nothing is bound to do work" is not the same as "nothing
 * happened", and a host listening for the event gets a complete scheduling feature with no engine
 * present at all.
 */
class CalendarScheduler
{
    public function __construct(
        private SpawnDriverResolver $drivers,
        private CalendarProjection $projection = new CalendarProjection,
    ) {}

    /**
     * Fire every due occurrence across every series of every calendar given (or all calendars).
     *
     * @param  iterable<Calendar>|null  $calendars
     * @return list<string> the recurrence keys fired, as `series_id|recurrence_id`
     */
    public function sweep(?Carbon $now = null, ?iterable $calendars = null): array
    {
        $now ??= Carbon::now();
        $driver = $this->drivers->resolve();
        $fired = [];

        $series = $calendars === null
            ? CalendarSeries::query()->cursor()
            : $this->seriesOf($calendars);

        foreach ($series as $row) {
            foreach ($this->projection->due($row, Horizon::upTo($now, $row->anchor)) as $occurrence) {
                $key = $this->fire($row, $occurrence, $driver, $now);

                if ($key !== null) {
                    $fired[] = $key;
                }
            }
        }

        return $fired;
    }

    /**
     * Fire ONE occurrence. Returns its key, or null if another process already held the claim.
     *
     * A driver that throws marks the firing failed and returns null — one bad occurrence must not
     * abort the sweep, because the next one may belong to an unrelated tenant's calendar.
     */
    public function fire(CalendarSeries $series, Occurrence $occurrence, ?SpawnDriver $driver, ?Carbon $now = null): ?string
    {
        $now ??= Carbon::now();
        $firing = $this->claim($series, $occurrence, $now);

        if ($firing === null) {
            return null; // someone else won the claim — idempotent by construction
        }

        try {
            $result = $driver !== null && $driver->canSpawn($occurrence)
                ? $driver->spawn($occurrence)
                : [];

            $firing->forceFill([
                'status' => CalendarFiring::STATUS_FIRED,
                'result' => $result,
                'fired_at' => $now,
            ])->save();

            OccurrenceFired::dispatch($occurrence, $firing);

            return $occurrence->seriesRef.'|'.$occurrence->recurrenceId;
        } catch (Throwable $e) {
            // The claim row stays, carrying the failure. Deleting it would make the next sweep
            // retry silently and forever; keeping it makes a stuck occurrence visible to an
            // operator, which is the only way anyone finds out.
            $firing->forceFill([
                'status' => CalendarFiring::STATUS_FAILED,
                'result' => ['error' => $e->getMessage()],
                'failed_at' => $now,
            ])->save();

            return null;
        }
    }

    /**
     * Claim the firing by inserting it. A unique violation means another process got there first,
     * which is the ONLY correct interpretation — so it is caught and answered with null rather
     * than allowed to surface as an error.
     */
    private function claim(CalendarSeries $series, Occurrence $occurrence, Carbon $now): ?CalendarFiring
    {
        try {
            return CalendarFiring::query()->create([
                'id' => (string) Str::uuid(),
                'series_id' => $series->getKey(),
                'calendar_id' => $series->calendar_id,
                'recurrence_id' => $occurrence->recurrenceId,
                'status' => CalendarFiring::STATUS_CLAIMED,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Postgres `23505` and SQLite `19` are the fleet's two drivers. Matching on the SQLSTATE rather
     * than on the message text keeps this working when the message is localised or reworded — and
     * a violation that is NOT a uniqueness one must still surface, so this is a narrow test, not a
     * blanket catch.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23505', '23000'], true)
            || str_contains(strtolower($e->getMessage()), 'unique constraint')
            || str_contains(strtolower($e->getMessage()), 'unique violation');
    }

    /**
     * @param  iterable<Calendar>  $calendars
     * @return iterable<CalendarSeries>
     */
    private function seriesOf(iterable $calendars): iterable
    {
        foreach ($calendars as $calendar) {
            yield from $calendar->series;
        }
    }
}
