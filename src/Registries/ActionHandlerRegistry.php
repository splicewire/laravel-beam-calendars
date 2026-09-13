<?php

namespace Splicewire\Beam\Calendars\Registries;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Calendars\Contracts\ActionHandler;

#[IsRegistry(
    root: 'beam.calendars.action_handlers',
    entryType: 'class-string<'.ActionHandler::class.'>',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'Calendar action kinds and their local transactional execution handlers.',
)]
class ActionHandlerRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.calendars.action_handlers';
    }

    /** Kind names contain dots. ConfigRegistry writes the entire root array, so literal keys are safe. */
    protected function slotFor(RegistryKey $key): string
    {
        $root = $this->declaration()->rootKey()->segments();
        $segments = $key->segments();

        if (array_slice($segments, 0, count($root)) === $root) {
            $segments = array_slice($segments, count($root));
        }

        return implode('.', $segments);
    }

    public function handler(string $kind): ?ActionHandler
    {
        $class = $this->tryResolve($kind);

        if (! is_string($class) || ! is_a($class, ActionHandler::class, true)) {
            return null;
        }

        return app($class);
    }
}
