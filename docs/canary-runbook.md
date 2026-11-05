# Canary runbook: entity spans (`data` group)

One hour on one production-like canary with `MAGENTO_OTEL_TRACING_GROUPS=data` plus the default groups on, then a
go/no-go. Background: [ADR 0021](adr/0021-entity-spans-and-one-trace-per-unit-of-work.md). Spike and canary use the same
protocol: `bin/e2e bench` locally (`e2e/bench/`), the same four targets on the canary.

`data` is shorthand for `entities,sql,redis`; `all` also switches on `events` and the rest. Set
`MAGENTO_OTEL_TRACING_GROUPS=http,cron,cli,indexer,cache,queue,outbound,data` (the defaults plus `data`).

## Before

1. Releases with rails A, B and C are deployed on the canary with the groups **unchanged**. Let it run one hour with the
   defaults: this is the baseline hour. Record, per route class (product page, category page, order placement, admin order
   grid): request p50 and p95, requests/s, PHP memory peak, CPU, spans/s received by the collector, spans per trace.
2. Run the benchmark against a staging copy and keep `e2e/.output/bench-results.md` with the ticket:
   `BENCH_ON_GROUPS=data,http,cron,cli,indexer,cache,queue,outbound bin/e2e bench` (entities off against on: spans per
   request and p50/p95 for product page, category page, order save, admin order grid). If it reports NO-GO, stop here.
3. Check the dashboards exist for the three counters below. Do not start without them.
4. Pick a rollback owner. Rollback is one environment variable (`MAGENTO_OTEL_TRACING_GROUPS` back to the defaults) and
   a PHP-FPM reload; nothing is migrated.

## During (one hour, same hour of the week as the baseline when possible)

Watch every 10 minutes, compared with the baseline hour at the same traffic:

| Signal | Where | Why |
|---|---|---|
| **Dropped without root** (child-only spans dropped, by group) | metric of rail A | Dropping is by design, but a rate that grows with traffic means a hook runs outside any unit of work that should have been one |
| **Orphans in Tempo**: traces whose root is not an HTTP/cron/queue/CLI span, and spans whose parent is missing | TraceQL (below) | Must be zero: dropped spans never start traces |
| **Collapsed** (spans folded into a summary, and group caps hit, by group) | metric of rail C | Tells whether caps are too tight (most traces collapse) or never reached |
| p50 and p95 per route class | RUM/APM, access log or the `http.server.request.duration` histogram | The cost of the feature |
| `telemetry.errors` | Bode_Metrics | Swallowed hook failures must not rise |
| Spans per trace (p50, p95, max) and spans/s at the collector | collector, Tempo | Backend load |
| PHP-FPM memory peak, CPU | host | Hooks hold state per unit |

Orphan query, spans that are not the root and have no parent in their trace (adapt to your Tempo version):

```
{ resource.service.name = "<your service>" && span:parentID = "" && kind != server && kind != consumer && name !~ "cron .*|.*cli.*" }
```

and by tree: traces with more than one root, `{ span:parentID = "" } | count() > 1`.

## Go / no-go

These numbers are the initial thresholds: the spike confirms or revises them, and the benchmark report
(`e2e/bench/report.php`) applies the first two. A no-go on any row is a no-go.

| Signal | Go | No-go |
|---|---|---|
| p95 per route class, canary hour vs baseline hour | within +5% | more than +10% on any class (+5..10% = hold the canary for another hour, then decide) |
| p50 per route class | within +5% | more than +10% |
| Orphans in Tempo (no root / more than one root per trace) | 0 | any |
| Dropped without root | below 1% of spans started, and flat as traffic changes | above 5% of spans started, or growing across the hour |
| Collapsed / cap hits | fewer than 5% of traces collapse; no trace above the span cap plus 60 | more than 20% of traces collapse (the caps are wrong for this shop: tune, run again) |
| Mean spans per request in `data` mode | at most 600 on every route class | above 600, or collector/Tempo ingest more than 3x the baseline hour |
| `telemetry.errors` | no increase over the baseline hour | any sustained increase |
| Errors and checkout conversion | unchanged | any regression attributable to the canary (stop at once, do not wait the hour) |

Stop immediately, without waiting for the hour, for: a rise in 5xx or failed orders, PHP-FPM out-of-memory kills, or a
p95 above +25%.

## After

- **Go:** write the numbers into the ticket (baseline hour next to canary hour, `bench-results.md`), widen to the next
  slice of traffic with the same watch, then document `data` as a supported preset.
- **No-go:** roll back, attach the same numbers, and file the failing row. Do not raise a threshold to pass; change the
  code or the caps and run the hour again.
- Either way remove nothing from the harness expectations: `harness/run.sh verify` and `bin/e2e entities` stay as the
  regression guards.