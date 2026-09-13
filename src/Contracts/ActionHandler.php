<?php

namespace Splicewire\Beam\Calendars\Contracts;

use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Data\ActionResultData;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;

interface ActionHandler
{
    /** Check current caller authority without replacing the stored payload or pins. */
    public function authorize(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): void;

    /** Validate the declared payload Data and freeze server-derived facts on the same connection. */
    public function prepare(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): CalendarActionData;

    /**
     * Recheck stored principal, scope and facts before changing local state on THIS connection.
     * External effects must be written as an outbox intent; network calls are not atomic here.
     */
    public function execute(CalendarAction $action, CalendarActionAttempt $attempt, ConnectionInterface $connection): ActionResultData;
}
