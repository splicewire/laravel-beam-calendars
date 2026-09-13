<?php

namespace Splicewire\Beam\Calendars;

use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry;
use Splicewire\Beam\Calendars\Data\CalendarActionSeriesData;
use Splicewire\Beam\Calendars\Ops\CancelActionSeries;
use Splicewire\Beam\Calendars\Ops\PinActionOccurrence;
use Splicewire\Beam\Calendars\Ops\ProjectActionSeries;
use Splicewire\Beam\Calendars\Ops\ReplaceActionOccurrence;
use Splicewire\Beam\Calendars\Ops\ResumeActionSeries;
use Splicewire\Beam\Calendars\Ops\ScheduleActionSeries;
use Splicewire\Beam\Calendars\Ops\SkipActionOccurrence;
use Splicewire\Beam\Calendars\Query\CalendarResourceQuery;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;

class ActionSeriesResources
{
    public static function declare(): void
    {
        app(AttributedParticleDiscovery::class)->discover([
            CalendarActionSeriesData::class, ScheduleActionSeries::class, PinActionOccurrence::class, SkipActionOccurrence::class, ReplaceActionOccurrence::class, CancelActionSeries::class, ResumeActionSeries::class, ProjectActionSeries::class,
        ]);

        if (app()->bound(ResourceRegistry::class)) {
            $filters = app(ResourceRegistry::class);

            if (! $filters->has('calendar-action-series')) {
                $filters->registerDefinition(new ResourceDefinition(
                    key: 'calendar-action-series', data: CalendarActionSeriesData::class, query: CalendarResourceQuery::class,
                ));
            }
        }
    }

    /** Call inside the host's authenticated tenant route group, after binding ActionContextProvider. */
    public static function mount(string $uri = 'calendar-action-series'): void
    {
        self::declare();
        Particle::ops($uri, 'calendar-action-series', [ScheduleActionSeries::class, PinActionOccurrence::class, SkipActionOccurrence::class, ReplaceActionOccurrence::class, CancelActionSeries::class, ResumeActionSeries::class, ProjectActionSeries::class]);
        Particle::mount($uri, 'calendar-action-series')->only(['index', 'show'])->idConstraint('uuid');
    }
}
