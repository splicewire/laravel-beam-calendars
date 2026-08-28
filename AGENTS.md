> You are in **splicewire/laravel-beam-calendars** — the generic calendar particle for the Beam family.

A Laravel package providing dated events, recurrence series, an exactly-once firing ledger, ICS/RSS
export and a declarative particle surface over all of it. **Composition-free and engine-free by
construction**: no composition kernel, no scheduler vendor, no AI. Free-tier arm of the Beam family —
depends down on `splicewire/laravel-beam` (the particle substrate) and
`schemastud/laravel-data-schemas` (Data), and never reaches up onto the composition/tower/satellite
tiers that are additive to it.

## What an engine adds, and where

Everything an engine contributes goes through **two ports and three registries**, all seeded here and
none of them required to be filled. A host with nothing bound has a complete, usable calendar.

- `Contracts\SpawnDriver` — what a due occurrence DOES. `null` (the default) still claims, records
  and announces the firing; only the consequence is bought separately. The driver is **pure** — it
  never touches the database, and the substrate keeps the exactly-once write.
- `Contracts\ChannelSource` — where lane vocabulary comes from. Defaults to the channel registry; a
  multi-tenant host binds something tenant-aware.
- `beam.calendars.event_kinds` — an unregistered kind is an **absent config key**, so a registry miss
  IS the unknown-kind condition. This is what lets tower contribute `kind.disclosure-ref` and
  `kind.run-circuit` without this package being able to name tower at all.
- `beam.calendars.renderers` — format token → renderer. Adding a format is a registration, not an edit.
- `beam.calendars.channels` — the lane vocabulary, with a `default` seed.

## Things that look like tidying and are not

- **`RecurrenceRuleData` and every `*InputData` use `T|Optional` with `= new Optional`, never
  `?T = null`.** The nullable form cannot express "clear this" — an absent field and an explicit null
  both arrive as `null`, so a write gate can set but never unset, silently, with a 200.
- **`SeriesData::schemaName()` is `calendar/series`, singular**, even though the package is plural.
  Stored payloads reconcile forward on that exact string.
- **`SpawnData` spells the reference `target_ref`, not `composition_id`.** A composition is a
  paid-tier concept this package must not name.
- **The migration stubs carry both a Postgres and a SQLite existence probe.** Each is wrong on the
  other's driver; keeping both is what lets the suite run the REAL stubs rather than a hand-built
  fixture schema that can drift.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it ships
with itself before editing through into it.
