<?php

namespace Splicewire\Beam\Calendars\Contracts;

use Splicewire\Beam\Calendars\Actions\ActionContext;

/** The host authenticates the caller and establishes its active tenant before returning this. */
interface ActionContextProvider
{
    public function current(): ActionContext;
}
