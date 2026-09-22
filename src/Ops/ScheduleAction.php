<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Data\CalendarActionRecordData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/** Authorization is deliberately delegated to ActionService and its concrete subject handler. */
#[ParticleOp(
    resource: 'calendar-actions',
    name: 'schedule',
    subject: \Splicewire\Beam\Particle\Subject\NoSubject::class,
    kind: OperationKind::Write,
    ability: false,
    input: CalendarActionData::class,
    output: CalendarActionRecordData::class,
)]
class ScheduleAction
{
    public static function handle(?object $model, Request $request, mixed $actor): CalendarActionRecordData
    {
        $input = CalendarActionData::from($request->all());
        $context = app(ActionContextProvider::class)->current();
        $result = app(ActionService::class)->schedule($input, $context);

        return CalendarActionRecordData::fromModel($result);
    }
}
