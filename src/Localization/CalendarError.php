<?php

namespace Splicewire\Beam\Calendars\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/** Language-independent identities for calendar refusals. */
enum CalendarError: string implements CodedError
{
    use IsCodedError;

    #[Message('Use an ISO instant with an explicit offset.')]
    case InstantOffset = 'calendar_instant_offset_required';

    #[Message('The scheduled instant is invalid.')]
    case InstantInvalid = 'calendar_instant_invalid';

    #[Message('Use an IANA timezone identifier.')]
    case TimezoneInvalid = 'calendar_timezone_invalid';

    #[Message('No execution handler is available for this action kind.')]
    case HandlerUnavailable = 'calendar_handler_unavailable';

    #[Message('This source origin is reserved for a server adapter.')]
    case OriginReserved = 'calendar_origin_reserved';

    #[Message('Provide a nonempty retry key of at most 255 characters.')]
    case RetryKeyInvalid = 'calendar_retry_key_invalid';

    #[Message('This series rule generates no occurrence on that date.')]
    case RuleOccurrenceMissing = 'calendar_rule_occurrence_missing';

    #[Message('Choose count or until, not both.')]
    case RuleLimitExclusive = 'calendar_rule_limit_exclusive';

    #[Message('That series does not belong to this calendar.')]
    case SeriesCalendarMismatch = 'calendar_series_calendar_mismatch';

    #[Message('This series generates no occurrence on [:date]. Pinning a date the rule does not produce would write an override that can never match.')]
    case OccurrenceMissing = 'calendar_occurrence_missing';

    #[Message('Unknown calendar event kind [:kind]. Registered: :registered.')]
    case KindUnknown = 'calendar_kind_unknown';

    #[Message('Unsupported export format [:format]. Available: :available.')]
    case ExportUnsupported = 'calendar_export_unsupported';

    #[Message('An action keeps its source origin when edited.')]
    case OriginImmutable = 'calendar_origin_immutable';

    #[Message('A recurring action keeps its source series, recurrence identity and origin when edited.')]
    case SeriesIdentityImmutable = 'calendar_series_identity_immutable';

    #[Message('The retry key was already used for a different request.')]
    case RetryKeyUsed = 'calendar_retry_key_used';

    #[Message('The action changed or is no longer editable. Reload its current outcome.')]
    case ActionChanged = 'calendar_action_changed';

    #[Message('This origin already identifies a different action intent. Edit the existing action explicitly.')]
    case OriginUsed = 'calendar_origin_used';

    #[Message('This occurrence is skipped. Its exclusion must remain explicit.')]
    case OccurrenceSkipped = 'calendar_occurrence_skipped';

    #[Message('The action series changed or is no longer editable. Reload it.')]
    case SeriesChanged = 'calendar_series_changed';

    public function validation(string $field, array $params = []): CodedValidationException
    {
        return CodedValidationException::for($field, $this, $params);
    }

    public function conflict(array $params = []): CalendarActionConflict
    {
        return new CalendarActionConflict($this, $params);
    }
}
