<?php

namespace Splicewire\Beam\Calendars\Projection;

use Splicewire\Beam\Calendars\Recurrence\Occurrence;

/**
 * One entry in a projected calendar — a stored row OR a virtual instance a series implies, in a
 * single shape so a consumer can sort, render and export the merged list without branching.
 *
 * ⚠️ This is deliberately NOT {@see Occurrence}, and the two must not be merged. An `Occurrence`
 * is the pure, scalar-only object handed to a SpawnDriver, whose contract promises it never
 * touches the database; a `ProjectedEvent` is the READ shape and carries presentation facts
 * (`title`, `kind`, `virtual`) that a driver has no business seeing. Collapsing them would either
 * put display concerns in the driver contract or drop them from the read model.
 *
 * `id` is null for a virtual entry, and that null IS the write-target answer: there is nothing to
 * update until the instance is materialized. `virtual` states the same fact positively so a
 * consumer never has to infer intent from a missing id.
 */
class ProjectedEvent
{
    public function __construct(
        public ?string $id,
        public string $calendarId,
        public string $channel,
        public ?string $kind,
        public string $anchor,
        public ?string $title,
        public ?string $seriesRef,
        public ?string $recurrenceId,
        public bool $virtual,
        public ?string $status = null,
    ) {}

    /** The virtual half — an expanded occurrence that has no row behind it yet. */
    public static function virtual(Occurrence $occurrence, string $calendarId, ?string $title = null): self
    {
        return new self(
            id: null,
            calendarId: $calendarId,
            channel: $occurrence->channel,
            kind: 'kind.series',
            anchor: $occurrence->anchor,
            title: $title,
            seriesRef: $occurrence->seriesRef,
            recurrenceId: $occurrence->recurrenceId,
            virtual: true,
            // A virtual instance has no stored row, so it has no stored status. `upcoming` is the
            // only honest answer — it has not happened and nothing has recorded that it did.
            status: 'upcoming',
        );
    }
}
