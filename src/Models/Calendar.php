<?php

namespace Splicewire\Beam\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;
use Rushing\PermissionCascade\Concerns\HasMorphUser;
use Rushing\PermissionCascade\Concerns\HasVisibility;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Splicewire\Beam\Calendars\Concerns\CalendarParticle;
use Splicewire\Beam\Facades\Beam;

/**
 * A calendar — the container a set of dated events and recurrence series belong to.
 *
 * Composed on the substrate primitives rather than reimplementing them: permission-cascade
 * {@see HasVisibility} for share/publish through the cascade, {@see HasMorphUser} for
 * single-owner-via-morph-columns ownership.
 *
 * The attribute below declares this content model's cascade policy. `create: true` is self-service
 * calendar creation. Executable actions use their separate host-context read policy.
 */
#[UseCascadePolicy(BaseModelPolicy::class, create: true)]
class Calendar extends Model
{
    use CalendarParticle;
    use HasMorphUser;
    use HasUuids;
    use HasVisibility;

    protected $guarded = [];

    public function getTable(): string
    {
        return Beam::tableFor('beam.calendars.tables.calendars', 'calendars');
    }
}
