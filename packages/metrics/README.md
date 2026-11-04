# Bode_Metrics

OpenTelemetry **metrics** for Magento 2 with a hard low-cardinality contract: HTTP, cron, indexers, queues, cache,
outbound HTTP, database and Redis, a few commerce counters, and a collector that exports the state of cron, indexers,
Mview and the database queue. General-purpose: nothing shop-specific lives in the code, everything is configuration
through environment variables. A no-op (no errors, negligible cost) when `ext-opentelemetry` or the SDK is absent.

Depends on `Bode_Tracing` for its shared contracts (`Bode\Tracing\Api\...`: route normalisation, the
"is OpenTelemetry available" decision) and works with tracing switched off.

## Installation

```bash
composer require bode/module-metrics   # also installs bode/module-tracing, the same version
bin/magento module:enable Bode_Metrics Bode_Tracing
bin/magento setup:upgrade
```

## The cardinality rule

**No unbounded or per-user labels, ever.** Never a user, customer or admin id, IP, session, SKU, order id, raw path or
URL, query string, error message, schedule id or event name. Allowed attributes are bounded sets only: `http.route`
(Magento full action name, REST route template, GraphQL operation), method, status class (`2xx`..`5xx`), area, store
code, cron job code and group, indexer id, cache type, queue topic and consumer, payment method code, and outbound
hosts from an allow-list.

It is enforced in code, not by convention:

- `LabelPolicy` holds an allow-list of attribute keys **per instrument**. Anything else is dropped before it reaches
  the SDK. A second list of forbidden keys (user id, IP, session, URL, SKU, ...) can never be allowed, even by editing
  the first one. Tests pin both.
- `CardinalityGuard` collapses new values of an attribute into `other` after 100 distinct values **in one process**.
  That protects long-running processes (consumers, cron) and bounds a bug. It cannot see across processes: PHP-FPM
  workers are short-lived and each sees only its own requests. The real protection is the allow-list plus bounded
  sources, and a series limit in the collector (see the shop's deployment docs).

### Series budget

Estimated, not measured on a live shop. Prometheus stores one series per label combination, and a histogram stores
`buckets + 2` of them (9 explicit buckets, `+Inf` is included in the count, plus `sum` and `count`).

| Instrument | Label combinations (typical) | Series |
|---|---|---|
| `http.server.request.duration` | ~40 routes × ~1.6 methods and status classes (route fixes area) ≈ 65 | ≈ 720 |
| `magento.http.server.errors` | ~15 | 15 |
| `magento.cron.job.runs` / `.duration` | ~60 jobs × 3 statuses (duration: 60 × 11) | ≈ 840 |
| `magento.indexer.*` | 12 indexers × 3 statuses, runs, duration | ≈ 120 |
| `magento.mview.backlog`, `magento.queue.*` | ~20 views, ~10 queues × statuses | ≈ 100 |
| cache, outbound, commerce, telemetry | a few dozen | ≈ 100 |

Total about 1,900: right at the 2,000 target, not comfortably under it. Two levers if a shop exceeds it: drop
`http.request.method` from the HTTP histogram (set `MAGENTO_OTEL_METRICS_GROUPS` without `http` and keep the span-derived
metrics), or reduce the cron jobs that are traced. Measure your own number with
`count({__name__=~"http_server_.*|magento_.*|bode_.*"})` after a day of traffic.

## Instruments

| Instrument | Type | Attributes |
|---|---|---|
| `http.server.request.duration` | histogram, s | `http.route`, `http.request.method`, `http.response.status_class`, `area` |
| `magento.http.server.errors` | counter | `http.route`, `http.response.status_class`, `area` |
| `magento.cron.job.runs` | counter | `cron.job_code`, `cron.group`, `status` (`success`, `error`, `missed`) |
| `magento.cron.job.duration` | histogram, s | `cron.job_code`, `cron.group` |
| `magento.cron.oldest_pending_age` | gauge, s | `status` (`pending`, `missed`) |
| `magento.cron.jobs_stuck` | gauge | none |
| `magento.indexer.status` | gauge | `indexer_id`, `status` (`valid`, `invalid`, `working`; 1 for the current one) |
| `magento.indexer.runs` / `.duration` | counter / histogram | `indexer_id`, `mode`, `outcome` |
| `magento.mview.backlog` | gauge | `view_id` |
| `magento.queue.backlog` | gauge | `queue`, `status` |
| `magento.queue.messages` / `.message.duration` | counter / histogram | `topic`, `consumer`, `outcome` |
| `magento.cache.flushes` / `.operations` | counter | `cache_type` (, `operation`, `result`) |
| `magento.db.operation.duration` (opt-in) | histogram, s | `db.operation` (verb only, never a table) |
| `magento.redis.command.duration` (opt-in) | histogram, s | `db.operation` (command only) |
| `magento.outbound.http.calls` / `.duration` | counter / histogram | `server.address` (allow-list, else `other`), status class |
| `magento.orders.placed` | counter | `store`, `payment_method` |
| `magento.payments.failed` | counter | `payment_method` |
| `magento.emails` | counter | `outcome` (`sent`, `failed`) |
| `magento.admin.login.failures` | counter | none |
| `bode.telemetry.errors` / `.spans.dropped` | counter | `group` / none |

## The collector

`bin/magento bode:metrics:collect`, and the cron job `bode_metrics_collect` every minute, read the database and export
gauges: age of the oldest overdue pending and missed cron schedule, jobs stuck in `running` (longer than
`MAGENTO_OTEL_CRON_STUCK_MINUTES`, default 30), per-indexer status, Mview backlog per view (changelog version minus the
view's processed version) and database queue messages by status. Each reading is independent: a failing query is
skipped, the rest is exported.

There are deliberately no "last success" gauges: PHP-FPM and `cron:run` are short-lived processes and a gauge written
by one of them lies as soon as it exits. Ask Prometheus instead: `increase(magento_cron_job_runs_total{status="success"}[1h])`.

## Configuration (environment variables)

| Variable | Default | Meaning |
|---|---|---|
| `MAGENTO_OTEL_METRICS_ENABLED` | `true` | `false` switches the module off |
| `MAGENTO_OTEL_METRICS_GROUPS` | `http,cron,indexer,cache,queue,outbound,commerce,telemetry` | Groups on. Opt-in: `db`, `redis`, `cache_ops`. `all` enables everything |
| `MAGENTO_OTEL_OUTBOUND_HOSTS` | none | Hosts that keep their own series; every other host is `other` |
| `MAGENTO_OTEL_CRON_STUCK_MINUTES` | `30` | A job in `running` longer than this counts as stuck |

Standard `OTEL_*` variables configure the exporter. **PHP-FPM and `cron:run` are short-lived: use delta temporality**
(`OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta`) and convert to cumulative in the collector
(`deltatocumulative` in the OpenTelemetry Collector or Grafana Alloy), otherwise every process restarts its counters.

## Tests

`composer test` in the monorepo root (also run in CI: the unit tests need no Magento).
