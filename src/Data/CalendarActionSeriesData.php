<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Operators\Exact;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/** Tenant scoping is enforced before projection; its internal token is never exposed here. */
#[ParticleResource(
    key: 'calendar-action-series',
    backing: CalendarActionSeries::class,
    data: CalendarActionSeriesData::class,
    input: false,
    readOnly: true,
    editable: false,
    deletable: false,
)]
class CalendarActionSeriesData extends BeamData
{
    /**
     * @param  list<ActionOccurrenceOverrideData>  $overrides
     * @param  list<string>  $blockers
     */
    public function __construct(
        public string $id,
        public int $revision,
        public string $status,
        public CalendarActionData $action,
        public RecurrenceRuleData $rule,
        public ?string $window,
        public array $overrides,
        public array $blockers,
        #[MapName('calendar_id'), Filterable(Exact::class, name: 'calendar_id')]
        public ?string $calendarId,
    ) {}

    public static function scope(Builder $query): Builder
    {
        if (! app()->bound(ActionContextProvider::class)) {
            return $query->whereRaw('1 = 0');
        }

        $context = app(ActionContextProvider::class)->current();

        return $query->where('tenant_token', $context->tenantToken)->where('principal', $context->principal);
    }

    public static function fromModel(CalendarActionSeries $action): self
    {
        return new self(
            id: $action->id, revision: $action->revision, status: $action->status,
            action: $action->actionData(), rule: RecurrenceRuleData::from($action->rule),
            window: $action->window,
            overrides: array_map(fn (array $entry): ActionOccurrenceOverrideData => ActionOccurrenceOverrideData::from($entry), $action->overrides),
            blockers: $action->blockers, calendarId: $action->calendar_id,
        );
    }
}
