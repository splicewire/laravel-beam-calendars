<?php

namespace Splicewire\Beam\Calendars\Actions;

use RuntimeException;
use Splicewire\Beam\Calendars\Data\ActionResultData;

/** Internal savepoint rollback carrying a handler's non-applied result. */
class ActionNotApplied extends RuntimeException
{
    public function __construct(public ActionResultData $result)
    {
        parent::__construct('The action was not applied.');
    }
}
