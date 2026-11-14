<?php

namespace Splicewire\Beam\Calendars\Localization;

use Splicewire\Beam\Calendars\Actions\ActionConflict;

/** Remains catchable through the existing action-conflict contract. */
class CalendarActionConflict extends ActionConflict
{
    public function __construct(public readonly CalendarError $error, array $params = [])
    {
        parent::__construct($error->message($params));
    }
}
