<?php

namespace Splicewire\Beam\Calendars\Recurrence;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Calendars\Data\RecurrenceRuleData;
use Splicewire\Beam\Calendars\Data\SeriesData;
use Splicewire\Beam\Calendars\Data\SpawnData;
use Splicewire\Beam\Calendars\Enums\RecurrenceFrequency;

/**
 * Expands a {@see SeriesData}'s typed recurrence rule into virtual {@see Occurrence}s.
 * Pure and deterministic — no persistence, and no clock beyond the horizon it is handed.
 * That purity is what lets the whole recurrence model be unit-tested without a database,
 * and it is the reason expansion lives here rather than inside the scheduler.
 *
 * This is the size-invariance payoff: a 52-week series is ONE stored row that expands here
 * into 52 virtual Occurrences at projection or fire time.
 *
 * Bounded by the earliest of the rule's own `until`, the series' `window`, and an
 * optional caller `horizonEnd` (the Scheduler passes `now` to expand only due
 * Occurrences); `count` caps the number emitted. An otherwise-unbounded rule (no
 * `count`, no `until`, no `window`, no `horizonEnd`) throws — the model never expands
 * to infinity.
 */
class SeriesExpander
{
    /**
     * A hard ceiling so a mis-specified rule can never loop unbounded even with a far
     * horizon; well above any real cadence over a sane window.
     */
    private const MAX_OCCURRENCES = 5000;

    /** BYDAY token → day offset from the (Monday-based) start of its week. */
    private const WEEKDAY_OFFSET = [
        'MO' => 0, 'TU' => 1, 'WE' => 2, 'TH' => 3, 'FR' => 4, 'SA' => 5, 'SU' => 6,
    ];

    /**
     * @param  string  $seriesRef  the source series row's id (the occurrence's back-pointer)
     * @return list<Occurrence>
     */
    public function expand(SeriesData $series, string $seriesRef, ?Carbon $horizonEnd = null): array
    {
        $rule = $series->rule;
        $interval = max(1, $this->optionalInt($rule->interval, 1));
        $count = $this->optionalInt($rule->count, 0);
        $byday = $this->byday($rule);

        $start = Carbon::parse($series->anchor)->startOfDay();
        $end = $this->effectiveEnd($series, $horizonEnd);

        if ($end === null && $count <= 0) {
            throw new InvalidArgumentException(
                'An unbounded series (no count, until, window, or horizon) cannot be expanded.'
            );
        }

        $occurrences = [];
        $cursor = $start->copy();
        $steps = 0;
        $emitted = 0; // generated instances (emitted + skipped) — RFC-5545 counts both against `count`

        while (count($occurrences) < self::MAX_OCCURRENCES && $steps < self::MAX_OCCURRENCES) {
            $steps++;

            foreach ($this->datesForStep($cursor, $rule->freq, $byday) as $date) {
                if ($date->lt($start)) {
                    continue;
                }
                if ($end !== null && $date->gt($end)) {
                    return $occurrences;
                }

                $recurrenceId = self::recurrenceId($date);
                $spawn = self::resolveSpawn($series, $recurrenceId);

                // A `skip` override (EXDATE) drops the Occurrence entirely — it is not
                // projected and the Scheduler never fires it. It still counts against a
                // `count`-bounded rule (RFC-5545: an excluded instance is still generated
                // then removed), so bump the counter without emitting.
                if ($spawn === null) {
                    if ($count > 0 && ++$emitted >= $count) {
                        return $occurrences;
                    }

                    continue;
                }

                $occurrences[] = new Occurrence(
                    channel: $series->channel,
                    anchor: $date->toDateString(),
                    recurrenceId: $recurrenceId,
                    seriesRef: $seriesRef,
                    spawn: $spawn,
                );

                if ($count > 0 && ++$emitted >= $count) {
                    return $occurrences;
                }
            }

            $cursor = $this->advance($cursor, $rule->freq, $interval);

            if ($end !== null && $cursor->copy()->startOfWeek(Carbon::MONDAY)->gt($end)) {
                break;
            }
        }

        return $occurrences;
    }

