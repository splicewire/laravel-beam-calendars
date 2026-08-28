<?php

namespace Splicewire\Beam\Calendars\Write;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Registries\EventKindRegistry;

/**
 * Validates that a written `(kind, payload)` pair names a registered kind and satisfies that kind's
 * typed shape.
 *
 * ⚠️ This THROWS, and by this estate's own rule that is only legitimate because the question is one
 * the declaration's author could have answered without knowing the host: "is this payload shaped
 * like the kind it claims to be" is grammar. Contrast the question this deliberately does NOT ask —
 * "is this kind one that a *particular* host has registered" — which is a fact about the host, and
 * so a doctor finding rather than a fatal.
 *
 * Except that here the two collapse, and it is worth being precise about why: an unregistered kind
 * cannot be validated at all, since the registry is what supplies the shape to validate against.
 * Storing an unvalidatable payload would defer the failure to read time, where it surfaces far from
 * the write that caused it. So an unknown kind is refused ON WRITE — and the message names every
 * kind that IS registered, so a caller can see whether the engine that provides theirs is installed.
 */
class KindValidator
{
    public function __construct(private EventKindRegistry $kinds) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the payload, normalised through its typed class
     */
    public function validate(string $kind, array $payload): array
    {
        $class = $this->kinds->tryResolve($kind);

        if (! is_string($class) || ! class_exists($class)) {
            throw ValidationException::withMessages([
                'kind' => sprintf(
                    'Unknown calendar event kind [%s]. Registered: %s.',
                    $kind,
                    implode(', ', $this->kinds->declaredKeys()) ?: '(none)',
                ),
            ]);
        }

        // Hydrating through the declared class is the validation: laravel-data rejects a payload
        // that cannot satisfy the shape, and the round trip normalises it (enums cast, Optionals
        // dropped) so what lands in the column is canonical rather than whatever the caller sent.
        return $class::from($payload + ['kind' => $kind])->toArray();
    }
}
