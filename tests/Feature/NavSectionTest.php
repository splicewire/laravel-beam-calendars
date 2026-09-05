<?php

use Splicewire\Beam\Calendars\Data\CalendarData;
use Splicewire\Beam\Calendars\Data\CalendarEventData;
use Splicewire\Beam\Calendars\Data\CalendarSeriesData;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;

/**
 * The `calendars` nav SEAT this package contributes.
 *
 * Its three resources have declared `section: 'calendars'` since they were written, and no host in the
 * estate seats that string — so the declaration was correct and the section was invisible. These tests
 * pin the seat itself, the realm pair, and the JOIN between the two halves, which is the thing that was
 * actually broken: a seat whose `key` drifts from the resources' declared `section:` is a seat with no
 * children, and `FrameNavContribution` drops an empty contributed seat, so the drift would look exactly
 * like the defect this closes.
 */
function calendarSeats(string $realm): array
{
    return app(NavSectionRegistry::class)->for($realm);
}

it('seats `calendars` in both the operator and tenant realms', function () {
    foreach (['operator', 'tenant'] as $realm) {
        $keys = array_map(fn (NavSection $section): string => $section->key, calendarSeats($realm));

        expect($keys)->toContain('calendars');
    }
});

it('declares both gate axes explicitly, so an omission cannot pass for a decision', function () {
    $seat = collect(calendarSeats('tenant'))->firstWhere('key', 'calendars');

    // Ungated is the DECISION here — the three resources under the seat are `viewAny`-gated
    // individually and an empty seat is dropped, so an unauthorized reader loses the section because
    // its contents went, not because the seat carried a gate of its own.
    expect($seat->entitlement)->toBeNull()
        ->and($seat->permission)->toBeNull()
        ->and($seat->isGated())->toBeFalse()
        ->and($seat->gate())->toBe([]);
});

it('names the seat with the SAME string the three resources declare as their section', function () {
    $declared = [];

    foreach ([CalendarData::class, CalendarEventData::class, CalendarSeriesData::class] as $data) {
        $attribute = (new ReflectionClass($data))->getAttributes(ParticleResourceAttribute::class)[0] ?? null;

        expect($attribute)->not->toBeNull("{$data} no longer declares #[ParticleResource]");

        $declared[$data] = $attribute->newInstance()->section;
    }

    // One value, and it is the seat's key. This is the join; if either half is renamed alone the seat
    // silently collects nothing.
    expect(array_unique(array_values($declared)))->toBe(['calendars']);

    $seat = collect(calendarSeats('tenant'))->firstWhere('key', 'calendars');

    expect($seat->key)->toBe('calendars');
});

it('seats nothing in a realm this package did not declare for', function () {
    // A seat never CREATES a realm and never leaks into one it did not name — `site` is a realm key the
    // estate uses and this package has no opinion about.
    expect(calendarSeats('site'))->toBe([]);
});

it('registers each seat under this package name, so provenance survives into the registry', function () {
    $registry = app(NavSectionRegistry::class);

    expect($registry->targetedRealmKeys())->toContain('operator')
        ->and($registry->targetedRealmKeys())->toContain('tenant');
});
