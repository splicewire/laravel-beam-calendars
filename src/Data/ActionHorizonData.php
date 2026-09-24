<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Splicewire\Beam\Data\BeamData;

class ActionHorizonData extends BeamData
{
    public function __construct(
        #[Description('First date (Y-m-d) of the projection window.')]
        #[DateFormat('Y-m-d')] public string $from,
        #[Description('Last date (Y-m-d), inclusive, of the projection window.')]
        #[DateFormat('Y-m-d')] public string $through,
    ) {}
}
