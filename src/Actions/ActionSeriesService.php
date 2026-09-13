<?php

namespace Splicewire\Beam\Calendars\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Data\ActionOccurrenceData;
use Splicewire\Beam\Calendars\Data\ActionSeriesInputData;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Data\CalendarActionRecordData;
use Splicewire\Beam\Calendars\Data\RecurrenceRuleData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionSeries;
use Splicewire\Beam\Calendars\Recurrence\SeriesExpander;
use Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry;

class ActionSeriesService
{
    public function __construct(private ActionService $actions, private ActionHandlerRegistry $handlers, private SeriesExpander $expander) {}

    public function schedule(ActionSeriesInputData $input, ActionContext $context, ?ConnectionInterface $connection = null): CalendarActionSeries
    {
        $connection = $this->actions->connection($connection);

        return $connection->transaction(function () use ($input, $context, $connection): CalendarActionSeries {
            $this->validateRule($input);
            $handler = $this->handlers->handler($input->action->kind)
                ?? throw ValidationException::withMessages(['action.kind' => 'No execution handler is available for this action kind.']);
            $handler->authorize($input->action, $context, $connection);
            $template = $handler->prepare(clone $input->action, $context, $connection);
            $this->localStart($template);
            $series = new CalendarActionSeries;
            $series->setConnection($connection->getName());
            $series->forceFill([
                'revision' => 1, 'status' => 'active', 'principal' => $context->principal,
                'creator' => $context->creator, 'tenant_token' => $context->tenantToken,
                'calendar_id' => $template->calendarId, 'template' => $template->toArray(),
                'rule' => $input->rule->toArray(), 'window' => $input->window, 'overrides' => [], 'blockers' => [],
            ]);
            $this->actions->persist($series);

            return $series;
        });
    }

    /** @return list<ActionOccurrenceData> Read-only, including pending or terminal pinned records. */
    public function project(CalendarActionSeries $series, string $from, string $through): array
    {
        validator(['from' => $from, 'through' => $through], [
            'from' => ['required', 'date_format:Y-m-d'], 'through' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $records = CalendarAction::on($series->getConnectionName())->where('tenant_token', $series->tenant_token)
            ->where('series_id', $series->id)->where('origin', 'like', 'action-series:'.$series->id.':%')->get()->keyBy('recurrence_id');
        $out = [];
        foreach ($this->dates($series, $through) as $date) {
            $key = $date->toDateString();
            $record = $records->get($key);
            if ($record !== null) {
                $data = $record->toActionData();
            } elseif ($series->status !== 'active' || $this->override($series, $key) === 'skip') {
                continue;
            } else {
                $data = $this->occurrence($series, $key);
            }
            $displayDate = ActionInstant::parse($data->dueAt)->setTimezone($data->timezone)->toDateString();
            if ($displayDate >= $from && $displayDate <= $through) {
                $out[] = new ActionOccurrenceData($key, $data, $record ? CalendarActionRecordData::fromModel($record) : null);
            }
        }
        // Moved pins whose original date lies beyond this horizon still belong on the read lens.
        $seen = array_fill_keys(array_map(fn (ActionOccurrenceData $o): string => $o->recurrenceId, $out), true);
        foreach ($records as $record) {
            $date = $record->due_at->setTimezone($record->timezone)->toDateString();
            if (! isset($seen[$record->recurrence_id]) && $date >= $from && $date <= $through) {
                $out[] = new ActionOccurrenceData($record->recurrence_id, $record->toActionData(), CalendarActionRecordData::fromModel($record));
            }
        }
        usort($out, fn (ActionOccurrenceData $a, ActionOccurrenceData $b): int => strcmp($a->action->dueAt, $b->action->dueAt));

        return $out;
    }

    public function pin(string $id, int $expectedRevision, string $recurrenceId, ActionContext $context, ?ConnectionInterface $connection = null): CalendarAction
    {
        $connection = $this->actions->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $recurrenceId, $context, $connection): CalendarAction {
            $series = $this->locked($id, $context, $connection);
            $this->editable($series, $expectedRevision, $context, $connection);
            $this->assertOccurrence($series, $recurrenceId);
            if ($this->override($series, $recurrenceId) === 'skip') {
                throw new ActionConflict('This occurrence is skipped. Its exclusion must remain explicit.');
            }

            return $this->materialize($series, $recurrenceId, $connection);
        });
    }

