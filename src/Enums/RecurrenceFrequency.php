<?php

namespace Splicewire\Beam\Calendars\Enums;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

/**
 * The cadence axis of a {@see \Splicewire\Beam\Calendars\Data\RecurrenceRuleData} — RFC-5545's
 * `FREQ`. A backed enum so a persisted rule's `freq` projects to a JSON-Schema `enum` and an
 * invalid value is rejected on write by the typed-payload validator, and so the ICS RRULE string
 * (an output projection, never storage) round-trips these exact tokens.
 *
 * Implements {@see ProvidesEnumLabel} so a series form renders the human cadence ("Daily")
 * instead of the RFC token (`DAILY`).
 */
enum RecurrenceFrequency: string implements ProvidesEnumLabel
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case Yearly = 'YEARLY';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }
}
