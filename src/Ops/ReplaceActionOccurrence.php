<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionSeriesService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\CalendarActionRecordData;
use Splicewire\Beam\Calendars\Data\ReplaceActionOccurrenceData;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Authorization is deliberately delegated to ActionSeriesService and its concrete subject handler. */
#[ParticleOp(
    resource: 'calendar-action-series',
    name: 'replace',
    kind: OperationKind::Write,
    ability: false,
    input: ReplaceActionOccurrenceData::class,
    output: CalendarActionRecordData::class,
)]
class ReplaceActionOccurrence
{
    public static function handle(CalendarActionSeries $model, Request $request, mixed $actor): CalendarActionRecordData
    {
        $input = ReplaceActionOccurrenceData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionSeriesService::class)->replace($model->id, $input->expectedRevision, $input->recurrenceId, $input->action, $context, $model->getConnection());

        return CalendarActionRecordData::fromModel($result);
    }
}
