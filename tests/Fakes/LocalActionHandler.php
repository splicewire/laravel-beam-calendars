<?php

namespace Splicewire\Beam\Calendars\Tests\Fakes;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Contracts\ActionHandler;
use Splicewire\Beam\Calendars\Data\ActionResultData;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;

class LocalActionHandler implements ActionHandler
{
    public function authorize(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): void
    {
        if ($context->principal !== 'editor:1') {
            throw new AuthorizationException('Editor authority is required.');
        }
    }

    public function prepare(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): CalendarActionData
    {
        $copy = clone $data;
        $copy->payload['prepared'] = true;

        return $copy;
    }

    public function execute(CalendarAction $action, CalendarActionAttempt $attempt, ConnectionInterface $connection): ActionResultData
    {
        $connection->table('users')->insert(['name' => 'Action completed']);

        return new ActionResultData('applied', result: ['attempt_id' => $attempt->id]);
    }
}
