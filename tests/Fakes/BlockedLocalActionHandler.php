<?php

namespace Splicewire\Beam\Calendars\Tests\Fakes;

use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Data\ActionResultData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;

class BlockedLocalActionHandler extends LocalActionHandler
{
    public function execute(CalendarAction $action, CalendarActionAttempt $attempt, ConnectionInterface $connection): ActionResultData
    {
        parent::execute($action, $attempt, $connection);

        return new ActionResultData('blocked', ['Approval is still required.']);
    }
}
