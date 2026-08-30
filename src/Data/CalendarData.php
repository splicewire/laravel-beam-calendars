<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Authorization\RowAuthorization;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The `calendars` particle resource — declarative read/write/hydrate.
 *
 * `scope` narrows to own ∪ reach-visible through the cascade policy the MODEL declares with
 * `#[UseCascadePolicy]`; there is no Policy class in this package for the gate to find, and that is
 * the design rather than an omission.
 *
 * A non-empty `label` makes this resource FRAMED — it projects into Frame's admin manifest as well
 * as the REST surface. Both transports run the same ParticleWriter pipeline
 * (validate → authorize → persist → emit), so the admin edit and the REST call cannot diverge.
 *
 * The list facets are declared HERE, on the properties, rather than in a query class: `filterable`
 * on the attribute is what makes data-filters generate the index query, and the generated
 * ResourceQuery needs no body at all unless a `baseQuery()` is genuinely required.
 *
 * ⚠️ `filterable: true` requires a data-filters REGISTRATION to actually produce that query —
 * `config/data-filters.php`, `#[ResourceFilter]` discovery, or `DataFilter::resource()`. The
 * attribute alone is a declaration of intent, not the wiring. Measured at the flagship
 * 2026-08-29: `GET /api/v1/calendars` is a live 500 for exactly this reason ("No data-filters
 * resource is registered under [calendars]"), and the same holds for the two sibling resources.
 *
 * ⚠️ Every facet here is `Exact::class`, declared explicitly. These three DTOs shipped with a
 * BARE `#[Filterable]`, which has never been legal — `Filterable::__construct()` has required the
 * operator class-string since the package's first commit. It went unnoticed because an attribute
 * is inert source text until something calls `newInstance()`, so the class loaded, the suite was
 * green, and every consumer that REFLECTS it died: the flagship's OpenAPI parameter-coverage test
 * and the JSON-Schema generator behind frame's edit form (3 of 44 particle resources could not
 * generate a schema at all, and all three were these). `Exact` rather than a wider operator
 * because `filter[…]` semantics are a published contract and nothing here declared a wider one —
 * every backing column but `title` is an indexed identifier/enum string, which is what exact
 * equality is for. Widening `title` to a `Partial`/`IlikeMatch` search is a deliberate contract
 * change and should have to edit `FilterFacetTest` to make it.
 */
#[ParticleResource(
    key: 'calendars',
    backing: Calendar::class,
    data: self::class,
    input: CalendarInputData::class,
    filterable: true,
    label: 'Calendars',
    singularLabel: 'Calendar',
    group: 'Calendars',
    icon: 'calendar-days',
    section: 'calendars',
)]
/**
 * ⚠️ The wire keys are DECLARED below, which is what makes the camelCase property spelling a
 * style choice rather than a silent contract change.
 *
 * Under the host's global `input => CamelCaseMapper` / `output => null`, an UNDECLARED DTO
 * publishes whatever the global mapper happens to produce. This package shipped with neither
 * axis declared, so its read side emitted `calendar_id` while its write side demanded
 * `calendarId` — read one key, write another, with nothing reporting it. `WireNameTest` now
 * asserts the published keys directly.
 */
#[TypeScript]
class CalendarData extends BeamData
{
    public function __construct(
        public string $id,
        #[Sortable(default: true)]
        #[Filterable(Exact::class)]
        public ?string $title,
        #[Filterable(Exact::class)]
        public ?string $slug,
        public string $timezone,
        #[Filterable(Exact::class)]
        public ?string $visibility,
        /** Derived, never a column — see the CalendarParticle trait. */
        #[MapName('event_count')]
        public int $eventCount,
        #[MapName('series_count')]
        public int $seriesCount,
    ) {}

    /**
     * Own ∪ reach-visible calendars, through the cascade the model's attribute declares.
     *
     * Rides {@see RowAuthorization} — the row plane of authorization as one named idiom
     * (registry-kernel 72). This site previously called `Gate::getPolicyFor($model)->scopeForUser(…)`
     * with no null check and so fataled at any host that binds no policy for the resolved model; the
     * idiom fails CLOSED there instead.
     */
    public static function scope(Builder $query): Builder
    {
        $model = config('beam.calendars.models.calendar', Calendar::class);

        return RowAuthorization::apply($query, $model);
    }

    public static function project(Calendar $calendar): self
    {
        $visibility = $calendar->visibility;

        return new self(
            id: (string) $calendar->getKey(),
            title: $calendar->title,
            slug: $calendar->slug,
            timezone: (string) ($calendar->timezone ?? 'UTC'),
            visibility: $visibility instanceof \BackedEnum ? $visibility->value : $visibility,
            eventCount: $calendar->events()->count(),
            seriesCount: $calendar->series()->count(),
        );
    }
}
