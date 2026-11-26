<?php

namespace Splicewire\Beam\Calendars\Config;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.calendars /resources` (nested in {@see BeamCalendarsSection}) — the route-group prefix and middleware
 * the mounted calendar/calendar-event/calendar-series particle resources inherit (the legacy `group_prefix`
 * mount that stays beside the `api_root` mount until ticket 16).
 */
class BeamCalendarsResourcesConfig extends Data
{
    public function __construct(
        #[Title('Group prefix'), Description('Route-group prefix for the mounted calendar particle resources. Empty inherits the prefix of the host route group that mounts them; set it to mount the surface under a fixed path.'), ConfigKey]
        public string $group_prefix,
        #[Title('Middleware'), Description('Middleware applied to the mounted resource routes. Empty inherits the middleware of the host route group that mounts them; list middleware names to add them.'), ConfigKey, ArrayItems('string')]
        public array $middleware,
    ) {
    }
}
