<?php

namespace Splicewire\Beam\Calendars\Registries;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Data\Data;

/**
 * The registry of calendar EVENT KINDS — the vocabulary of what can sit on a date, and the typed
 * payload class each kind resolves to.
 *
 * Storage is `config('beam.calendars.event_kinds')`, a map of dotted kind key → class-string. This
 * package seeds the four generic kinds (`kind.event`, `kind.series`, `kind.ref`,
 * `kind.decommission`); `splicewire/tower` appends `kind.disclosure-ref` and `kind.run-circuit`
 * from its own provider; a host may append its own kind in its config file with no code here
 * changing.
 *
 * ## The property that made this shape win
 *
 * An unregistered kind is an ABSENT CONFIG KEY. There is no `class_exists` probe, no
 * `$app->bound()` check, and no package interrogating whether a sibling is installed — which
 * matters most in the direction that would otherwise be impossible, since the topology fence
 * forbids this package from naming tower at all. A registry miss IS the "unknown kind" condition,
 * so the write validator stops being a hardcoded list this package would have to keep in step with
 * packages it must not depend on.
 *
 * `ConfigRegistry` reads through to the repository on every read rather than snapshotting at
 * construction, which is load-bearing here: tower registers in `boot()`, after this package's own
 * provider has already run, and a snapshot would freeze its kinds out with no error anywhere.
 *
 * ## `PickOne`, and entries are class-strings
 *
 * One kind key resolves to one payload class. Entries stay class-strings rather than instances so
 * a kind may declare constructor dependencies and be resolved through the container at use.
 */
#[IsRegistry(
    root: 'beam.calendars.event_kinds',
    of: 'calendar event kinds — the dotted kind key and the typed payload class each resolves to',
    arity: RegistryArity::PickOne,
    entryType: 'class-string<'.Data::class.'>',
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'Keys are the dotted kind verbatim (`kind.event`, `kind.run-circuit`). A registry MISS is '
        .'the unknown-kind condition, which is why no participant needs a class_exists guard — and '
        .'why tower can contribute kinds this package is forbidden to name.',
)]
class EventKindRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.calendars.event_kinds';
    }

    /**
     * Every registered kind key, as whoever registered it spelled it — so an "unknown kind" error
     * can name what IS available rather than only what is not.
     *
     * @return list<string>
     */
    public function declaredKeys(): array
    {
        return $this->store()->relativeKeys();
    }
}
