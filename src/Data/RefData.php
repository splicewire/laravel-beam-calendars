<?php

namespace Splicewire\Beam\Calendars\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Splicewire\Beam\Data\Data;

/**
 * A scheduled REFERENCE to something this package does not own.
 *
 * This is the generic collapse of what the engine tier spelled as three separate kinds —
 * `composition-ref`, `idea-ref` and the release cell — each of which named a paid-tier entity. The
 * distinction between them was never carried by the calendar: all three were "a date, a lane, and
 * a pointer", and the pointer's MEANING was resolved by the engine every time.
 *
 * So the free tier keeps the shape and drops the taxonomy. `target_type` is an opaque host-owned
 * discriminator (`composition`, `idea`, `song`, …) and `target_ref` an opaque id; this package
 * stores, projects and exports them and never dereferences either. A host that wants three
 * distinct kinds registers three keys in the kind registry pointing at this same class.
 */
#[Title('Scheduled reference')]
class RefData extends Data implements SchemaIdentity
{
    public function __construct(
        public string $kind,
        #[Title('Channel')]
        public string $channel,
        #[Title('Date')]
        public string $anchor,
        #[Title('Target type')]
        #[Description('Host-owned discriminator for what is being referenced. Opaque here.')]
        public ?string $target_type = null,
        #[Title('Target')]
        #[Description('The referenced item id. Opaque here — never dereferenced by this package.')]
        public ?string $target_ref = null,
        #[Title('Title')]
        #[Description('A cached human label for the referent, so a listing needs no per-row fetch.')]
        public ?string $title = null,
    ) {}

    public static function schemaName(): string
    {
        return 'calendar/ref';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
