<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\ActionSeriesInputData;
use Splicewire\Beam\Calendars\Data\CalendarActionSeriesData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Authorization is deliberately delegated to ActionSeriesService and its concrete subject handler. */
#[ParticleOp(
    resource: 'calendar-action-series',
    name: 'schedule',
    subject: \Splicewire\Beam\Particle\Subject\NoSubject::class,
    kind: OperationKind::Write,
    ability: null,
    input: ActionSeriesInputData::class,
    output: CalendarActionSeriesData::class,
)]
class ScheduleActionSeries
{
    public static function handle(?object $model, Request $request, mixed $actor): CalendarActionSeriesData
    {
        $input = ActionSeriesInputData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionSeriesService::class)->schedule($input, $context);

        return CalendarActionSeriesData::fromModel($result);
    }
}
