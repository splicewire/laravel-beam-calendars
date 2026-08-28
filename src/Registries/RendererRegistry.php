<?php

namespace Splicewire\Beam\Calendars\Registries;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Calendars\Contracts\CalendarRenderer;

/**
 * Export formats, keyed by the format token a caller asks for (`ics`, `rss`, …).
 *
 * This replaces a hardcoded `match ($format)` that listed the two renderers inline and threw on
 * anything else. The match was the reason a host could not add a `csv` export without editing the
 * package — the format vocabulary and the renderer set were the same literal, in one file, owned
 * by the tier least able to know what a host wants to export.
 *
 * `ConfigRegistry` again, for the same read-through reason as {@see EventKindRegistry}: an engine
 * or host registering a renderer after this package's provider has booted must simply be visible.
 */
#[IsRegistry(
    root: 'beam.calendars.renderers',
    of: 'calendar export renderers, one per format token',
    arity: RegistryArity::PickOne,
    entryType: 'class-string<'.CalendarRenderer::class.'>',
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'The format token is the registry key and the advertised export format are the same '
        .'string, so `exportFormats()` is derived from the registry rather than declared beside it.',
)]
class RendererRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.calendars.renderers';
    }

    /**
     * The formats this host can actually export — derived from the registry, never a second list
     * that could disagree with it.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return $this->store()->relativeKeys();
    }
}
