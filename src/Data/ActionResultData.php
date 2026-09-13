<?php

namespace Splicewire\Beam\Calendars\Data;

use InvalidArgumentException;
use Splicewire\Beam\Data\BeamData;

class ActionResultData extends BeamData
{
    /**
     * @param  list<string>  $blockers
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $status,
        public array $blockers = [],
        public array $result = [],
    ) {
        if (! in_array($status, ['applied', 'blocked', 'failed'], true)) {
            throw new InvalidArgumentException('An action result must be applied, blocked, or failed.');
        }
    }
}
