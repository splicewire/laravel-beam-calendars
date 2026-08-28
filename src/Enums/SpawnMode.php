<?php

namespace Splicewire\Beam\Calendars\Enums;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

/**
 * How each firing of a series produces the thing its Occurrence points at.
 *
 *  - {@see Generate}: each firing spawns a *distinct* net-new target (the heterogeneous default —
 *    a fresh daily piece).
 *  - {@see Reference}: each firing re-surfaces one fixed `target_ref` (the homogeneous degenerate
 *    case — the same evergreen item on a cadence).
 *
 * What "spawn a target" MEANS is not this package's business: the free tier records the firing and
 * emits an event, and an engine binding the SpawnDriver port decides the rest.
 */
enum SpawnMode: string implements ProvidesEnumLabel
{
    case Generate = 'generate';
    case Reference = 'reference';

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Generate a new item',
            self::Reference => 'Reference a fixed item',
        };
    }
}
