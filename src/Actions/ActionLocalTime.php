<?php

namespace Splicewire\Beam\Calendars\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** One local-calendar-day policy for recurring and relative actions. */
class ActionLocalTime
{
    public static function shiftDays(CarbonInterface $instant, int $days, string $timezone): CarbonImmutable
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);
        $date = CarbonImmutable::parse($local->toDateString(), 'UTC')->addDays($days)->toDateString();

        return self::atDate($local, $date, $timezone);
    }

    public static function atDate(CarbonInterface $instant, string $date, string $timezone): CarbonImmutable
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Use an IANA timezone identifier.']);
        }
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $start = CarbonImmutable::instance($instant)->setTimezone($timezone);
        $local = CarbonImmutable::parse($date.' '.$start->format('H:i:s.u'), $timezone);
        // For an autumn overlap choose the earlier instant explicitly. PHP's default offset can
        // vary with construction path; enumerate the transition offsets to make the choice stable.
        $wall = CarbonImmutable::parse($date.' '.$start->format('H:i:s.u'), 'UTC');
        $candidates = [];
        foreach ((new \DateTimeZone($timezone))->getTransitions($wall->timestamp - 172800, $wall->timestamp + 172800) ?: [] as $transition) {
            $candidate = $wall->subSeconds($transition['offset'])->setTimezone($timezone);
            if ($candidate->format('Y-m-d H:i:s.u') === $wall->format('Y-m-d H:i:s.u')) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates !== []) {
            usort($candidates, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());
            $local = $candidates[0];
        }

        // A spring gap follows PHP/IANA forward normalization, preserving minutes within the gap.
        return $local->utc();
    }
}
