<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Splicewire\Beam\Data\Data;

/**
 * The plain dated event — the generic kind, and the one a host reaches for when it wants a
 * calendar entry that stands on its own rather than pointing at something else.
 *
 * `title` is carried, not derived: a bare event has no referent to read a title from, which is
 * exactly what distinguishes it from {@see RefData}.
 */
#[Title('Event')]
class EventData extends Data implements SchemaIdentity
{
    public function __construct(
        public string $kind,
        #[Title('Channel')]
        #[Description('The delivery lane this event sits on.')]
        public string $channel,
        #[Title('Date')]
        #[Description('ISO date. Calendar entries are all-day, day-granular.')]
        public string $anchor,
        #[Title('Title')]
        public string $title,
        #[Title('Notes')]
        public ?string $body = null,
    ) {}

    public static function schemaName(): string
    {
        return 'calendar/event';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
