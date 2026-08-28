<?php

namespace Splicewire\Beam\Calendars\Ops;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Splicewire\Beam\Calendars\Data\HorizonInputData;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Render\CalendarExporter;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * Export a calendar in a registered format — `ics`, `rss`, or whatever a host has registered.
 *
 * ⚠️ **This operation declares no `output:` Data class, and that is a DECLARED EXCEPTION rather
 * than an oversight.** The particle doctrine's test for the description-document exemption is the
 * MEDIA TYPE, not "it returns a document": this responds `text/calendar` or `application/rss+xml`,
 * never `application/json`, so there is no JSON shape for a DTO to describe and a declared one
 * would be a lie the generated client would then believe.
 *
 * The format list is not enumerated here either — {@see CalendarExporter} derives it from the
 * renderer registry, so a host adding a format does not have to edit this class to be able to ask
 * for it.
 */
#[ParticleOp(
    resource: 'calendars',
    name: 'export',
    kind: OperationKind::Read,
    model: Calendar::class,
    ability: 'view',
    input: HorizonInputData::class,
)]
class ExportCalendar
{
    public static function handle(Calendar $calendar, Request $request, mixed $actor = null): Response
    {
        $format = (string) $request->query('format', 'ics');
        $horizon = HorizonInputData::from($request->query())->toHorizon();

        $document = app(CalendarExporter::class)->export($calendar, $format, $horizon);

        return new Response($document['body'], 200, [
            'Content-Type' => $document['contentType'],
            // A subscribed feed is fetched by a client that will re-fetch it forever; naming the
            // file is what makes a one-off download land as something recognisable instead of as
            // the raw route segment.
            'Content-Disposition' => sprintf(
                'inline; filename="%s.%s"',
                $calendar->slug ?: 'calendar',
                $document['format'],
            ),
        ]);
    }
}
