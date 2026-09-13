<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\ActionHorizonData;
use Splicewire\Beam\Calendars\Data\ActionOccurrenceListData;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Read-only expansion is scoped by the current tenant/principal resource policy. */
#[ParticleOp(
    resource: 'calendar-action-series',
    name: 'project',
    kind: OperationKind::Read,
    method: \Splicewire\Beam\Routing\HttpMethod::Get,
    ability: 'view',
    input: ActionHorizonData::class,
    output: ActionOccurrenceListData::class,
)]
class ProjectActionSeries
{
    public static function handle(CalendarActionSeries $model, Request $request, mixed $actor): ActionOccurrenceListData
    {
        $input = ActionHorizonData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionSeriesService::class)->project($model, $input->from, $input->through);

        return new ActionOccurrenceListData($result);
    }
}
