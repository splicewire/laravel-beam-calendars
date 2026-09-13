<?php

namespace Splicewire\Beam\Calendars\Actions;

use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;

/** Read access follows the host's authenticated principal and tenant, not request input. */
class ActionSeriesPolicy
{
    public function viewAny(mixed $user = null): bool
    {
        return app()->bound(ActionContextProvider::class);
    }

    public function view(mixed $user, CalendarActionSeries $action): bool
    {
        if (! app()->bound(ActionContextProvider::class)) {
            return false;
        }

        $context = app(ActionContextProvider::class)->current();

        return $action->tenant_token === $context->tenantToken && $action->principal === $context->principal;
    }
}
