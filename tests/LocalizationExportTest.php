<?php

namespace Splicewire\Beam\Calendars\Tests;

use Splicewire\Beam\Calendars\Models\Calendar;
use Splicewire\Beam\Calendars\Projection\Horizon;
use Splicewire\Beam\Calendars\Projection\ProjectedEvent;
use Splicewire\Beam\Calendars\Render\IcsRenderer;
use Splicewire\Beam\Calendars\Render\RssRenderer;
use Splicewire\Beam\Localization\PseudoLocale;

/** Identical observers at original source and candidate; only native generated feed stamps normalize. */
class LocalizationExportTest extends TestCase
{
    public function test_export_english_complete_bytes(): void
    {
        $this->app->setLocale('en');
        foreach (['ics', 'rss'] as $format) {
            $expected = $this->fixture($format);
            $this->assertSame($expected, $this->render($format));
            $authored = str_replace(
                $format === 'ics' ? 'X-WR-CALNAME:Calendar' : ['<title>Calendar</title>', '<description>Calendar</description>'],
                $format === 'ics' ? 'X-WR-CALNAME:Authored Calendar' : ['<title>Authored Calendar</title>', '<description>Authored Calendar</description>'],
                $expected,
            );
            $this->assertSame($authored, $this->render($format, 'Authored Calendar'));
        }
    }

    public function test_export_ics_pseudo_fallbacks(): void
    {
        $this->assertPseudoFeed('ics');
    }

    public function test_export_rss_pseudo_fallbacks(): void
    {
        $this->assertPseudoFeed('rss');
    }

    private function assertPseudoFeed(string $format): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale('en-XA');
        $actual = $this->render($format);
        $pattern = $format === 'ics' ? '/^X-WR-CALNAME:(.*)\r$/m' : '/<title>([^<]*)<\/title>/';
        $this->assertSame(1, preg_match($pattern, $actual, $match));
        $this->assertTrue(PseudoLocale::isMarked($match[1]), $format.' real renderer calendar fallback must be pseudo localized');
        $calendar = __('beam-calendars::messages.export_calendar');
        $event = __('beam-calendars::messages.export_event');
        $expected = str_replace(
            $format === 'ics' ? ['X-WR-CALNAME:Calendar', 'SUMMARY:Event'] : ['<title>Calendar</title>', '<description>Calendar</description>'],
            $format === 'ics' ? ['X-WR-CALNAME:'.$calendar, 'SUMMARY:'.$event] : ['<title>'.$calendar.'</title>', '<description>'.$calendar.'</description>'],
            $this->fixture($format),
        );
        $this->assertSame($expected, $actual);
        $authored = str_replace(
            $format === 'ics' ? 'X-WR-CALNAME:'.$calendar : ['<title>'.$calendar.'</title>', '<description>'.$calendar.'</description>'],
            $format === 'ics' ? 'X-WR-CALNAME:Authored Calendar' : ['<title>Authored Calendar</title>', '<description>Authored Calendar</description>'],
            $expected,
        );
        $this->assertSame($authored, $this->render($format, 'Authored Calendar'));
    }

    private function fixture(string $format): string
    {
        return file_get_contents(__DIR__.'/Fixtures/localization-export/fallback.'.$format);
    }

    private function render(string $format, ?string $title = null): string
    {
        $calendar = new Calendar;
        $calendar->forceFill(['id' => 'calendar-authored-id', 'title' => $title]);
        $events = [
            new ProjectedEvent('null-kind', 'calendar-authored-id', 'authored-channel', null, '2026-01-06', null, null, null, false),
            new ProjectedEvent('empty-kind', 'calendar-authored-id', 'authored-channel', 'kind.', '2026-01-07', null, null, null, false),
            new ProjectedEvent('authored-kind', 'calendar-authored-id', 'authored-channel', 'kind.AuthoredKind', '2026-01-08', null, null, null, false),
            new ProjectedEvent('authored-title', 'calendar-authored-id', 'authored-channel', 'kind.event', '2026-01-09', 'Authored, title; literal', null, null, false),
        ];
        $renderer = $format === 'ics' ? new IcsRenderer : new RssRenderer;
        $body = $renderer->render($calendar, $events, Horizon::between('2026-01-01', '2026-01-31'));
        $pattern = $format === 'ics' ? '/^DTSTAMP:\d{8}T\d{6}Z\r$/m' : '/<lastBuildDate>[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT<\/lastBuildDate>/';
        $replacement = $format === 'ics' ? "DTSTAMP:<GENERATED>\r" : '<lastBuildDate><GENERATED></lastBuildDate>';
        $normalized = preg_replace($pattern, $replacement, $body, -1, $count);
        $this->assertSame($format === 'ics' ? 4 : 1, $count, 'Only native generated feed timestamps may normalize');

        return $normalized;
    }
}
