<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Actions\ActionConflict;
use Splicewire\Beam\Calendars\Localization\CalendarError;
use Splicewire\Beam\Calendars\Localization\CalendarErrorResponses;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\TextNamespaces;

it('marks every calendar refusal at a JSON boundary with unchanged stable codes and replaced parameters', function () {
    config(['app.debug' => false, 'beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    $params = ['date' => '2026-01-05', 'kind' => 'kind.unknown', 'registered' => 'kind.event', 'format' => 'missing', 'available' => 'ics|rss'];
    $conflicts = ['OriginImmutable', 'SeriesIdentityImmutable', 'RetryKeyUsed', 'ActionChanged', 'OriginUsed', 'OccurrenceSkipped', 'SeriesChanged'];
    foreach (CalendarError::cases() as $case) {
        $conflict = in_array($case->name, $conflicts, true);
        Route::get('/loc25/cases/'.$case->value, fn () => throw ($conflict ? $case->conflict($params) : $case->validation('input', $params)));
        $response = $this->getJson('/loc25/cases/'.$case->value)->assertStatus($conflict ? 409 : 422);
        expect($response->json($conflict ? 'code' : 'codes.input.0'))->toBe($case->value);
        expect(PseudoLocale::isMarked($response->json('message')))->toBeTrue($case->value);
        expect($response->json('message'))->not->toMatch('/:(date|kind|registered|format|available)\b/');
        if (! $conflict) {
            expect(PseudoLocale::isMarked($response->json('errors.input.0')))->toBeTrue($case->value);
        }
    }
});

it('preserves parent exception contracts and leaves browser handling and unknown diagnostics alone', function () {
    $validation = CalendarError::TimezoneInvalid->validation('timezone');
    expect($validation)->toBeInstanceOf(ValidationException::class)->toBeInstanceOf(CodedValidationException::class);
    expect($validation->errors())->toBe(['timezone' => ['Use an IANA timezone identifier.']]);
    $conflict = CalendarError::ActionChanged->conflict();
    expect($conflict)->toBeInstanceOf(ActionConflict::class)->toBeInstanceOf(Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    expect($conflict->getStatusCode())->toBe(409);
    $jsonProof = Request::create('/calendar', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $rendered = CalendarErrorResponses::render($conflict, $jsonProof);
    expect($rendered->getEncodingOptions())->toBe(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    expect($rendered->headers->all())->toBe((new Illuminate\Http\JsonResponse([], 409, $conflict->getHeaders()))->headers->all());
    $browser = Request::create('/calendar');
    expect(CalendarErrorResponses::render($conflict, $browser))->toBeNull();
    expect($validation->render($browser))->toBeNull();
    $json = Request::create('/calendar', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    expect(CalendarErrorResponses::render(new RuntimeException('Raw downstream diagnostic'), $json))->toBeNull();
    expect(CalendarErrorResponses::render(new ActionConflict('Host-authored conflict'), $json))->toBeNull();
});

it('uses the owned beam-calendars namespace and root language directory', function () {
    $owner = app(TextNamespaces::class)->ownerOfClass(CalendarError::class);
    expect($owner->keyPrefix())->toBe('beam-calendars::');
    foreach (CalendarError::cases() as $case) {
        expect($case->translationKey())->toStartWith('beam-calendars::enums.');
    }
    expect(__('beam-calendars::messages.generate'))->toBe('Generate a new item');
});
