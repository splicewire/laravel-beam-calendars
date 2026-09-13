<?php

namespace Splicewire\Beam\Calendars\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Data\ActionResultData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry;
use Throwable;

/** Executes only local transactional handlers; no external exactly-once promise. */
class ActionScheduler
{
    public function __construct(private ActionService $actions, private ActionHandlerRegistry $handlers) {}

    public function run(string $actionId, string $tenantToken, ?CarbonInterface $now = null, ?ConnectionInterface $connection = null, ?int $expectedRevision = null): ?CalendarActionAttempt
    {
        $connection = $this->actions->connection($connection);
        $now = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now)->utc();

        return $connection->transaction(function () use ($actionId, $tenantToken, $now, $connection, $expectedRevision): ?CalendarActionAttempt {
            $action = CalendarAction::on($connection->getName())->where('tenant_token', $tenantToken)
                ->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null || $action->status !== 'pending' || $action->due_at->greaterThan($now)
                || ($expectedRevision !== null && $action->revision !== $expectedRevision)) {
                return null;
            }

            $attempt = $action->attempts()->whereKey($action->current_attempt_id)->lockForUpdate()->firstOrFail();
            $attempt->forceFill(['status' => 'running', 'started_at' => $now]);
            $this->actions->persist($attempt);
            $action->forceFill(['status' => 'running']);
            $this->actions->persist($action);

            try {
                // A savepoint ensures a failing/blocked handler cannot leave partial local writes.
                $result = $connection->transaction(function () use ($action, $attempt, $connection): ActionResultData {
                    $handler = $this->handlers->handler($action->kind);
                    if ($handler === null) {
                        $result = new ActionResultData('failed', ['The execution handler for this action kind is unavailable.']);
                    } else {
                        $handler->authorize($action->toActionData(), new ActionContext($action->principal, $action->creator, $action->tenant_token), $connection);
                        $result = $handler->execute($action, $attempt, $connection);
                    }

                    if ($result->status !== 'applied') {
                        throw new ActionNotApplied($result);
                    }

                    return $result;
                });
            } catch (ActionNotApplied $notApplied) {
                $result = $notApplied->result;
            } catch (AuthorizationException $exception) {
                $result = new ActionResultData('blocked', ['The execution principal is no longer authorized.']);
            } catch (Throwable $exception) {
                report($exception);
                $result = new ActionResultData('failed', ['The action handler failed.'], ['exception' => $exception::class]);
            }

            $attempt->forceFill([
                'status' => $result->status, 'result' => $result->result,
                'blockers' => $result->blockers, 'completed_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->actions->persist($attempt);
            $action->forceFill(['status' => $result->status, 'revision' => $action->revision + 1]);
            $this->actions->persist($action);

            return $attempt;
        });
    }

    /** @return list<CalendarActionAttempt> */
    public function sweep(string $tenantToken, ?CarbonInterface $now = null, ?ConnectionInterface $connection = null): array
    {
        $connection = $this->actions->connection($connection);
        $now = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now)->utc();
        $candidates = CalendarAction::on($connection->getName())->where('tenant_token', $tenantToken)
            ->where('status', 'pending')->where('due_at', '<=', $now->format('Y-m-d H:i:s.uP'))->orderBy('due_at')->get(['id', 'revision']);
        $attempts = [];

        foreach ($candidates as $candidate) {
            $attempt = $this->run($candidate->id, $tenantToken, $now, $connection, $candidate->revision);

            if ($attempt !== null) {
                $attempts[] = $attempt;
            }
        }

        return $attempts;
    }
}
