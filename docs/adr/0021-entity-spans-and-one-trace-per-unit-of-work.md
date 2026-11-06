# ADR 0021: Entity spans, and one trace per unit of work

Status: proposed. Semver impact: `feat:` (minor). Implemented by rails A (trace integrity), B (entity spans), C (per-group
caps and collapse); verified by `harness/` (tree scenarios, `harness/expectations.php`), `bin/e2e entities` and the
benchmark in `e2e/bench/`; rolled out by [docs/canary-runbook.md](../canary-runbook.md).

## Context

Operators want to see what a request spends its time on in Magento's data layer: which entity loads and saves, which
collections, with the SQL and Redis calls each one made. Today the opt-in groups `events`, `sql` and `redis` emit flat
child spans, and the packages have no span for an entity (EAV or flat-table) or a collection.

Two problems stand in the way.

1. **A child span with nothing above it starts a trace of its own.** `SpanStarter::start()` takes the current context as
   parent; with no current span the parent is empty and the SDK mints a new trace id. An `events` span dispatched before
   `Bootstrap::run`, after it, on an excluded path (`/static/`, `/health`) or in a process nobody instrumented becomes a
   one-span trace. With entity and SQL spans switched on this multiplies: every stray span is a trace in Tempo that
   no query ever finds, costs a trace in storage and pollutes trace counts, and a lone `SELECT` is no use to anyone.
   (Observed in the harness at the time of writing: `harness/run.sh scenarios` + `verify` shows the one-span traces for
   `events-bootstrap` and `event-excluded-path`, and an `event` span as the root of a cron job's trace.)
2. **Volume.** One category page can load hundreds of entities, each with queries. The per-trace span cap (500)
   protects the backend but cuts the tree wherever it happens to be reached, and says nothing of what was cut.

## Decision

1. **Roots are only HTTP requests, cron jobs, queue messages and CLI commands.** Those are the units of work
   (`rootOfUnit` in `Instrumenter::span`). Every other span is a child.
2. **A child-only span without a root is dropped and counted; it never starts a trace.** `Instrumenter` checks that a
   root of the unit is open before it asks the handler for a span; if none is, the hook does nothing observable,
   increments a counter by group (the name is rail A's; this ADR calls it "dropped without root") and returns. The counter
   is exposed next to the existing `telemetry.errors`. Indexer and cache spans started outside any unit are dropped the
   same way; the units they normally belong to (`cli`, `cron`, `http`) are the roots.
3. **Entity spans** live in a new `entities` group: `entity load|save|delete <type>` for resource models
   (`AbstractDb`) and EAV entities (`AbstractEntity`), `collection load <type>` for collections. The type is the entity
   type or table, never an id, SKU or value. Groups `eav` and `business` become aliases of `entities`; `data` is
   shorthand for `entities,sql,redis`. SQL and Redis spans issued while an entity span is open are its children.
4. **Bounded by group.** `MAGENTO_OTEL_SPAN_CAP_GROUPS` caps spans per group per unit; past
   `MAGENTO_OTEL_SPAN_COLLAPSE_AFTER` consecutive similar spans collapse into one summary span with a count. What was
   dropped or collapsed is counted. The overall cap stays.
5. **Unchanged:** the default groups, every name and attribute emitted today, the privacy contract (no values, no query
   strings, no SQL literals, no cache keys). A duration filter ("only spans slower than N ms") is deliberately not part of
   this decision: it needs the span to be started and ended before it is kept, which conflicts with decision 2's cheap
   check, and is deferred.

## Alternatives considered

- **Start a fallback root for an orphan span** (a "background" trace named after the first span). Rejected: that is the
  current behaviour with a nicer label, and it is what produces the stray traces. Nothing links such a trace to a unit of
  work, so it cannot be explained.
- **Attach orphans to the last known trace** (a global or "ambient" context). Rejected: wrong in long-running processes
  (consumers, `cron:run`) and under any concurrency; it would hide the bug by producing wrong trees.
- **Span links instead of parents** for work done outside a unit. Rejected for now: links need a trace to point at, and the
  case here is precisely that there is none.
- **Tail sampling in the collector to drop one-span traces.** Cheap for the application but pays the export and
  collector cost for every dropped span, is configuration the module cannot enforce, and cannot work without a collector.
- **Only raise the span cap.** Does not address orphans and makes volume worse.

## Consequences

- Dashboards and alerts built on span names and attributes keep working: nothing emitted today changes name or labels.
  The entity span names and `bode.`-prefixed attributes are new, and from now on part of the contract (renaming one later
  is a major release).
- Some spans that exist today disappear: events dispatched outside any request, job or command, indexer and cache spans
  outside one. Anything that relied on them as traces of their own (searching Tempo for a cache flush started by hand)
  should use the CLI group instead. This is a behaviour change in opt-in groups and in two default ones; it is
  released as `feat:` because the only thing it removes is output that was a defect (orphan one-span traces), and it is
  called out in the release notes.
- `entities` costs two hook invocations per entity operation. The canary runbook defines the measured budget (p95
  latency, spans per request) before `data` is advised for anyone; `entities` is off by default.
- Every trace is now a tree with exactly one root per unit: `harness/verify.php` and `bin/e2e assert trace-integrity`
  check it by `spanId`/`parentSpanId`, and fail the build when a parentless non-root span appears.
- A new counter (dropped without root) and the collapse counters need dashboards and the canary's go/no-go thresholds.
- Semver: `feat:`, minor. Only if a release also renames or removes an existing attribute does it become `feat!:`.