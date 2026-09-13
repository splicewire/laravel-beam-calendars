# Executable calendar actions

An action is dated intent with a durable execution attempt. It is independent of the calendar's
informational events and Generate/Reference recurrence spawning. Installing this package adds no
workflow, composition or Circuit requirement; an optional adapter supplies those meanings through
`Contracts\ActionHandler`.

## Install and mount

Publish this package's migrations, including `create_calendar_actions_table` and
`create_calendar_action_attempts_table`, through the host's normal Beam installation flow. They are
shared, connection-scoped stubs; neither provider boot nor a calendar read runs them.

Bind `Contracts\ActionContextProvider` in the host. Its `current()` returns an `Actions\ActionContext`
whose `principal`, `creator` and `tenantToken` come from authenticated host state. The provider must
reject unavailable authentication or tenant context. These fields are not writable request fields.
The host establishes the database connection and tenant before entering the package.

Register each action kind with `Registries\ActionHandlerRegistry::register($kind, $handlerClass)`.
Dotted names such as `kind.workflow-transition` are preserved. The provider declares a
`calendar-actions` particle, but mounts no routes. Mount inside the authenticated host route group:

```php
Splicewire\Beam\Calendars\ActionResources::mount('calendar-actions');
```

The read surface is `GET calendar-actions` and `GET calendar-actions/{id}`. Both are limited to the
current tenant and execution principal. `filter[calendar_id]` is a declared exact filter. The
`calendar_id` association is an opaque string: a host can distinguish `calendar:<uuid>` and
`composition:<uuid>` without the package interpreting either. Association authorization belongs in
the host/handler. Reads expose outcomes and attempts, but omit the internal tenant token and hashes.

Writes use declared operations; raw resource create, update and delete are not mounted:

| Operation | Input | Output |
| --- | --- | --- |
| `POST calendar-actions/schedule` | `CalendarActionData` | `CalendarActionRecordData` |
| `POST calendar-actions/{id}/reschedule` | `expected_revision`, `action: CalendarActionData` | `CalendarActionRecordData` |
| `POST calendar-actions/{id}/cancel` | `expected_revision` | `CalendarActionRecordData` |
| `POST calendar-actions/{id}/retry` | `expected_revision`, `due_at`, `idempotency_key` | `CalendarActionAttemptData` |

The operation's `ability: null` is deliberate: `ActionService` authorizes the concrete requested
subject through its handler, inside the transaction. Rescheduling authorizes both the existing and
replacement subject. Resource reads separately use the principal/tenant scope and read policy.
Never expose the model through an unrestricted generic write mount.

## Handler contract

`authorize(data, context, connection)` checks live caller authority without replacing stored pins.
`prepare(data, context, connection)` validates its nested payload through a declared Data class and
returns canonical data with server-derived pins. Both run in the action's creation/edit transaction.
A workflow adapter, for example, owns subject resolution and the definition/version it freezes;
this package knows only its kind and declared envelope.

`execute(action, attempt, connection)` checks the stored execution principal and current facts, then
returns `ActionResultData`: `applied`, `blocked`, or `failed`, with a blocker list and result map.
Use the supplied **same database connection** for all local mutations and lock the subject before
checking facts. A successful local mutation and its action result commit in one transaction.
Network effects must be persisted as outbox intent, then delivered with their own idempotency keys
and receipts. This transaction does not provide external exactly-once delivery.

Run `Actions\ActionScheduler::sweep($tenantToken, $now, $connection)` after the host establishes
scope. It captures each candidate's revision, then locks and checks it again before execution.
Queued callers should pass the captured revision as the fifth argument to `run()`. A stale revision
returns no attempt, even if its replacement is already due. Calling `run()` without that argument
deliberately asks to execute the current intent.

## Time and identity

`due_at` is an offset-bearing ISO instant, stored in UTC with up to six fractional digits. The IANA
`timezone` is preserved for display and future editing. Existing date-only event and series anchors
keep their existing contract. An event kind registration alone never turns a date into an action.

An action has a stable ID, a revision and an active attempt ID. `origin`, when supplied, identifies
one source intent within a tenant, enforced by a unique index. `correlation_id` groups related work
and is not unique. A replay with the same origin and canonical authored intent returns the original
action, including after completion. The authored-intent hash includes principal, creator and tenant;
server preparation is not rerun on a matching replay, so completed subject changes cannot repin it.
A different meaning under that origin is a conflict; edit the existing pending action explicitly.

Pending edits and cancellation take the same row lock as execution and require `expected_revision`.
Whichever transaction acquires the lock and commits first wins; the other reloads the current state
and conflicts or skips. Applied and cancelled actions cannot be edited or retried. Blocked/failed
actions are not automatically retried. Explicit retry creates a new attempt and preserves the old
receipt and request snapshot. Repeated identical retry keys return that same attempt even after
execution advances the revision; reusing a key for a different request conflicts.

## Failure and recovery

The first pending attempt is persisted when the action is authored. Execution marks it running only
inside its local transaction; a hard process/database abort rolls back both the claim and local
subject changes, leaving the same pending identity recoverable. A committed terminal result is not
re-executed by a later sweep.

A handler exception, blocked result or failed result rolls back handler-local writes to a savepoint,
then records the non-applied outcome. Missing executable handlers are visibly failed. Model observer
save vetoes throw; an inability to persist the receipt aborts the whole transaction. `completed_at`
records the clock after the handler returns, rather than copying the sweep's initial instant.

Materialization, projection, export and a host's read lens remain reads or placement operations;
none invokes this scheduler. Recurring action templates are not part of this initial seam. A future
recurrence adapter must retain occurrence identity and route pinned occurrences to their own due
action, without treating materialization as execution or changing Generate/Reference spawning.

## Verification

`composer test` runs the package's real migration stubs in SQLite. The action service tests cover
transaction rollback, save vetoes, stale revisions, scoped reads/writes, precise due instants, origin
deduplication and explicit retries. `CalendarActionSurfaceTest` mounts the real particle routes and
checks the same behavior over HTTP. SQLite tests do not establish PostgreSQL worker contention;
hosts requiring that control should exercise two connections and process-abort recovery against
their isolated PostgreSQL database before enabling their scheduler.
