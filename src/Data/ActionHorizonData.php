<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Splicewire\Beam\Data\BeamData;

class ActionHorizonData extends BeamData
{
    public function __construct(
        #[DateFormat('Y-m-d')] public string $from,
        #[DateFormat('Y-m-d')] public string $through,
    ) {}
}
