<?php

namespace Splicewire\Beam\Calendars\Data;

use Illuminate\Database\Eloquent\Builder;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Operators\Exact;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Calendars\Contracts\ActionContextProvider;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Localization\Presentation\MessagePresenters;
use Splicewire\Beam\Localization\Presentation\PresentedLine;
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

    /**
     * localization 37 (SCOUT-37 ruling 3): a blocked series' stored blocker sentence, in the reader's language. A series
     * reaches `blocked` in exactly one place, which stores exactly one fixed sentence, so the status PROVES the text and
     * calendars' `calendar-series` identity presenter renders it. Any other list passes through as stored. The shape
     * stays `list<string>` and nothing stored changes.
     *
     * @return list<string>
     */
    private static function presentBlockers(CalendarActionSeries $action): array
    {
        $stored = array_values((array) ($action->blockers ?? []));
        if ($stored === []) {
            return $stored;
        }
        $line = app(MessagePresenters::class)->present('calendar-series', ['status' => $action->status, 'subject_type' => $action->getMorphClass()], null, null);

        return $line->source === PresentedLine::IDENTITY && $line->text !== null ? [$line->text] : $stored;
    }

    public static function fromModel(CalendarActionSeries $action): self
    {
        return new self(
            id: $action->id, revision: $action->revision, status: $action->status,
            action: $action->actionData(), rule: RecurrenceRuleData::from($action->rule),
            window: $action->window,
            overrides: array_map(fn (array $entry): ActionOccurrenceOverrideData => ActionOccurrenceOverrideData::from($entry), $action->overrides),
            blockers: self::presentBlockers($action), calendarId: $action->calendar_id,
        );
    }
}
