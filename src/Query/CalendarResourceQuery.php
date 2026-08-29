<?php

namespace Splicewire\Beam\Calendars\Query;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rushing\DataFilters\Query\ResourceQuery;

/**
 * The base query behind all three calendar data-filters resources.
 *
 * ONE class rather than three, because there is nothing per-resource to say: the filter, sort and
 * include surface is declared on the particle DTOs themselves (`#[Filterable]` / `#[Sortable]`), the
 * reflector reads it off `$this->definition->data`, and `#[Sortable(default: true)]` already supplies
 * the default sort on each of the three. What is left is the one thing a declaration cannot express,
 * and it is identical for all three.
 *
 * ⚠️ **That one thing is row-level authorization, and getting it wrong here is a read-exposure bug,
 * not a missing feature.** beam's `ParticleController::index` applies a resource's `scope` closure
 * **only on the non-filterable path** — its own comment says so: "Applied here (not for the filterable
 * path, whose data-filters query is its own gate)." So the moment these three resources became
 * `filterable: true`, `baseQuery()` became the sole read guard, and the inherited
 * `ResourceQuery::baseQuery()` — a bare `Model::query()` — would have listed every calendar in the
 * tenant to every authenticated caller. The scope is re-applied here deliberately; it is not
 * belt-and-braces.
 *
 * The scope is read off the DTO rather than restated, so the filterable list and the detail read are
 * gated by the same `Gate::getPolicyFor(...)->scopeForUser(...)` cascade the model's
 * `#[UseCascadePolicy]` declares, and cannot drift apart.
 */
class CalendarResourceQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        $query = ($this->definition->requireModel())::query();

        $data = $this->definition->data;

        return method_exists($data, 'scope')
            ? $data::scope($query) ?? $query
            : $query;
    }
}
