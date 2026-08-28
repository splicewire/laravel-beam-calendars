<?php

namespace Splicewire\Beam\Calendars\Render;

use DateTimeImmutable;
use Splicewire\Beam\Calendars\Contracts\CalendarRenderer;
use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Calendars\Projection\ProjectedEvent;

/**
 * The same projection as an RSS 2.0 feed — the "what is coming up" reading of a calendar, for
 * consumers that subscribe to content rather than to time.
 *
 * Ordered newest-first, which is the opposite of the ICS document's chronological order and is
 * correct for a feed: a reader wants the most recent item at the top.
 */
class RssRenderer implements CalendarRenderer
{
    public function contentType(): string
    {
        return 'application/rss+xml';
    }

    /** @param  list<ProjectedEvent>  $occurrences */
    public function render(Calendar $calendar, array $occurrences, Horizon $horizon): string
    {
        $items = array_reverse($occurrences);

        $body = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0">',
            '  <channel>',
            '    <title>'.$this->escape((string) ($calendar->title ?? 'Calendar')).'</title>',
            '    <description>'.$this->escape((string) ($calendar->title ?? 'Calendar')).'</description>',
            '    <lastBuildDate>'.gmdate('D, d M Y H:i:s \G\M\T').'</lastBuildDate>',
        ];

        foreach ($items as $event) {
            $body = [...$body, ...$this->item($calendar, $event)];
        }

        $body[] = '  </channel>';
        $body[] = '</rss>';

        return implode("\n", $body)."\n";
    }

    /** @return list<string> */
    private function item(Calendar $calendar, ProjectedEvent $event): array
    {
        $date = $this->date($event->anchor);

        return [
            '    <item>',
            '      <title>'.$this->escape($event->title ?? $event->anchor).'</title>',
            // A GUID must be stable and unique. `isPermaLink="false"` says explicitly that it is an
            // identifier and not a URL — without it a reader may try to fetch it and treat the
            // failure as a broken item.
            '      <guid isPermaLink="false">'.$this->escape($this->guid($calendar, $event)).'</guid>',
            $date !== null ? '      <pubDate>'.$date->format('D, d M Y').' 00:00:00 GMT</pubDate>' : '',
            '      <category>'.$this->escape($event->channel).'</category>',
            '    </item>',
        ];
    }

    private function guid(Calendar $calendar, ProjectedEvent $event): string
    {
        $local = $event->seriesRef !== null && $event->recurrenceId !== null
            ? $event->seriesRef.'-'.$event->recurrenceId
            : (string) ($event->id ?? $event->anchor.'-'.$event->channel);

        return 'urn:beam-calendar:'.$calendar->getKey().':'.$local;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
