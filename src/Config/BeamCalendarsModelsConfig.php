<?php

namespace Splicewire\Beam\Calendars\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.calendars /models` (nested in {@see BeamCalendarsSection}) — the model-binding seam. A host that
 * subclasses one of these Eloquent models points the config at its own class-string; the services and
 * resources resolve the concrete model through here rather than naming a class directly. A fixed set of
 * four bindings (not a host-extensible map): the keys are this package's model roles, so each is its own
 * declared key.
 */
class BeamCalendarsModelsConfig extends Data
{
    public function __construct(
        #[Title('Calendar model'), Description('Class-string of the Eloquent model for a calendar. A host subclasses `Models\Calendar` and points this at its own class to extend the model; services and resources resolve through here.'), ConfigKey]
        public string $calendar,
        #[Title('Event model'), Description('Class-string of the Eloquent model for a calendar event. Override to a `Models\CalendarEvent` subclass to extend the per-date event record.'), ConfigKey]
        public string $event,
        #[Title('Series model'), Description('Class-string of the Eloquent model for a calendar series (the recurrence rule that spawns events). Override to a `Models\CalendarSeries` subclass.'), ConfigKey]
        public string $series,
        #[Title('Firing model'), Description('Class-string of the Eloquent model for a series firing (the record that a due series produced an occurrence). Override to a `Models\CalendarFiring` subclass.'), ConfigKey]
        public string $firing,
    ) {
    }
}
