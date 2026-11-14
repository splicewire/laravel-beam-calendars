<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** An immediate calendar conflict with its additive language-independent identity. */
#[TypeScript]
class CalendarErrorData extends BeamData
{
    public function __construct(
        #[Description('The original human-readable calendar refusal in the request language.')]
        public string $message,
        #[Description('The stable language-independent calendar refusal code.')]
        public string $code,
    ) {}
}
