# Bode_Tracing

OpenTelemetry **traces** for Magento 2: storefront, admin, REST and GraphQL requests, cron jobs, CLI commands,
indexers and Mview, cache flushes, queue consumer messages and outbound HTTP, plus log correlation (`trace_id` on every
Magento log line). General-purpose and vendor-neutral: everything shop-specific is configuration through environment
variables. A no-op (no errors, negligible cost) when `ext-opentelemetry` or the OpenTelemetry SDK is absent, so
development, CI and unit tests are unaffected.

It began as a copy of an MIT-licensed project (whose notice is kept) and was rebuilt; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
Metrics are in the sibling module `Bode_Metrics`, which depends on this one for its shared contracts.


## Installation

```bash
composer require bode/module-tracing
bin/magento module:enable Bode_Tracing
bin/magento setup:upgrade
```

## Requirements

- PHP 8.5 or newer, Magento 2.4.x.
- To record anything: `ext-opentelemetry` (PECL `opentelemetry`) and `open-telemetry/sdk`, `exporter-otlp`, `sem-conv`;
  `ext-protobuf` is recommended for OTLP over http/protobuf. Without them the module registers nothing.
- Hooks are registered when Composer loads `registration.php`, i.e. on every PHP process, before Magento runs. That works
  in production mode with compiled DI: hooks attach to the real classes, not to generated `Interceptor` subclasses.

## What is traced

| Group (default) | Spans |
|---|---|
| `http` (on) | One SERVER root span per request in every area, named `<METHOD> <resource>`. Magento has no declared route table for storefront/admin/CMS pages, so those are named after the resolved route/controller/action (`CatalogProductController#view`), the same way Datadog names resources `Controller#action` for frameworks without one. REST (`/v1/products/:id`) and GraphQL (`graphql <operation>`) do have a declared route table, so they keep a route template and are the only two that also set the `http.route` attribute. A full page cache hit has no controller yet, so it falls back to a best-effort template of the raw path. `code.namespace` (the real PHP controller/service class) and `bode.magento.action` (the resolved action name) are set wherever a controller or REST route resolves; `bode.magento.route_resolution=noroute` flags a request Magento forwarded to its built-in 404 handler. Honours an incoming `traceparent`. |
| `cron` (on) | One root span per job (`cron <job_code>`), with group, final status, error type; Schedule status changes are span events. |
| `cli` (on) | `cli <command>`, with the exit code. Not for `cron:run` and `queue:consumers:start`, which run for hours. |
| `indexer` (on) | `indexer <id> reindexAll\|reindexList\|reindexRow`, `mview <id> update`. |
| `cache` (on) | `cache flush\|clean` with the cache types, page cache clean. |
| `queue` (on) | One root span per consumed message (`queue <topic>`), with consumer and outcome. |
| `outbound` (on) | Guzzle calls as `HTTP <METHOD>`, with host and status; `traceparent` is sent along. |
| `sql` (off) | Database queries: verb, bounded table, obfuscated text. |
| `events` (off) | Dispatched Magento events (name only). |
| `redis` (off) | Redis cache backend commands (command only). |
| `eav`, `business` (off) | Reserved names. Upstream's entity, pricing, totals and repository hooks were not carried over. |

Request spans carry: area, store and website code, `enduser.id` (customer id, or admin user id), customer group or admin
role (`bode.magento.admin.role`), a keyed hash of the session id, `client.address`, the configured correlation headers,
truncated user agent, status class and `error.type` for 5xx. Admin saves, deletes, cache flushes and logins are span
events (`admin.audit`): who (id), entity type, action, never field values.

## Never recorded

Query strings are never recorded, and `http.route`/the span name are always a bounded template, never the raw URL. The
literal, query-free path (`url.path`) is recorded on every request, for every area, so a trace can still be traced back
to the exact page; it is never used to build `http.route` or the span name (storefront/admin/CMS pages carry slugs,
ids and one-time keys that must never become part of a low-cardinality name). Also never recorded: request and
response bodies, GraphQL queries and variables, resolver values, SQL literals and bind values, e-mail addresses,
names, the raw session id, exception messages and traces (only the exception class), cache keys and tags, queue
message bodies.

## Configuration (environment variables)

