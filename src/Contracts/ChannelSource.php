<?php

namespace Splicewire\Beam\Calendars\Contracts;

/**
 * Where the lane vocabulary comes from. The default
 * ({@see \Splicewire\Beam\Calendars\Registries\ChannelRegistry}) reads the package's config, which
 * is the right answer for a single-tenant host and for the package's own suite.
 *
 * A multi-tenant host binds this to resolve channels per tenant instead. That binding is the
 * reason the interface exists: calendar channel vocabulary currently leaks upward into
 * `splicewire/laravel-beam-tenancy`'s Tenant model (`$calendar`, `setCalendarChannels()`), and a
 * tenant-backed implementation of THIS port is what lets that leak have exactly one reader and
 * eventually be retired.
 */
interface ChannelSource
{
    /**
     * The lane ids, in display order. Must never be empty — a calendar with no channel is not a
     * degenerate calendar, it is an unusable one, so an implementation with nothing configured
     * returns the `default` seed rather than `[]`.
     *
     * @return list<string>
     */
    public function ids(): array;

    /**
     * The human label for a lane, or null if the lane is unknown. Null rather than a throw: a
     * calendar carrying a channel a host has since removed should still render, with the raw id.
     */
    public function label(string $id): ?string;

    /**
     * The lane's full declared metadata, or `[]` for an unknown lane.
     *
     * This exists because a host's channel vocabulary carries more than a label — ordering, a
     * colour, whatever a surface needs to render a lane header — and a port that exposed only the
     * label would force every consumer that wants the rest to go back to reading the host's storage
     * directly. That is precisely the second reader this port exists to prevent.
     *
     * The package itself reads nothing out of this beyond `label`; it is a pass-through so a
     * consumer can ask ONE thing for everything a lane declares.
     *
     * @return array<string, mixed>
     */
    public function meta(string $id): array;
}
