# Standalone workflow action browser fixture

This test-only HTTP host loads the optional workflow package's Testbench providers and real
calendar particle routes. It installs no Tower/composition providers, resolves no application
credentials, and accepts only loopback clients. Do not mount it in a deployed host.

Install development dependencies for the sibling `splicewire/laravel-beam-workflows` checkout,
including its optional local calendars dependency. From `splicewire/laravel-beam-calendars`:

```sh
XDEBUG_MODE=off herd php -S 127.0.0.1:8767 tests/Browser/standalone-router.php
```

Override `BEAM_WORKFLOWS_FIXTURE_ROOT` if the workflow checkout is not a sibling. Override
`BEAM_CALENDAR_FIXTURE_DB` to use a new session-specific SQLite file; the default lives in the system
temporary directory. The server's port is the `php -S` argument. CORS permits
`http://localhost:6019`, the portable component Storybook origin. Keep it running while exercising
the actual portable form/detail UI; this fixture intentionally ships no duplicate frontend.

The managed subject is `subject_kind: article`, `subject_id: "1"`. Its definition permits `publish`
from draft to published and `unpublish` back to draft. Standard action and action-series operations
are mounted under `/api/beam`. Additional **fixture-only declared particle operations** provide:

| Endpoint | Input | Output inside `data` |
| --- | --- | --- |
| `GET /api/beam/calendar-actions/fixture-projection` | none | `WorkflowProjectionData` |
| `POST /api/beam/calendar-actions/{id}/fixture-run` | `due_at` offset ISO instant | `CalendarActionRecordData` |
| `POST /api/beam/calendar-actions/fixture-transition` | `transition` | `WorkflowTransitionAttemptData` |

`fixture-run` executes just that action at the supplied clock instant and captured current revision.
A before-due request remains pending. To exercise blocked recovery, schedule `publish`, call the
legal `publish` transition first, then run the scheduled action: it is blocked by current marking.
Call `unpublish`, use the real action `retry` operation with a new idempotency key, and run again.
The earlier blocked attempt remains in the detail response. The normal HTTP surface still enforces
revision conflicts, trusted context, pins and local transaction/receipt behavior.

For a CLI due sweep using the real current time:

```sh
XDEBUG_MODE=off herd php tests/Browser/standalone-router.php tick
```

Fixture context is intentionally fixed to `user:editor`, creator `user:creator`, and `tenant:test`.
Production hosts must bind their own authenticated context and authority. The fixture creates its
SQLite schema from real package migration stubs and uses the workflow test harness's definition
store/activity-log setup. Browser evidence belongs with the consumer's test run, not in this file.
