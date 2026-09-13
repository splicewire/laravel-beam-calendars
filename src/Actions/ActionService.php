<?php

namespace Splicewire\Beam\Calendars\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry;

class ActionService
{
    public function __construct(private ActionHandlerRegistry $handlers) {}

    public function schedule(CalendarActionData $data, ActionContext $context, ?ConnectionInterface $connection = null): CalendarAction
    {
        $connection = $this->connection($connection);
        $intentHash = $this->intentHash($data, $context);

        $schedule = function () use ($data, $context, $connection, $intentHash): CalendarAction {
            $handler = $this->handlers->handler($data->kind)
                ?? throw ValidationException::withMessages(['kind' => 'No execution handler is available for this action kind.']);
            $handler->authorize($data, $context, $connection);

            if ($data->origin !== null) {
                $existing = CalendarAction::on($connection->getName())->where('tenant_token', $context->tenantToken)
                    ->where('origin', $data->origin)->lockForUpdate()->first();

                if ($existing !== null) {
                    return $this->sameIntent($existing, $intentHash);
                }
            }

            $prepared = $handler->prepare(clone $data, $context, $connection);
            $attributes = $this->attributes($prepared);
            $action = new CalendarAction;
            $action->setConnection($connection->getName());
            $action->forceFill($attributes + [
                'id' => (string) Str::uuid(), 'status' => 'pending', 'revision' => 1,
                'attempt_number' => 1, 'current_attempt_id' => (string) Str::uuid(),
                'principal' => $context->principal, 'creator' => $context->creator, 'tenant_token' => $context->tenantToken,
                'intent_hash' => $intentHash,
            ]);
            $this->persist($action);
            $this->createAttempt($action);

            return $action;
        };

        try {
            return $connection->transaction($schedule);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent source insertion may win after our initial lookup. Its transaction
            // committed before the constraint answered; read it in a new transaction/savepoint.
            if ($data->origin === null) {
                throw $exception;
            }

            return $connection->transaction(function () use ($data, $context, $connection, $intentHash, $exception): CalendarAction {
                $existing = CalendarAction::on($connection->getName())->where('tenant_token', $context->tenantToken)
                    ->where('origin', $data->origin)->lockForUpdate()->first();

                return $existing === null ? throw $exception : $this->sameIntent($existing, $intentHash);
            });
        }
    }

    public function find(string $id, string $tenantToken, ?ConnectionInterface $connection = null): CalendarAction
    {
        return CalendarAction::on($this->connection($connection)->getName())
            ->where('tenant_token', $tenantToken)->findOrFail($id);
    }

