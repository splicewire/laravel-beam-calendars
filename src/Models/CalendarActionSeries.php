<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Facades\Beam;

class CalendarActionSeries extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['template' => 'array', 'rule' => 'array', 'overrides' => 'array', 'blockers' => 'array', 'revision' => 'integer'];
    }

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendar_action_series', 'calendar_action_series');
    }

    public function actionData(): CalendarActionData
    {
        return CalendarActionData::from($this->template);
    }

    public function context(): ActionContext
    {
        return new ActionContext($this->principal, $this->creator, $this->tenant_token);
    }
}
