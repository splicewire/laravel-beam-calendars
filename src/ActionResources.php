<?php

namespace Splicewire\Beam\Calendars;

use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry;
use Splicewire\Beam\Calendars\Data\CalendarActionRecordData;
use Splicewire\Beam\Calendars\Ops\CancelAction;
use Splicewire\Beam\Calendars\Ops\RescheduleAction;
use Splicewire\Beam\Calendars\Ops\RetryAction;
use Splicewire\Beam\Calendars\Ops\ScheduleAction;
use Splicewire\Beam\Calendars\Query\CalendarResourceQuery;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;

class ActionResources
{
    public static function declare(): void
    {
        app(AttributedParticleDiscovery::class)->discover([
            CalendarActionRecordData::class, ScheduleAction::class, RescheduleAction::class,
            CancelAction::class, RetryAction::class,
        ]);

        if (app()->bound(ResourceRegistry::class)) {
            $filters = app(ResourceRegistry::class);

            if (! $filters->has('calendar-actions')) {
                $filters->registerDefinition(new ResourceDefinition(
                    key: 'calendar-actions', data: CalendarActionRecordData::class, query: CalendarResourceQuery::class,
                ));
            }
        }
    }

    /** Call inside the host's authenticated tenant route group, after binding ActionContextProvider. */
    public static function mount(string $uri = 'calendar-actions'): void
    {
        self::declare();
        Particle::ops($uri, 'calendar-actions', [ScheduleAction::class, RescheduleAction::class, CancelAction::class, RetryAction::class]);
        Particle::mount($uri, 'calendar-actions')->only(['index', 'show'])->idConstraint('uuid');
    }
}
