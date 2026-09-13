<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Splicewire\Beam\Data\BeamData;

class RetryActionData extends BeamData
{
    public function __construct(
        #[MapName('expected_revision'), Min(1)]
        public int $expectedRevision,
        #[MapName('due_at')]
        #[Date]
        #[Regex('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/')]
        public string $dueAt,
        #[MapName('idempotency_key')]
        public string $idempotencyKey,
    ) {}
}
