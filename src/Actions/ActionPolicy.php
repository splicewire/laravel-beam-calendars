<?php

namespace Splicewire\Beam\Calendars\Actions;

use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarAction;

/** Read access follows the host's authenticated principal and tenant, not request input. */
class ActionPolicy
{
    public function viewAny(mixed $user = null): bool
    {
        return app()->bound(ActionContextProvider::class);
    }

    public function view(mixed $user, CalendarAction $action): bool
    {
        if (! app()->bound(ActionContextProvider::class)) {
            return false;
        }

        $context = app(ActionContextProvider::class)->current();

        return $action->tenant_token === $context->tenantToken && $action->principal === $context->principal;
    }
}
