<?php

namespace Splicewire\Beam\Calendars\Doctor;

use Splicewire\Beam\Calendars\BeamCalendarsServiceProvider;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;

/**
 * Reports this package's published migration copies that have drifted from the stubs they came
 * from. All of the logic is in beam-core's shared base; this class exists only to name the package
 * and its provider, which is the whole point of the base existing.
 *
 * Advisory, like almost every audit in the estate: whether a host's published copy SHOULD match the
 * current stub is a fact about that host — it may carry a deliberate local edit — and a check whose
 * answer depends on the host must not throw.
 */
class BeamCalendarsMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-calendars';
    }

    protected function serviceProviderClass(): string
    {
        return BeamCalendarsServiceProvider::class;
    }
}
