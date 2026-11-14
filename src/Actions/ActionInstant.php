<?php

namespace Splicewire\Beam\Calendars\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Exception;
use Splicewire\Beam\Calendars\Localization\CalendarError;

/** Offset-bearing, microsecond-precision instants; date-only calendar anchors stay unchanged. */
class ActionInstant
{
    public static function parse(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw CalendarError::InstantOffset->validation('due_at');
        }

        try {
            $instant = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
        } catch (Exception) {
            throw CalendarError::InstantInvalid->validation('due_at');
        }

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw CalendarError::InstantInvalid->validation('due_at');
        }

        return CarbonImmutable::instance($instant)->utc();
    }

    public static function format(CarbonInterface $instant): string
    {
        return $instant->format('u') === '000000'
            ? $instant->toIso8601String()
            : $instant->format('Y-m-d\TH:i:s.uP');
    }
}
