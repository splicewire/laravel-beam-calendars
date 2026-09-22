<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\CalendarActionAttemptData;
use Splicewire\Beam\Calendars\Data\RetryActionData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Authorization is deliberately delegated to ActionService and its concrete subject handler. */
#[ParticleOp(
    resource: 'calendar-actions',
    name: 'retry',
    kind: OperationKind::Write,
    ability: false,
    input: RetryActionData::class,
    output: CalendarActionAttemptData::class,
)]
class RetryAction
{
    public static function handle(CalendarAction $model, Request $request, mixed $actor): CalendarActionAttemptData
    {
        $input = RetryActionData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionService::class)->retry($model->id, $input->expectedRevision, $context, \Splicewire\Beam\Calendars\Actions\ActionInstant::parse($input->dueAt), $input->idempotencyKey, $model->getConnection());

        return CalendarActionAttemptData::fromModel($result);
    }
}
