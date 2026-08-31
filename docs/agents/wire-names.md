# Wire names are declared here, so the PHP spelling is free

Every DTO property in `src/Data/` is **camelCase**. Every multi-word property carries
`#[MapName('snake_key')]`. `tests/Feature/WireNameTest.php` asserts the published keys directly.

Those three facts are one decision, and the order matters: **declare the wire, then the spelling
is a style choice.** Reverse it and a rename silently moves a published contract.

## ⚠️ What went wrong before this existed

The package shipped with **neither axis declared** on any of its 15 Data classes. Under the
flagship's `config/data.php`:

```php
'name_mapping_strategy' => ['input' => CamelCaseMapper::class, 'output' => null],
```

that meant:

| | declared? | actual published key |
|---|---|---|
| `ProjectedEventData::$calendar_id` (read) | no | `calendar_id` — output mapper is null, so the key is the property name |
| `CalendarEventInputData::$calendar_id` (write) | no | **`calendarId`** — the global input mapper camels it |

**The package emitted one key and demanded another for the same field.** Measured, not inferred:
a probe over `DataConfig::getDataClass()` returned `inputMappedName = calendarId` for
`$calendar_id`. Nothing reported it — the estate has an audit for "you did not declare your column
map" (`beam.particle.undeclared-write-map`) and **none** for "you did not declare your wire name",
which is the strictly more load-bearing of the two.

The symptom people notice is mixed casing inside one class — `CalendarSeriesData` had
`$calendar_id` next to `$overrideCount`. That is worth fixing, but it is the *symptom*. The
disease is that with nothing declared, the global mapper was choosing this package's contract.

## The form: per-property `#[MapName]`, not a class-level mapper

`#[MapInputName(SnakeCaseMapper::class)]` on the **class** was tried first and does not work here
— the resolved mapper still came back as the global one, so `$calendarId` published `calendarId`.
The estate's working form is the per-property attribute with an **explicit string**, which is what
`splicewire/tower` carries across 39 files:

```php
#[MapName('calendar_id')]
public string|Optional $calendarId = new Optional,
```

`MapName($input, $output = null)` defaults `output` to `input`, so one attribute pins both axes.

## Which axes each class pins, and why

- **Write DTOs** and **read DTOs** — both, so a field is named the same way going in and coming out.
- **Stored payload DTOs** (`SeriesData`, `SpawnData`, `RefData`, …) — both, and this one is not
  cosmetic: their keys live in a **JSON column**. A payload written under one mapper and read under
  another is unreadable data, not a formatting difference.

The direction is snake_case, matching the engine tier this was extracted from and the packaged TS
calendar surface, which maps the projection DTO "with zero casing translation".

## ⚠️ The trap this rename walked into twice

A DTO property is camelCase. An **Eloquent column is not.** Both are read with `->`, and a
mis-cased model read returns `null` rather than erroring:

```php
// in CalendarEventData::project(CalendarEvent $event)
calendarId: (string) $event->calendar_id,   // ✅ model → COLUMN, snake
```
```php
// in ProjectedEventData::fromProjection(ProjectedEvent $event)
calendarId: $event->calendarId,             // ✅ value object → PROPERTY, camel
```

A blanket regex cannot tell these apart — it renamed both, and the model reads silently became
`null`, surfacing three tests later as "expected '2026-01-06', got null". **Rename by subject
type, never by pattern.** `toModelAttributes()` now spells the mapping out as an explicit
`property => column` array for the same reason: the two used to be the same string, and a loop
over one list only worked while that coincidence held.

## Adding a property

Multi-word? Add `#[MapName('snake_key')]` and an assertion in `WireNameTest`. Single word? Nothing
to do — the mappers are identities on it. If you rename a property and a `WireNameTest` assertion
moves, the attribute is missing; that is the test's whole job.

## Checking your work — two instruments, two different questions

Both landed after this package was swept, so nothing here was verified by them at the time. Use them
on any future change.

**`splicewire:beam:dev:wire-names`** (in `splicewire/laravel-beam-dev`, `require-dev`) — needs **two
readings** and answers *"did this change move a published key?"*:

```
artisan splicewire:beam:dev:wire-names <src…> > before.txt
# …rename properties, leaving every attribute argument untouched…
artisan splicewire:beam:dev:wire-names <src…> > after.txt
diff before.txt after.txt        # MUST be empty
```

**`beam.particle.undeclared-wire-name`** (a doctor audit in `splicewire/laravel-beam`, on
`surgeon:audit`) — needs **one reading** and answers *"is this declaration coherent right now?"*. It
reports two things: a property a configured global mapper would rewrite, and a class that declares
**some** of its multi-word wire names but not others.

⚠️ **The second one is what protects this package specifically.** Every property here is camelCase
now, so a camel input mapper is the *identity* on them — meaning **deleting a `#[MapName]` moves that
field's published key silently**, and the mapper-rewrites test cannot see it. The partial-declaration
check can, because every multi-word property in these classes declares one. Measured 2026-08-28:
removing a single `#[MapName]` from `ProjectedEventData` produced **no finding at all** until that
check existed, and one finding naming the field afterwards.

Neither instrument subsumes the other. A diff cannot judge a codebase it sees once; an audit cannot
know what a key used to be.

## Estate context

The wider cleanup is `api-surface-coherence` **issue 100** — 60 properties across 37 files that
are spelled snake to route around a defect closed on 2026-08-26. This package adopted that end
state directly rather than joining the sweep. The general "what should the wire vocabulary be"
question is parked at `schemastud/laravel-data-schemas` → `schema-method-naming` issue 01 and is
explicitly **not** settled by this file.