    /**
     * The RFC-5545 `RECURRENCE-ID` for a dated occurrence — its own date. Deterministic
     * per `(series, date)`, so the firing ledger's `(series_ref, recurrence_id)` key can
     * never double-fire. One authority both the expander and the Scheduler
     * consume.
     */
    public static function recurrenceId(Carbon|string $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse($date))->toDateString();
    }

    /**
     * Project the typed rule to an RFC-5545 RRULE string — OUTPUT only (the ICS renderer's line), derived from the typed truth, never stored.
     */
    public static function rrule(RecurrenceRuleData $rule): string
    {
        $parts = ['FREQ='.$rule->freq->value];

        $interval = $rule->interval instanceof Optional ? 1 : (int) $rule->interval;
        if ($interval > 1) {
            $parts[] = 'INTERVAL='.$interval;
        }
        if (! $rule->count instanceof Optional) {
            $parts[] = 'COUNT='.(int) $rule->count;
        }
        if (! $rule->until instanceof Optional) {
            $parts[] = 'UNTIL='.Carbon::parse((string) $rule->until)->format('Ymd');
        }
        if (! $rule->byday instanceof Optional && $rule->byday !== []) {
            $parts[] = 'BYDAY='.implode(',', $rule->byday);
        }

        return implode(';', $parts);
    }

    /**
     * The effective spawn for one Occurrence applying its
     * override if any: `skip` (EXDATE) returns null — the caller drops the Occurrence;
     * `replace` (RECURRENCE-ID) returns the override's own spawn (or the series default
     * when the override names no spawn); no override returns the series default. One
     * authority the expander and the scheduler's materialize path both consume.
     */
    public static function resolveSpawn(SeriesData $series, string $recurrenceId): ?SpawnData
    {
        foreach (self::overridesList($series) as $entry) {
            if (($entry['recurrence_id'] ?? null) !== $recurrenceId) {
                continue;
            }

            if (($entry['action'] ?? '') === 'skip') {
                return null;
            }

            return isset($entry['spawn']) && is_array($entry['spawn'])
                ? SpawnData::from($entry['spawn'])
                : $series->spawn;
        }

        return $series->spawn;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function overridesList(SeriesData $series): array
    {
        return $series->overrides instanceof Optional ? [] : array_values($series->overrides);
    }

    /**
     * The dates one cursor step yields. WEEKLY with BYDAY fans the step's (Monday-based)
     * week out to each configured weekday; every other frequency yields the cursor date.
     *
     * @param  list<string>  $byday
     * @return list<Carbon>
     */
    private function datesForStep(Carbon $cursor, RecurrenceFrequency $freq, array $byday): array
    {
        if ($freq === RecurrenceFrequency::Weekly && $byday !== []) {
            $weekStart = $cursor->copy()->startOfWeek(Carbon::MONDAY);
            $dates = [];
            foreach ($byday as $token) {
                $offset = self::WEEKDAY_OFFSET[strtoupper($token)] ?? null;
                if ($offset !== null) {
                    $dates[] = $weekStart->copy()->addDays($offset);
                }
            }
            usort($dates, fn (Carbon $a, Carbon $b): int => $a->getTimestamp() <=> $b->getTimestamp());

            return $dates;
        }

        return [$cursor->copy()];
    }

    private function advance(Carbon $cursor, RecurrenceFrequency $freq, int $interval): Carbon
    {
        return match ($freq) {
            RecurrenceFrequency::Daily => $cursor->copy()->addDays($interval),
            RecurrenceFrequency::Weekly => $cursor->copy()->addWeeks($interval),
            RecurrenceFrequency::Monthly => $cursor->copy()->addMonthsNoOverflow($interval),
            RecurrenceFrequency::Yearly => $cursor->copy()->addYears($interval),
        };
    }

    private function effectiveEnd(SeriesData $series, ?Carbon $horizonEnd): ?Carbon
    {
        $candidates = [];

        if (! $series->rule->until instanceof Optional) {
            $candidates[] = Carbon::parse((string) $series->rule->until)->endOfDay();
        }
        if ($series->window !== null) {
            $candidates[] = Carbon::parse($series->window)->endOfDay();
        }
        if ($horizonEnd !== null) {
            $candidates[] = $horizonEnd->copy();
        }

        return $candidates === [] ? null : collect($candidates)->min();
    }

    /**
     * @return list<string>
     */
    private function byday(RecurrenceRuleData $rule): array
    {
        return $rule->byday instanceof Optional ? [] : array_values($rule->byday);
    }

    private function optionalInt(int|Optional $value, int $default): int
    {
        return $value instanceof Optional ? $default : $value;
    }
}