| Variable | Default | Meaning |
|---|---|---|
| `MAGENTO_OTEL_ENABLED` | `true` | `false` switches everything off |
| `MAGENTO_OTEL_TRACING_GROUPS` | `http,cron,cli,indexer,cache,queue,outbound` | Groups on. `all` enables every group, `-name` subtracts from the defaults, `http,sql` is an explicit list |
| `MAGENTO_OTEL_EXCLUDED_PATHS` | `health_check.php,/health,/static/,/media/,cron.php,get.php` | Request path fragments that are never traced |
| `MAGENTO_OTEL_SPAN_CAP` | `500` | Spans per request, cron job or queue message; the rest are counted as dropped, and the counter resets for the next unit of work |
| `MAGENTO_OTEL_SPAN_CAP_GROUPS` | none | Per-group caps inside the global cap, e.g. `entities:150,sql:200,events:100`. Invalid entries are ignored; groups without an entry are bound only by `MAGENTO_OTEL_SPAN_CAP`. No defaults until real span-volume data exists |
| `MAGENTO_OTEL_SPAN_COLLAPSE_AFTER` | `0` (off) | `N>0`: after N spans from the same hook under the same parent span, further ones are not started and are counted as collapsed |
| `MAGENTO_OTEL_IP_MODE` | `truncate` | `full`, `truncate` (/24, /48), `hash`, `off` |
| `MAGENTO_OTEL_USER_MODE` | `id` | `id`, `hash`, `off` (never e-mail or name) |
| `MAGENTO_OTEL_HASH_SECRET` | none | Secret for the keyed hashes (session id, and IP/user in `hash` mode); without it they record nothing |
| `MAGENTO_OTEL_CORRELATION_HEADERS` | none | Comma separated request headers recorded as `http.request.header.<name>` |
| `MAGENTO_OTEL_FORWARDED_HEADER`, `MAGENTO_OTEL_TRUSTED_PROXIES` | none | Opt-in: take the client address from this header, but only when the direct peer is in the list (addresses or CIDR). Off by default: the address the web server already resolved (`REMOTE_ADDR`) is used |
| `MAGENTO_OTEL_DEBUG` | unset | `true` writes swallowed instrumentation errors to the PHP error log |

Sampling and the exporter use the standard `OTEL_*` variables (`OTEL_TRACES_SAMPLER`, `OTEL_EXPORTER_OTLP_*`, ...); the
module has no sampler of its own. The admin area is resolved from Magento itself (the area code), never from a constant.

### Privacy

`MAGENTO_OTEL_IP_MODE=full` and `MAGENTO_OTEL_USER_MODE=id` send personal data to your telemetry backend. The module
defaults are conservative (`truncate`, `id`); choosing `full` is a decision about region, retention and a data
processing agreement that belongs to the operator, not to this module.

## Log correlation

A Monolog processor, registered in `etc/di.xml` on `Magento\Framework\Logger\Monolog`, adds `trace_id`, `span_id` and
`trace_flags` to the record's `extra`. In Magento's default line format they end the line:

```
[2026-11-04T13:45:23.967357+00:00] main.ERROR: payment declined {"order":"x"} {"trace_id":"0af7...","span_id":"26...","trace_flags":"01"}
```

Records written outside a span get no ids. For structured JSON lines instead of this text format, with the ids at
the top level and the exception as data, install `bode/module-logs`, which reuses the span lookup of this module
(`Api\TraceContextProviderInterface`). To ship the lines to Loki and keep multi-line stack traces as one entry, see
the Alloy pipeline of the project that uses this module; a derived field with the regex `"trace_id":"([0-9a-f]{32})"`
links a line to its trace in Grafana.

## How it works (for contributors)

- `Hook\Runtime` boots once per process from `registration.php`, builds the `Instrumenter`, and registers every
  `Instrumentation\*` class. `Hook\HookRegistrarInterface` wraps `OpenTelemetry\Instrumentation\hook()`, so everything
  above it is unit-tested without the extension (`Test/Unit/Hook`).
- `Instrumenter::span()` is the only place that turns "start a span before, end it after" into hooks. It applies the
  group switch and the per-unit `SpanBudget`, keeps pre and post balanced with a `SpanStack` (a post hook never ends a
  span that is not its own), and catches, counts and swallows every failure (`ErrorCounter`, visible as
  `bode.telemetry.errors` in `Bode_Metrics`, logged with `MAGENTO_OTEL_DEBUG=true`).
- Code that needs dependency injection (sessions, store, admin user) cannot live in a static hook: the
  `Plugin\EnrichRequestSpan` after-plugin and the `Observer\AdminAudit` observer reach the current span through
  `Api\TelemetryStateInterface`.
- Hook targets are Magento internals (for example `Consumer::dispatchMessage`, `ProcessCronQueueObserver::tryRunJob`). A
  Magento upgrade can rename one; a hook on a missing method does nothing. Check after upgrades.

## Tests

`composer test` in the monorepo root (also run in CI: the unit tests need no Magento). `harness/` in the monorepo
runs every scenario end to end with the real extension against Tempo.

## Span volume

Three controls keep one noisy page from using the whole budget, checked in this order for every span: the global
`MAGENTO_OTEL_SPAN_CAP`, the group cap (`..._SPAN_CAP_GROUPS`), then repeat collapsing (`..._SPAN_COLLAPSE_AFTER`).
A refused span consumes nothing, the hook pair stays balanced (a null placeholder is pushed and popped), and the
counters reset at every unit of work. "Same span" for collapsing means the same hooked class and method under the same
parent span (the span name is only known after it is started). The root span gets `bode.magento.spans.dropped`,
`bode.magento.spans.collapsed` and, only for groups that have a configured cap, `bode.magento.spans.dropped.<group>`
(non-zero values only). A `repeat_count` attribute on the first sibling is not emitted: that span has usually ended
before the repeats occur. Roots of other kinds can write the same attributes from `SpanBudget::summary()`.
