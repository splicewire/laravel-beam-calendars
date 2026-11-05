# splicewire/laravel-beam-calendars

The generic **calendar** particle for the Beam family — dated events, recurrence series, an
exactly-once firing ledger, ICS/RSS export, and a declarative particle surface over all three.

**Composition-free and engine-free by construction.** No composition kernel, no scheduler vendor, no
AI. Free-tier arm of the Beam family: it depends **DOWN** on `splicewire/laravel-beam` and
`schemastud/laravel-data-schemas`, and never reaches **up** onto the composition/tower/satellite
tiers that consume it.

## What you get with nothing configured

A calendar you can create, add events to, give a recurrence rule, project over a horizon, subscribe
to as ICS, and sweep on a cron. Firings are claimed exactly once and announced as
`OccurrenceFired`. That is the whole free tier — not a degraded mode.

## Tables

Published (never auto-run), convergent, and shared-by-default so they run on both the central
and per-tenant passes:

| table | holds |
|---|---|
| `calendars` | the calendar particle — payload envelope + a thin queryable projection |
| `calendar_series` | one row per recurrence RULE, O(1) on disk however long it runs |
| `calendar_events` | dated rows, including materialized series instances |
| `calendar_firings` | the exactly-once ledger, unique on `(series_id, recurrence_id)` |
| `calendar_actions` | revisioned dated intent with a tenant-scoped source identity |
| `calendar_action_attempts` | stable execution identities and immutable completed outcomes |
| `calendar_action_series` | frozen action templates, recurrence rules and scoped occurrence overrides |

## The particle surface

Resources `calendars`, `calendar-events`, `calendar-series`, plus five operations on a calendar:

| op | kind | ability |
|---|---|---|
| `project` | Read | `view` |
| `export` | Read | `view` |
| `sweep` | Task | `update` |
| `materialize` | Write | `update` |
| `skip` | Write | `update` |

The content resources use the models' `#[UseCascadePolicy]` attributes. Executable actions have a
separate read policy scoped to the host-provided principal and tenant; mutations authorize through
their registered handler.

## Executable actions

Optional handlers can execute one-off or recurring dated actions with atomic local results, visible blocked/failed
outcomes, revision checks and explicit idempotent retries. Existing informational events and
Generate/Reference spawning retain their behavior. See [action setup and execution](docs/actions.md)
for host context, particle mounting, opaque calendar associations and the transaction boundary.

## Registries

This package declares four registries. The [calendars cluster page](docs/registries/beam.calendars.mdx) says
which one you reach for.

- [`beam.calendars.event-kinds`](docs/registries/beam.calendars.event-kinds.mdx): what can sit on a date, and
  the payload class each kind is validated through.
- [`beam.calendars.channels`](docs/registries/beam.calendars.channels.mdx): the named lanes a calendar's events
  are filed under.
- [`beam.calendars.renderers`](docs/registries/beam.calendars.renderers.mdx): one renderer per export format.
- [`beam.calendars.action-handlers`](docs/registries/beam.calendars.action-handlers.mdx): the handler behind each
  executable action kind.

It registers into registries `splicewire/laravel-beam` owns:

- [`beam.install.steps`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.install.steps.mdx):
  the install step that publishes the config and the shared migrations.
- [`beam.doctor.audits`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.doctor.audits.mdx):
  the audit of the package's own published migrations.
- [`beam.nav.sections`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.nav.sections.mdx):
  a `calendars` section in the `operator` and `tenant` realms.
- [`beam.particle.resources`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.resources.mdx)
  and [`beam.particle.operations`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.operations.mdx):
  the calendar and action resources and their operations, declared at boot. With `beam.calendars.register_resources`
  off, only the two action resources and their operations are declared.

## Extending it

See `AGENTS.md` for extension ports and registries, and for the handful of things that look like
tidying opportunities and are load-bearing instead.
