<?php

namespace Splicewire\Beam\Calendars\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Browser presentation and unknown diagnostics remain with the normal handler. */
final class CalendarErrorResponses
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        if ($exception instanceof CalendarActionConflict) {
            return new JsonResponse((new \Splicewire\Beam\Calendars\Data\CalendarErrorData(
                $exception->getMessage(), $exception->error->code(),
            ))->toArray(), $exception->getStatusCode(), $exception->getHeaders(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return null;
    }
}
