<?php

namespace Splicewire\Beam\Calendars\Tests\Fakes;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Splicewire\Beam\Calendars\Data\ActionResultData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;

class FailingLocalActionHandler extends LocalActionHandler
{
    public function execute(CalendarAction $action, CalendarActionAttempt $attempt, ConnectionInterface $connection): ActionResultData
    {
        parent::execute($action, $attempt, $connection);

        throw new RuntimeException('Synthetic local failure.');
    }
}
