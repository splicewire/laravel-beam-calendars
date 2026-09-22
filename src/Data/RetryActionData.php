<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Splicewire\Beam\Data\BeamData;

class RetryActionData extends BeamData
{
    public function __construct(
        #[MapName('expected_revision'), Min(1)]
        #[Description('Revision read from the failed action; retry is refused if it has changed.')]
        public int $expectedRevision,
        #[MapName('due_at')]
        #[Date]
        #[Regex('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/')]
        #[Description('New execution time for the retry, expressed as an ISO8601 timestamp with an offset.')]
        public string $dueAt,
        #[MapName('idempotency_key')]
        #[Description('Caller key identifying this retry request so repeating it does not create another attempt.')]
        public string $idempotencyKey,
    ) {}
}