    public function edit(string $id, int $expectedRevision, CalendarActionData $data, ActionContext $context, ?ConnectionInterface $connection = null): CalendarAction
    {
        $connection = $this->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $data, $context, $connection): CalendarAction {
            $action = $this->locked($id, $context, $connection);
            $this->authorize($action, $context, $connection);
            $this->expectRevision($action, $expectedRevision, ['pending']);
            $handler = $this->handlers->handler($data->kind)
                ?? throw ValidationException::withMessages(['kind' => 'No execution handler is available for this action kind.']);
            $handler->authorize($data, $context, $connection);
            $prepared = $handler->prepare(clone $data, $context, $connection);
            $action->forceFill($this->attributes($prepared) + [
                'revision' => $action->revision + 1, 'principal' => $context->principal,
                'intent_hash' => $this->intentHash($data, new ActionContext($context->principal, $action->creator, $context->tenantToken)),
            ]);
            $this->persist($action);
            $attempt = $action->attempts()->whereKey($action->current_attempt_id)->firstOrFail();
            $attempt->forceFill([
                'revision' => $action->revision, 'request' => $action->toActionData()->toArray(),
            ]);
            $this->persist($attempt);

            return $action;
        });
    }

    public function cancel(string $id, int $expectedRevision, ActionContext $context, ?ConnectionInterface $connection = null): CalendarAction
    {
        $connection = $this->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $context, $connection): CalendarAction {
            $action = $this->locked($id, $context, $connection);
            $this->authorize($action, $context, $connection);
            $this->expectRevision($action, $expectedRevision, ['pending']);
            $action->forceFill(['status' => 'cancelled', 'revision' => $action->revision + 1]);
            $this->persist($action);
            $attempt = $action->attempts()->whereKey($action->current_attempt_id)->firstOrFail();
            $attempt->forceFill([
                'status' => 'cancelled', 'completed_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->persist($attempt);

            return $action;
        });
    }

    /** Duplicate retry keys return their original attempt even after execution advances revision. */
    public function retry(string $id, int $expectedRevision, ActionContext $context, CarbonInterface $dueAt, string $idempotencyKey, ?ConnectionInterface $connection = null): CalendarActionAttempt
    {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            throw ValidationException::withMessages(['idempotency_key' => 'Provide a nonempty retry key of at most 255 characters.']);
        }

        $connection = $this->connection($connection);
        $dueAt = CarbonImmutable::instance($dueAt)->utc();
        $fingerprint = hash('sha256', json_encode([
            $expectedRevision, ActionInstant::format($dueAt), $context->principal, $context->creator,
        ], JSON_THROW_ON_ERROR));

        return $connection->transaction(function () use ($id, $expectedRevision, $context, $dueAt, $idempotencyKey, $fingerprint, $connection): CalendarActionAttempt {
            $action = $this->locked($id, $context, $connection);
            $this->authorize($action, $context, $connection);
            $existing = $action->attempts()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->idempotency_hash !== $fingerprint) {
                    throw new ActionConflict('The retry key was already used for a different request.');
                }

                return $existing;
            }

            $this->expectRevision($action, $expectedRevision, ['blocked', 'failed']);
            $action->forceFill([
                'status' => 'pending', 'revision' => $action->revision + 1,
                'attempt_number' => $action->attempt_number + 1,
                'current_attempt_id' => (string) Str::uuid(), 'due_at' => $dueAt,
            ]);
            $this->persist($action);

            return $this->createAttempt($action, $idempotencyKey, $fingerprint);
        });
    }

    private function locked(string $id, ActionContext $context, Connection $connection): CalendarAction
    {
        return CalendarAction::on($connection->getName())->where('tenant_token', $context->tenantToken)
            ->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function authorize(CalendarAction $action, ActionContext $context, Connection $connection): void
    {
        $handler = $this->handlers->handler($action->kind)
            ?? throw ValidationException::withMessages(['kind' => 'No execution handler is available for this action kind.']);
        $handler->authorize($action->toActionData(), $context, $connection);
    }

    private function expectRevision(CalendarAction $action, int $expectedRevision, array $statuses): void
    {
        if ($action->revision !== $expectedRevision || ! in_array($action->status, $statuses, true)) {
            throw new ActionConflict('The action changed or is no longer editable. Reload its current outcome.');
        }
    }

    private function sameIntent(CalendarAction $action, string $intentHash): CalendarAction
    {
        if ($action->intent_hash !== $intentHash) {
            throw new ActionConflict('This origin already identifies a different action intent. Edit the existing action explicitly.');
        }

        return $action;
    }

    private function intentHash(CalendarActionData $data, ActionContext $context): string
    {
        $attributes = $this->attributes($data);
        $attributes['due_at'] = ActionInstant::format($attributes['due_at']);
        $attributes['principal'] = $context->principal;
        $attributes['creator'] = $context->creator;
        $attributes['tenant_token'] = $context->tenantToken;

        return hash('sha256', json_encode($this->canonical($attributes), JSON_THROW_ON_ERROR));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    /** The exact same Laravel connection is used by Eloquent and by the handler. */
    public function connection(?ConnectionInterface $connection = null): Connection
    {
        $connection ??= DB::connection();

        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('Calendar actions require a Laravel database connection.');
        }

        return $connection;
    }

    public function persist(Model $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException('Calendar action persistence was refused by a model observer.');
        }
    }

    private function attributes(CalendarActionData $data): array
    {
        if (! in_array($data->timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Use an IANA timezone identifier.']);
        }

        $instant = ActionInstant::parse($data->dueAt);

        return [
            'kind' => $data->kind, 'payload' => $data->payload,
            'due_at' => $instant, 'timezone' => $data->timezone,
            'calendar_id' => $data->calendarId, 'series_id' => $data->seriesId, 'recurrence_id' => $data->recurrenceId,
            'origin' => $data->origin, 'correlation_id' => $data->correlationId,
        ];
    }

    private function createAttempt(CalendarAction $action, ?string $idempotencyKey = null, ?string $fingerprint = null): CalendarActionAttempt
    {
        $attempt = $action->attempts()->make([
            'id' => $action->current_attempt_id, 'number' => $action->attempt_number,
            'revision' => $action->revision, 'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
            'idempotency_hash' => $fingerprint,
            'request' => $action->toActionData()->toArray(), 'result' => [], 'blockers' => [],
        ]);
        $this->persist($attempt);

        return $attempt;
    }
}
