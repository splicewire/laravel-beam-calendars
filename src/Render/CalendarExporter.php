<?php

namespace Splicewire\Beam\Calendars\Render;

use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Contracts\CalendarRenderer;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Projection\CalendarProjection;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Calendars\Registries\RendererRegistry;

/**
 * Project, then render — the one path every export takes.
 *
 * This replaces a hardcoded `match ($format)` that named both renderers inline and threw on
 * anything else. That match meant the set of exportable formats and the set of shipped renderers
 * were the same literal in one file, owned by the tier least able to know what a host wants to
 * export; adding a CSV export meant editing the package. Now both derive from
 * {@see RendererRegistry}, so `formats()` cannot drift from what is actually renderable.
 */
class CalendarExporter
{
    public function __construct(
        private RendererRegistry $renderers,
        private Container $container,
        private CalendarProjection $projection = new CalendarProjection,
    ) {}

    /** The formats this host can export — read from the registry, never a second list beside it. */
    public function formats(): array
    {
        return $this->renderers->formats();
    }

    /**
     * @return array{body: string, contentType: string, format: string}
     */
    public function export(Calendar $calendar, string $format, ?Horizon $horizon = null): array
    {
        $horizon ??= Horizon::default();
        $renderer = $this->rendererFor($format);

        return [
            'body' => $renderer->render($calendar, $this->projection->events($calendar, $horizon), $horizon),
            'contentType' => $renderer->contentType(),
            'format' => $format,
        ];
    }

    private function rendererFor(string $format): CalendarRenderer
    {
        $class = $this->renderers->tryResolve($format);

        if (! is_string($class) || ! class_exists($class)) {
            // A ValidationException rather than a 500: an unsupported format is a bad REQUEST, and
            // the message names what IS available so the caller can correct it in one round trip.
            throw ValidationException::withMessages([
                'format' => sprintf(
                    'Unsupported export format [%s]. Available: %s.',
                    $format,
                    implode('|', $this->formats()) ?: '(none registered)',
                ),
            ]);
        }

        $renderer = $this->container->make($class);

        if (! $renderer instanceof CalendarRenderer) {
            throw new \RuntimeException(sprintf(
                'Renderer registered for [%s] is %s, which does not implement %s.',
                $format, $class, CalendarRenderer::class,
            ));
        }

        return $renderer;
    }
}
