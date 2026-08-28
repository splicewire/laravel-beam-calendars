<?php

namespace Splicewire\Beam\Calendars\Data;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Data\BeamData;

/**
 * The window a read operation asks for. Both ends optional — an absent pair means the configured
 * default horizon, which is what makes "just show me the calendar" a valid request.
 */
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
class HorizonInputData extends BeamData
{
    public function __construct(
        public string|Optional $from = new Optional,
        public string|Optional $to = new Optional,
    ) {}

    /**
     * A partially-specified window resolves against the default rather than erroring: `from` alone
     * means "the default span starting there", `to` alone means "the default span ending there".
     * Refusing half a window would make the common case of a client that only knows one edge into
     * an error the client cannot fix.
     */
    public function toHorizon(): Horizon
    {
        $default = Horizon::default();

        if ($this->from instanceof Optional && $this->to instanceof Optional) {
            return $default;
        }

        $days = max(1, (int) config('beam.calendars.default_horizon_days', 90));

        if ($this->to instanceof Optional) {
            return Horizon::between($this->from, \Illuminate\Support\Carbon::parse($this->from)->addDays($days));
        }

        if ($this->from instanceof Optional) {
            return Horizon::between(\Illuminate\Support\Carbon::parse($this->to)->subDays($days), $this->to);
        }

        return Horizon::between($this->from, $this->to);
    }
}
