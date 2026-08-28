<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Data\HorizonInputData;
use Splicewire\Beam\Calendars\Data\ProjectedEventData;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Projection\CalendarProjection;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * THE calendar read: everything on a calendar over a horizon, stored rows and virtual series
 * instances merged into one ordered list.
 *
 * Mounted at `GET calendars/{id}/op/project`. This is the operation that replaces the engine tier's
 * `GET compositions/{id}/calendar/events` — same answer, but asked of a calendar rather than of a
 * composition that happens to carry a calendar profile.
 *
 * `ability: 'view'` — reading a calendar is exactly the permission to read its contents, and the
 * cascade already decides who has it. The transport owns the deny shape: HTTP answers 403, and the
 * MCP projection simply omits the tool from `tools/list` rather than offering one that will fail.
 */
#[ParticleOp(
    resource: 'calendars',
    name: 'project',
    kind: OperationKind::Read,
    model: Calendar::class,
    ability: 'view',
    input: HorizonInputData::class,
    output: ProjectedEventData::class,
)]
class ProjectHorizon
{
    /**
     * @return list<ProjectedEventData>
     */
    public static function handle(Calendar $calendar, Request $request, mixed $actor = null): array
    {
        $horizon = HorizonInputData::from($request->query())->toHorizon();

        return ProjectedEventData::collect_(
            app(CalendarProjection::class)->events($calendar, $horizon),
        );
    }
}
