<?php

namespace Splicewire\Beam\Calendars\Contracts;

use Splicewire\Beam\Calendars\Recurrence\Occurrence;

/**
 * THE port. What a due occurrence actually DOES is the one thing this package refuses to know.
 *
 * Four invariants, each of which is why this is an interface rather than something easier:
 *
 * 1. **Engine-free by construction.** This names no composition, no circuit, no compliance
 *    schedule, no vendor. A calendar that fires is a complete free-tier feature; the meaning of
 *    firing is bought separately.
 *
 * 2. **A port, not an event.** Spawning is a synchronous capability WITH A RETURN — the scheduler
 *    needs to know what happened to resolve the firing row it already claimed. Fire-and-forget
 *    would make every failure invisible and the ledger a lie.
 *
 * 3. **The driver is PURE — it never touches the database.** It receives a fully-resolved
 *    {@see Occurrence} whose fields are scalars, and returns a description of what it did. The
 *    firing claim, the exactly-once guarantee and every write stay in
 *    {@see \Splicewire\Beam\Calendars\Scheduling\CalendarScheduler}, so the substrate keeps the
 *    part that is hard to get right and the engine supplies only the part it alone knows.
 *
 * 4. **Drivability is declared BY THE DRIVER**, not hardcoded in the substrate. A future spawn
 *    mode teaches its own driver to say yes, and nothing in this package changes. A driver that
 *    cannot handle an occurrence returns false and falls through, so a second driver could claim
 *    the rest.
 */
interface SpawnDriver
{
    /**
     * Can this driver handle the occurrence? Answer false rather than throwing — the scheduler
     * treats an unclaimed occurrence as "nothing to do", which is exactly the free-tier default.
     */
    public function canSpawn(Occurrence $occurrence): bool;

    /**
     * Do the thing. The return is recorded verbatim on the firing row's `result` column, so it
     * must be JSON-serialisable; it is evidence for an operator, not a value this package reads.
     *
     * Throwing is a legitimate outcome — the scheduler catches, marks the firing failed, and moves
     * to the next occurrence rather than aborting the sweep.
     *
     * @return array<string, mixed>
     */
    public function spawn(Occurrence $occurrence): array;
}
