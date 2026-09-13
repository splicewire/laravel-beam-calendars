<?php

namespace Splicewire\Beam\Calendars\Actions;

/** Trusted host-derived identity. Never hydrate this from a request body. */
class ActionContext
{
    public function __construct(
        public string $principal,
        public string $creator,
        public string $tenantToken,
    ) {}
}
