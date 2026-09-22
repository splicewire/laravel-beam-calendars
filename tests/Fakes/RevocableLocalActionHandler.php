<?php

namespace Splicewire\Beam\Calendars\Tests\Fakes;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Data\CalendarActionData;

class RevocableLocalActionHandler extends LocalActionHandler
{
    public static bool $revoked = false;

    public static bool $denyReplacement = false;

    public function authorize(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): void
    {
        parent::authorize($data, $context, $connection);

        if (self::$revoked || (self::$denyReplacement && ($data->payload['message'] ?? null) === 'Replacement')) {
            throw new AuthorizationException('The concrete action subject is no longer authorized.');
        }
    }
}
