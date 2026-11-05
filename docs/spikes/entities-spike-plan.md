# Spike plan: cost and value of the `entities` tracing group

Measurement protocol for the benchmark runner (Rail D). Question: is `entities` (and the composite `data` =
`entities,sql,redis`) cheap enough to recommend, and does it make traces readable?

## Variants

| Id | `MAGENTO_OTEL_TRACING_GROUPS` |
|---|---|
| off | unset (defaults) |
| entities | `http,cron,cli,indexer,cache,queue,outbound,entities` |
| entities+sql | the line above plus `sql` |

Same Magento 2.4.9 build, production mode, compiled DI, same sample data, `MAGENTO_OTEL_SPAN_CAP=500` (and once with
`5000` to see how much the cap hides), sampler `always_on`, a local OTLP collector that discards data (so exporter cost
is constant across variants). Warm opcache first. Measure with the full page cache bypassed (cache-miss path), which is
where entity loads happen.

## Scenarios

1. Product page (`catalog/product/view`).
2. Category page with default pagination.
3. Order save (place an order, or `OrderRepository::save`).
4. Admin order grid (`sales_order_grid` listing request).

## Per scenario and variant

- N = 200 requests after 20 warm-up requests, sequential, one worker, identical request.
- Spans per request: total, dropped by the cap, count by name prefix (`entity load`, `entity save`, `collection load`,
  SQL verbs), maximum nesting depth.
- Latency: p50 and p95 of server wall time, from the root span and from the client (the latter includes export cost).
- Overhead = (variant - off) / off for p50 and p95; absolute cost per span = delta p50 / extra spans per request.
- Peak PHP memory per request.
- Repeat the whole run 3 times, interleaving variants; report the median run and the spread.

## Readability check (qualitative)

For each scenario open one trace and note: do SQL spans sit under an entity or collection span; is the same object ever
shown twice (re-entrancy guard failing on a real EAV stack); are names low-cardinality (distinct span names over the 200
requests must be bounded by the number of tables and entity types, not by the number of requests).

## Acceptance suggestion

Recommend `entities` when p50 overhead is <= 5% and p95 <= 10% on all four scenarios and fewer than 5% of requests hit
the span cap at 500. Otherwise keep it a debugging switch and say so in the README.

## What the e2e harness needs

- A hook-target existence assertion: every class and method in the package README hook list exists in Magento 2.4.9
  (a hook on a missing method silently does nothing).
- A scenario that, with `entities` on, loads a product (EAV), a product collection and saves an order/customer; assert
  one `entity load catalog_product` span per product (not two) and SQL spans parented by entity/collection spans.

## Status

Not executed. No real Magento run was done for this plan; the numbers must come from the runner.
