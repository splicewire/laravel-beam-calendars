<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\ActionRevisionData;
use Splicewire\Beam\Calendars\Data\CalendarActionRecordData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Authorization is deliberately delegated to ActionService and its concrete subject handler. */
#[ParticleOp(
    resource: 'calendar-actions',
    name: 'cancel',
    kind: OperationKind::Write,
    ability: false,
    input: ActionRevisionData::class,
    output: CalendarActionRecordData::class,
)]
class CancelAction
{
    public static function handle(CalendarAction $model, Request $request, mixed $actor): CalendarActionRecordData
    {
        $input = ActionRevisionData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionService::class)->cancel($model->id, $input->expectedRevision, $context, $model->getConnection());

        return CalendarActionRecordData::fromModel($result);
    }
}
