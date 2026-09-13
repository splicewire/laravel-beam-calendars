<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Min;
use Splicewire\Beam\Data\BeamData;

class ActionRevisionData extends BeamData
{
    public function __construct(
        #[MapName('expected_revision'), Min(1)]
        public int $expectedRevision,
    ) {}
}
