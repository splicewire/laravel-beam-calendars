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

Four, published (never auto-run), convergent, and shared-by-default so they run on both the central
and per-tenant passes:

| table | holds |
|---|---|
| `calendars` | the calendar particle — payload envelope + a thin queryable projection |
| `calendar_series` | one row per recurrence RULE, O(1) on disk however long it runs |
| `calendar_events` | dated rows, including materialized series instances |
| `calendar_firings` | the exactly-once ledger, unique on `(series_id, recurrence_id)` |

## The particle surface

Resources `calendars`, `calendar-events`, `calendar-series`, plus five operations on a calendar:

| op | kind | ability |
|---|---|---|
| `project` | Read | `view` |
| `export` | Read | `view` |
| `sweep` | Task | `update` |
| `materialize` | Write | `update` |
| `skip` | Write | `update` |

Authorization is the models' own `#[UseCascadePolicy]` attributes — **no Policy class ships here.**

## Extending it

See `AGENTS.md` for the two ports and three registries, and for the handful of things that look like
tidying opportunities and are load-bearing instead.
