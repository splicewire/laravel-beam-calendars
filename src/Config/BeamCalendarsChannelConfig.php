<?php

namespace Splicewire\Beam\Calendars\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * One entry of the host-extensible `beam.calendars.channels` map — a delivery lane's display metadata.
 * The map key is the lane id (`default`, and whatever a host adds); this is the value shape
 * (`array{label: string}`) that {@see BeamCalendarsSection::$channels} descends into via `#[MapValues(self::class)]`,
 * so a populated host lane is covered the same as the shipped `default`.
 */
class BeamCalendarsChannelConfig extends Data
{
    public function __construct(
        #[Title('Label'), Description('Human-readable name for the lane, shown where a channel is presented. The lane id is the map key; this is its display label.'), ConfigKey]
        public string $label,
    ) {
    }
}
