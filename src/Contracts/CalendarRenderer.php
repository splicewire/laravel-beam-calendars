<?php

namespace Splicewire\Beam\Calendars\Contracts;

use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Projection\Horizon;

/**
 * One export format. Implementations are registered in
 * {@see \Splicewire\Beam\Calendars\Registries\RendererRegistry} under the format token they answer
 * to, which is also the token a caller asks for — so the advertised format list is derived from
 * the registry rather than declared next to it and allowed to drift.
 *
 * A renderer receives the calendar and a horizon and is handed the already-expanded projection; it
 * does not query, and it does not expand recurrence itself. Two renderers disagreeing about what
 * a series means is the failure this separation exists to prevent.
 */
interface CalendarRenderer
{
    /** The IANA media type this renderer produces — `text/calendar`, `application/rss+xml`, … */
    public function contentType(): string;

    /**
     * Render the projected occurrences to the format's body.
     *
     * @param  list<\Splicewire\Beam\Calendars\Recurrence\Occurrence>  $occurrences
     */
    public function render(Calendar $calendar, array $occurrences, Horizon $horizon): string;
}
