<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\ActionRevisionData;
use Splicewire\Beam\Calendars\Data\CalendarActionSeriesData;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Authorization is deliberately delegated to ActionSeriesService and its concrete subject handler. */
#[ParticleOp(
    resource: 'calendar-action-series',
    name: 'cancel',
    kind: OperationKind::Write,
    ability: false,
    input: ActionRevisionData::class,
    output: CalendarActionSeriesData::class,
)]
class CancelActionSeries
{
    public static function handle(CalendarActionSeries $model, Request $request, mixed $actor): CalendarActionSeriesData
    {
        $input = ActionRevisionData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionSeriesService::class)->cancel($model->id, $input->expectedRevision, $context, $model->getConnection());

        return CalendarActionSeriesData::fromModel($result);
    }
}
