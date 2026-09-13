<?php

namespace Splicewire\Beam\Calendars\Actions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A stale revision or immutable action; transports should present this as a conflict. */
class ActionConflict extends ConflictHttpException {}
