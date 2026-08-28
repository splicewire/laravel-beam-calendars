<?php

namespace Splicewire\Beam\Calendars\Projection;

use Illuminate\Support\Carbon;

/**
 * The window a projection covers — a value object rather than a pair of loose arguments, because
 * every caller in this package needs both ends and passing them separately is how one of them ends
 * up forgotten.
 *
 * The end is REQUIRED and there is no "unbounded" constructor. An unbounded recurrence rule with an
 * unbounded horizon expands forever, and the expander's answer to that is to throw; making the
 * horizon always-bounded means that throw is unreachable from any read path in this package.
 */
class Horizon
{
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function between(Carbon|string $start, Carbon|string $end): self
    {
        $s = ($start instanceof Carbon ? $start->copy() : Carbon::parse($start))->startOfDay();
        $e = ($end instanceof Carbon ? $end->copy() : Carbon::parse($end))->endOfDay();

        // A reversed pair is a caller bug that would otherwise present as "the calendar is empty",
        // which is indistinguishable from a genuinely empty calendar. Normalise instead of guessing.
        return $s->lte($e) ? new self($s, $e) : new self($e->copy()->startOfDay(), $s->copy()->endOfDay());
    }

    /** The default window when a caller names none — `default_horizon_days` forward from today. */
    public static function default(?Carbon $from = null): self
    {
        $from ??= Carbon::now();
        $days = (int) config('beam.calendars.default_horizon_days', 90);

        return self::between($from, $from->copy()->addDays(max(1, $days)));
    }

    /** Everything up to and including `$now` — the scheduler's window of things that are DUE. */
    public static function upTo(Carbon $now, Carbon|string|null $from = null): self
    {
        return self::between($from ?? $now->copy()->subYears(5), $now);
    }

    public function covers(Carbon|string $date): bool
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $d->betweenIncluded($this->start, $this->end);
    }
}
