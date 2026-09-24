<?php

namespace Splicewire\Beam\Calendars;

use Splicewire\Beam\Calendars\Data\CalendarActionRecordData;
use Splicewire\Beam\Calendars\Ops\CancelAction;
use Splicewire\Beam\Calendars\Ops\RescheduleAction;
use Splicewire\Beam\Calendars\Ops\RetryAction;
use Splicewire\Beam\Calendars\Ops\ScheduleAction;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

class ActionResources
{
    public static function declare(): void
    {
        app(AttributedParticleDiscovery::class)->discover([
            CalendarActionRecordData::class, ScheduleAction::class, RescheduleAction::class,
            CancelAction::class, RetryAction::class,
        ]);

    }

    /** Call inside the host's authenticated tenant route group, after binding ActionContextProvider. */
    public static function mount(string $uri = 'calendar-actions'): void
    {
        // Declared at boot by the provider; re-declaring at mount time only adds a supersession record.
        if (! app(ParticleResourceRegistry::class)->has('calendar-actions')) {
            self::declare();
        }
        Particle::ops($uri, 'calendar-actions', [ScheduleAction::class, RescheduleAction::class, CancelAction::class, RetryAction::class]);
        Particle::mount($uri, 'calendar-actions')->only(['index', 'show'])->idConstraint('uuid');
    }
}
