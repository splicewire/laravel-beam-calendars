# Executable calendar actions

An action is dated intent with a durable execution attempt. It is independent of the calendar's
informational events and Generate/Reference recurrence spawning. Installing this package adds no
workflow, composition or Circuit requirement; an optional adapter supplies those meanings through
`Contracts\ActionHandler`.

## Install and mount

Publish this package's migrations, including `create_calendar_actions_table`,
`create_calendar_action_attempts_table` and `create_calendar_action_series_table`, through the host's
normal Beam installation flow. They are
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
Origin itself is immutable during editing. `beam.calendars.reserved_action_origins` prevents public
scheduling from claiming server adapter namespaces; this package reserves `action-series:`.
Optional adapters append their own prefixes. Internal `ActionService::schedulePrepared()` accepts a
persisted server-prepared template, still checks concrete subject authority, and skips only the
preparation step. No public operation exposes that bypass.

`ActionService::restorePreparedIntent(data, savedContext, connection)` is a narrower internal
reconstitution seam for a host that already verified a durable, previously authorized source. It
requires a stable origin and preserves that saved intent even if its handler or authority disappeared.
It skips authoring admission and preparation, but grants no authority for effects: `ActionScheduler`
always authorizes the stored context before calling `execute`, recording revoked authority as blocked
and an absent handler as failed. Never expose this restoration method to request input.

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
none invokes this scheduler.

## Recurring actions

`CalendarActionSeries` stores a frozen generic action template, typed `RecurrenceRuleData`, optional
window, occurrence overrides and host context. It is separate from the spawn-based `CalendarSeries`
model. Both use `SeriesExpander::dates()` as their recurrence date authority; Generate/Reference
`SeriesData`, `Occurrence`, `SpawnData` and the pure `SpawnDriver` keep their original contracts.
There is no workflow or composition import in this recurrence layer.

The initial `action.due_at` defines the first local date and wall time in `action.timezone`.
Subsequent dates preserve that wall time using `ActionLocalTime`, shared with relative date adapters.
During an autumn overlap the earlier instant wins. A spring gap shifts forward by the gap, preserving
minutes (02:30 becomes 03:30 in a one-hour gap). UTC offsets and microseconds remain explicit when
written to the database. COUNT includes skipped slots and does not extend the series to replace them.
The inherited recurrence expansion ceiling is 5,000 generated instances; rules and views should use
bounded windows appropriate to that limit.

The handler prepares the template once at series authoring, including its definition pin. Each
materialization checks live authority but retains that prepared template. Execution independently
checks current subject facts and pins. A recurring transition against one fixed subject may therefore
apply once and later block when that subject no longer permits it; recurrence never manufactures a
fresh subject or silently selects another workflow version.

Mount the optional action-series surface inside the same trusted host route group:

```php
Splicewire\Beam\Calendars\ActionSeriesResources::mount('calendar-action-series');
```

Resource index/show reads use the current principal and tenant, with exact `filter[calendar_id]`.
The write resource remains disabled; operations declare their complete Data shapes:

| Operation | Input | Output |
| --- | --- | --- |
| `POST calendar-action-series/schedule` | `ActionSeriesInputData`: `action`, `rule`, optional `window` | `CalendarActionSeriesData` |
| `GET calendar-action-series/{id}/project` | `from`, `through` local dates | `ActionOccurrenceListData` |
| `POST calendar-action-series/{id}/pin` | `expected_revision`, `recurrence_id` | `CalendarActionRecordData` |
| `POST calendar-action-series/{id}/skip` | `expected_revision`, `recurrence_id` | `CalendarActionSeriesData` |
| `POST calendar-action-series/{id}/replace` | `expected_revision`, `recurrence_id`, `action` | `CalendarActionRecordData` |
| `POST calendar-action-series/{id}/cancel` | `expected_revision` | `CalendarActionSeriesData` |
| `POST calendar-action-series/{id}/resume` | `expected_revision` | `CalendarActionSeriesData` |

Each recurrence ID is the original local date from the rule, retained after a move. Its stable source
origin is `action-series:<series-id>:<recurrence-id>`. Pinning creates one pending action and never
executes it; repeated pins return that record. A pin alone does not change the template revision.
Skip excludes one virtual slot, or cancels its pending pin; it cannot undo an applied result. Replace
prepares a replacement for exactly one slot and can move its due date. Existing cancelled/applied
records remain immutable. Skip and replace change the series revision. Once pinned, the normal action
reschedule/cancel/retry operations address that one action; reschedule must retain its origin,
series ID and recurrence ID. Cancelled occurrences never reappear as new pending actions.

Series cancellation stops future materialization and cancels its pending action records in one
transaction. It preserves applied, blocked and failed history. Explicit retry of a terminal blocked
or failed action remains an action-level decision. The series has no bulk edit or automatic repinning
operation: author a new series when the template itself needs a new definition or target.

`ActionSeriesService::project()` merges virtual occurrences and stored actions within local dates,
including pins moved in or out of the horizon. It writes nothing. `ActionSeriesService::sweep()`
locks each active series, materializes due dates using stable source identity, then runs the existing
`ActionScheduler` across the scoped tenant's pending actions. Existing pins execute at their own due
instants even if their original recurrence date differs. Hosts should invoke this entry point when
they need both recurring and one-off action delivery; reads never invoke it.

A materialization error or unavailable authority/handler rolls back that series' new materializations
to a savepoint and records visible `blocked` status plus blockers. Restoring the handler or authority
alone does not restart it: `resume` explicitly changes the series back to active after authorization
and a revision check. Resume does not retry any existing terminal action attempt. Those use their own
explicit idempotent retry operation. A hard transaction abort leaves pending identities recoverable.

## Verification

`composer test` runs the package's real migration stubs in SQLite. The action service tests cover
transaction rollback, save vetoes, stale revisions, scoped reads/writes, precise due instants, origin
deduplication and explicit retries. `CalendarActionSurfaceTest` mounts the real particle routes and
checks the same behavior over HTTP. Action-series tests cover frozen templates, COUNT/skip,
pin/replace/move scope, cancellation history, DST, missing-handler resume and abort recovery. A
[repeatable loopback fixture](../tests/Browser/README.md) runs the real optional workflow handler and
particle HTTP surfaces without Tower/composition for portable UI browser checks. SQLite tests do not
establish PostgreSQL worker contention;
hosts requiring that control should exercise two connections and process-abort recovery against
their isolated PostgreSQL database before enabling their scheduler.

Database writes and due-sweep bounds retain an explicit UTC offset as well as microseconds. This prevents a PostgreSQL session timezone from reinterpreting a UTC instant as local wall time.