    public function replace(string $id, int $expectedRevision, string $recurrenceId, CalendarActionData $replacement, ActionContext $context, ?ConnectionInterface $connection = null): CalendarAction
    {
        $connection = $this->actions->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $recurrenceId, $replacement, $context, $connection): CalendarAction {
            $series = $this->locked($id, $context, $connection);
            $this->editable($series, $expectedRevision, $context, $connection);
            $this->assertOccurrence($series, $recurrenceId);
            $data = clone $replacement;
            $data->seriesId = $series->id;
            $data->recurrenceId = $recurrenceId;
            $data->origin = 'action-series:'.$series->id.':'.$recurrenceId;
            $existing = $this->record($series, $recurrenceId, $connection);
            if ($existing === null) {
                $handler = $this->handlers->handler($data->kind)
                    ?? throw ValidationException::withMessages(['action.kind' => 'No execution handler is available for this action kind.']);
                $handler->authorize($data, $context, $connection);
                $prepared = $handler->prepare($data, $context, $connection);
                $action = $this->actions->schedulePrepared($prepared, $context, $connection);
            } else {
                $action = $this->actions->edit($existing->id, $existing->revision, $data, $context, $connection);
            }
            $this->setOverride($series, $recurrenceId, 'replace', $action->id);

            return $action;
        });
    }

    public function skip(string $id, int $expectedRevision, string $recurrenceId, ActionContext $context, ?ConnectionInterface $connection = null): CalendarActionSeries
    {
        $connection = $this->actions->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $recurrenceId, $context, $connection): CalendarActionSeries {
            $series = $this->locked($id, $context, $connection);
            $this->editable($series, $expectedRevision, $context, $connection);
            $this->assertOccurrence($series, $recurrenceId);
            $record = $this->record($series, $recurrenceId, $connection);
            if ($record !== null && $record->status !== 'cancelled') {
                $this->actions->cancel($record->id, $record->revision, $context, $connection);
            }
            $this->setOverride($series, $recurrenceId, 'skip', $record?->id);

            return $series;
        });
    }

    public function cancel(string $id, int $expectedRevision, ActionContext $context, ?ConnectionInterface $connection = null): CalendarActionSeries
    {
        $connection = $this->actions->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $context, $connection): CalendarActionSeries {
            $series = $this->locked($id, $context, $connection);
            $this->editable($series, $expectedRevision, $context, $connection, ['active', 'blocked']);
            foreach (CalendarAction::on($connection->getName())->where('tenant_token', $context->tenantToken)->where('series_id', $id)->where('origin', 'like', 'action-series:'.$id.':%')->where('status', 'pending')->orderBy('id')->lockForUpdate()->get() as $action) {
                $this->actions->cancel($action->id, $action->revision, $context, $connection);
            }
            $series->forceFill(['status' => 'cancelled', 'revision' => $series->revision + 1]);
            $this->actions->persist($series);

            return $series;
        });
    }

    /** Resume materialization explicitly; existing terminal occurrence attempts are never retried here. */
    public function resume(string $id, int $expectedRevision, ActionContext $context, ?ConnectionInterface $connection = null): CalendarActionSeries
    {
        $connection = $this->actions->connection($connection);

        return $connection->transaction(function () use ($id, $expectedRevision, $context, $connection): CalendarActionSeries {
            $series = $this->locked($id, $context, $connection);
            $this->editable($series, $expectedRevision, $context, $connection, ['blocked']);
            $series->forceFill(['status' => 'active', 'blockers' => [], 'revision' => $series->revision + 1]);
            $this->actions->persist($series);

            return $series;
        });
    }

    /** @return list<\Splicewire\Beam\Calendars\Models\CalendarActionAttempt> */
    public function sweep(string $tenantToken, ?CarbonInterface $now = null, ?ConnectionInterface $connection = null): array
    {
        $connection = $this->actions->connection($connection);
        $now = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now)->utc();
        $ids = CalendarActionSeries::on($connection->getName())->where('tenant_token', $tenantToken)->where('status', 'active')->pluck('id');
        foreach ($ids as $id) {
            $connection->transaction(function () use ($id, $tenantToken, $now, $connection): void {
                $series = CalendarActionSeries::on($connection->getName())->where('tenant_token', $tenantToken)->whereKey($id)->lockForUpdate()->first();
                if ($series === null || $series->status !== 'active') {
                    return;
                }
                $through = $now->setTimezone($series->actionData()->timezone)->toDateString();
                try {
                    // A savepoint rolls back every new materialization in this series on failure;
                    // the outer lock then records the visible blocked result.
                    $connection->transaction(function () use ($series, $through, $now, $connection): void {
                        foreach ($this->dates($series, $through) as $date) {
                            $key = $date->toDateString();
                            if ($this->override($series, $key) === 'skip' || ActionInstant::parse($this->occurrence($series, $key)->dueAt)->gt($now)) {
                                continue;
                            }
                            $this->materialize($series, $key, $connection);
                        }
                    });
                } catch (\Throwable $exception) {
                    report($exception);
                    $series->forceFill(['status' => 'blocked', 'revision' => $series->revision + 1,
                        'blockers' => ['Occurrence materialization is unavailable or no longer authorized. Restore the handler or authority, then resume this series.']]);
                    $this->actions->persist($series);
                }
            });
        }

        // Existing pinned actions still execute at their own due instant, including moved pins.
        return app(ActionScheduler::class)->sweep($tenantToken, $now, $connection);
    }

    private function materialize(CalendarActionSeries $series, string $recurrenceId, Connection $connection): CalendarAction
    {
        return $this->record($series, $recurrenceId, $connection)
            ?? $this->actions->schedulePrepared($this->occurrence($series, $recurrenceId), $series->context(), $connection);
    }

    private function record(CalendarActionSeries $series, string $recurrenceId, Connection $connection): ?CalendarAction
    {
        return CalendarAction::on($connection->getName())->where('tenant_token', $series->tenant_token)
            ->where('origin', 'action-series:'.$series->id.':'.$recurrenceId)->lockForUpdate()->first();
    }

    private function locked(string $id, ActionContext $context, Connection $connection): CalendarActionSeries
    {
        return CalendarActionSeries::on($connection->getName())->where('tenant_token', $context->tenantToken)
            ->where('principal', $context->principal)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function editable(CalendarActionSeries $series, int $expectedRevision, ActionContext $context, Connection $connection, array $statuses = ['active']): void
    {
        $handler = $this->handlers->handler($series->actionData()->kind)
            ?? throw ValidationException::withMessages(['action.kind' => 'No execution handler is available for this action kind.']);
        $handler->authorize($series->actionData(), $context, $connection);
        if ($series->revision !== $expectedRevision || ! in_array($series->status, $statuses, true)) {
            throw new ActionConflict('The action series changed or is no longer editable. Reload it.');
        }
    }

    private function assertOccurrence(CalendarActionSeries $series, string $recurrenceId): void
    {
        validator(['recurrence_id' => $recurrenceId], ['recurrence_id' => ['required', 'date_format:Y-m-d']])->validate();
        foreach ($this->dates($series, $recurrenceId) as $date) {
            if ($date->toDateString() === $recurrenceId) {
                return;
            }
        }
        throw ValidationException::withMessages(['recurrence_id' => 'This series rule generates no occurrence on that date.']);
    }

    private function setOverride(CalendarActionSeries $series, string $recurrenceId, string $mode, ?string $actionId): void
    {
        $overrides = array_values(array_filter($series->overrides, fn (array $entry): bool => $entry['recurrence_id'] !== $recurrenceId));
        $overrides[] = ['recurrence_id' => $recurrenceId, 'mode' => $mode, 'action_id' => $actionId];
        $series->forceFill(['overrides' => $overrides, 'revision' => $series->revision + 1]);
        $this->actions->persist($series);
    }

    private function localStart(CalendarActionData $template): CarbonImmutable
    {
        if (! in_array($template->timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['action.timezone' => 'Use an IANA timezone identifier.']);
        }

        return ActionInstant::parse($template->dueAt)->setTimezone($template->timezone);
    }

    /** @return list<Carbon> */
    private function dates(CalendarActionSeries $series, string $through): array
    {
        return $this->expander->dates($this->localStart($series->actionData())->toDateString(), RecurrenceRuleData::from($series->rule), $series->window, Carbon::parse($through)->endOfDay());
    }

    private function occurrence(CalendarActionSeries $series, string $recurrenceId): CalendarActionData
    {
        $template = $series->actionData();
        $start = $this->localStart($template);
        $local = ActionLocalTime::atDate($start, $recurrenceId, $template->timezone);
        $template->dueAt = ActionInstant::format($local->utc());
        $template->seriesId = $series->id;
        $template->recurrenceId = $recurrenceId;
        $template->origin = 'action-series:'.$series->id.':'.$recurrenceId;

        return $template;
    }

    private function override(CalendarActionSeries $series, string $recurrenceId): ?string
    {
        foreach ($series->overrides as $override) {
            if ($override['recurrence_id'] === $recurrenceId) {
                return $override['mode'];
            }
        }

        return null;
    }

    private function validateRule(ActionSeriesInputData $input): void
    {
        $rule = $input->rule->toArray();
        validator(['rule' => $rule, 'window' => $input->window], [
            'rule.interval' => ['sometimes', 'integer', 'min:1'], 'rule.count' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'rule.until' => ['sometimes', 'date_format:Y-m-d'], 'rule.byday' => ['sometimes', 'array'],
            'rule.byday.*' => ['in:MO,TU,WE,TH,FR,SA,SU', 'distinct'], 'window' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        if (isset($rule['count'], $rule['until'])) {
            throw ValidationException::withMessages(['rule' => 'Choose count or until, not both.']);
        }
        $this->localStart($input->action);
    }
}
