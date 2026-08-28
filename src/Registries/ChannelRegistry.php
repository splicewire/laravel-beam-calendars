<?php

namespace Splicewire\Beam\Calendars\Registries;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Calendars\Contracts\ChannelSource;

/**
 * The lane vocabulary — the delivery channels a calendar's events are filed under, and the
 * default {@see ChannelSource}.
 *
 * The `default` seed is load-bearing rather than cosmetic: a calendar with exactly one channel is
 * a single-lane DOCUMENT (collapse-invariant, one column, one ICS feed), and a calendar with two
 * is a multi-lane TIMELINE. Falling back to one seeded lane rather than to zero is what keeps the
 * single-channel case a real calendar instead of a degenerate one.
 *
 * A multi-tenant host does not extend this — it binds {@see ChannelSource} to something
 * tenant-aware and leaves the registry as the fallback.
 */
#[IsRegistry(
    root: 'beam.calendars.channels',
    of: 'calendar delivery channels (lanes) — the lane id and its display metadata',
    arity: RegistryArity::PickOne,
    entryType: 'array{label: string}',
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'The `default` lane is a SEED, not a reserved name — a host may supersede it. An empty '
        .'registry still answers `[default]`, because one lane is a document and zero is unusable.',
)]
class ChannelRegistry extends ConfigRegistry implements ChannelSource
{
    /** The lane a calendar collapses to when nothing is configured. */
    public const DEFAULT_LANE = 'default';

    protected function configKey(): string
    {
        return 'beam.calendars.channels';
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = $this->store()->relativeKeys();

        return $ids === [] ? [self::DEFAULT_LANE] : array_values($ids);
    }

    public function label(string $id): ?string
    {
        // A lane a host has since removed still renders, with its raw id — see ChannelSource.
        return $this->meta($id)['label'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(string $id): array
    {
        $entry = $this->tryResolve($id);

        return is_array($entry) ? $entry : [];
    }
}
