<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Calendars\Projection\ProjectedEvent;
use Splicewire\Beam\Data\BeamData;

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
/**
 * ⚠️ The wire keys are DECLARED below, which is what makes the camelCase property spelling a
 * style choice rather than a silent contract change.
 *
 * Under the host's global `input => CamelCaseMapper` / `output => null`, an UNDECLARED DTO
 * publishes whatever the global mapper happens to produce. This package shipped with neither
 * axis declared, so its read side emitted `calendar_id` while its write side demanded
 * `calendarId` — read one key, write another, with nothing reporting it. `WireNameTest` now
 * asserts the published keys directly.
 */
class ProjectedEventData extends BeamData
{
    public function __construct(
        #[Description('The stored row id, or null for a virtual occurrence — there is no write target until it is materialized.')]
        public ?string $id,
        #[MapName('calendar_id')]
        public string $calendarId,
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
        #[MapName('series_ref')]
        public ?string $seriesRef,
        #[Description('The RFC-5545 RECURRENCE-ID date coordinate; null for a standalone event.')]
        #[MapName('recurrence_id')]
        public ?string $recurrenceId,
        #[Description('True for a computed, read-only occurrence; false for a stored row.')]
        public bool $virtual,
        #[Description('The stored row status; a virtual occurrence is always `upcoming`.')]
        public ?string $status,
    ) {}

    public static function fromProjection(ProjectedEvent $event): self
    {
        return new self(
            id: $event->id,
            calendarId: $event->calendarId,
            channel: $event->channel,
            kind: $event->kind,
            anchor: $event->anchor,
            title: $event->title,
            seriesRef: $event->seriesRef,
            recurrenceId: $event->recurrenceId,
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
