<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Splicewire\Beam\Calendars\Projection\ProjectedEvent;
use Splicewire\Beam\Data\Data;

/**
 * The wire shape of one projected calendar entry — a stored row, or a virtual instance a series
 * implies over the requested horizon.
 *
 * OWN-THE-WIRE: snake_case properties mirror the snake_case wire one-to-one, so a typed client maps
 * this with zero casing translation. This is the DTO the packaged calendar UI adapts into its own
 * event shape.
 *
 * ⚠️ No `#[TypeScript]` here, and adding one would not do what it looks like it does. The
 * transformer's `auto_discover_types` is rooted at the HOST's `app_path()`, so a package's Data
 * classes are invisible to a host-rooted scan whatever attributes they carry — a host that wants
 * these in its generated types names this package's path in its own codegen pipeline config.
 * Codegen is a host concern, and pretending otherwise here produces a DTO that "doesn't generate"
 * for reasons nobody can find.
 */
#[Description('One dated calendar entry: a stored event or a series\' virtual occurrence, with its write-target identity.')]
class ProjectedEventData extends Data
{
    public function __construct(
        #[Description('The stored row id, or null for a virtual occurrence — there is no write target until it is materialized.')]
        public ?string $id,
        public string $calendar_id,
        #[Example('default')]
        #[Description('The delivery lane this entry sits on.')]
        public string $channel,
        #[Example('kind.event')]
        public ?string $kind,
        #[Example('2026-07-15')]
        #[Description('ISO date. Calendar entries are all-day, day-granular.')]
        public string $anchor,
        public ?string $title,
        #[Description('Back-pointer to the source series row; null for a standalone event.')]
        public ?string $series_ref,
        #[Description('The RFC-5545 RECURRENCE-ID date coordinate; null for a standalone event.')]
        public ?string $recurrence_id,
        #[Description('True for a computed, read-only occurrence; false for a stored row.')]
        public bool $virtual,
        #[Description('The stored row status; a virtual occurrence is always `upcoming`.')]
        public ?string $status,
    ) {}

    public static function fromProjection(ProjectedEvent $event): self
    {
        return new self(
            id: $event->id,
            calendar_id: $event->calendarId,
            channel: $event->channel,
            kind: $event->kind,
            anchor: $event->anchor,
            title: $event->title,
            series_ref: $event->seriesRef,
            recurrence_id: $event->recurrenceId,
            virtual: $event->virtual,
            status: $event->status,
        );
    }

    /**
     * @param  list<ProjectedEvent>  $events
     * @return list<self>
     */
    public static function collect_(array $events): array
    {
        return array_map(static fn (ProjectedEvent $e): self => self::fromProjection($e), $events);
    }
}
