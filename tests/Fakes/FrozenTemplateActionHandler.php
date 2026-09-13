<?php

namespace Splicewire\Beam\Calendars\Tests\Fakes;

use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Data\CalendarActionData;

class FrozenTemplateActionHandler extends LocalActionHandler
{
    public function prepare(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): CalendarActionData
    {
        $data = parent::prepare($data, $context, $connection);
        $data->payload['definition_version'] = config('test.definition_version', 1);

        return $data;
    }
}
