<?php

namespace Splicewire\Beam\Calendars\Recurrence;

use Splicewire\Beam\Calendars\Data\SpawnData;

/**
 * One dated instance derived from a series — VIRTUAL until it materializes into a stored event
 * row. Never persisted as such: it is the "not resident" node the expander computes for a viewed
 * horizon and throws away again.
 *
 * `recurrenceId` is DERIVED from the occurrence's own date (RFC-5545 RECURRENCE-ID semantics) by
 * {@see SeriesExpander::recurrenceId()}, which is why the firing ledger's
 * `(series_id, recurrence_id)` unique key is deterministic and a firing can never double-run.
 * `seriesRef` back-points to the source series row.
 *
 * Every field is a SCALAR (or a plain value object). That is deliberate and load-bearing: this is
 * the object handed to a {@see \Splicewire\Beam\Calendars\Contracts\SpawnDriver}, whose contract
 * says it never touches the database — so there must be nothing here that can lazy-load a
 * relation and quietly make that false.
 */
class Occurrence
{
    public function __construct(
        public string $channel,
        public string $anchor,
        public string $recurrenceId,
        public string $seriesRef,
        public SpawnData $spawn,
        /** The calendar the source series belongs to; carried so a driver need not re-query it. */
        public ?string $calendarId = null,
    ) {}
}
