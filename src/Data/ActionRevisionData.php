<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Min;
use Splicewire\Beam\Data\BeamData;

class ActionRevisionData extends BeamData
{
    public function __construct(
        #[MapName('expected_revision'), Min(1)]
        #[Description('Revision read from the action; the operation is refused if the action has changed.')]
        public int $expectedRevision,
    ) {}
}
