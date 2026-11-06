<?php

declare(strict_types=1);

require __DIR__ . '/../lib/tree.php';

/**
 * One trace per unit of work, no strays. Runs against the spans the all-groups web server exported
 * (service.name = ENTITIES_SERVICE, see `bin/e2e entities`, MAGENTO_OTEL_TRACING_GROUPS=all):
 *
 *  - every scenario request produced a trace under exactly the trace id its traceparent carried;
 *  - every span of the service belongs to one of those traces: no span started a trace of its own (a child-only span
 *    without a root: event, sql, entity, cache), no "stray" trace;
 *  - in each trace exactly one span has no parent inside the trace, and its parent is the remote span id the request
 *    carried: that is the root; every other span's parent is a span of the same trace (no parentless non-root span);
 *  - the root is an HTTP server span.
 *
 * Usage: php trace-integrity.php [service-name]   (default ENTITIES_SERVICE)
 */

$service = $argv[1] ?? ENTITIES_SERVICE;
$spans = spansOfService(loadSpans('/output/traces.jsonl'), $service);
if ($spans === []) {
    fail("no spans exported by service $service: did the all-groups web server run? (bin/e2e entities)");
}
$traces = groupByTrace($spans);
pass(sprintf('%d spans in %d traces from %s', count($spans), count($traces), $service));

$known = [];
foreach (ENTITIES_SCENARIOS as $scenario) {
    $known[traceIdFor($scenario)] = $scenario;
}

foreach ($known as $traceId => $scenario) {
    check("$scenario: the request produced a trace under its own trace id", isset($traces[$traceId]));
}

$strays = array_diff_key($traces, $known);
$strayNames = [];
foreach ($strays as $trace) {
    foreach ($trace as $span) {
        $strayNames[$span['name']] = true;
    }
}
check(
    'no stray traces: every span is in a trace a scenario started'
        . ($strays ? ' (' . count($strays) . ' strays, e.g. ' . implode(', ', array_slice(array_keys($strayNames), 0, 5)) . ')' : ''),
    $strays === []
);

foreach ($known as $traceId => $scenario) {
    $trace = $traces[$traceId] ?? [];
    if ($trace === []) {
        continue;
    }
    $tops = topLevelSpans($trace);
    $orphans = array_values(array_filter(
        $tops,
        static fn (array $s): bool => $s['parentSpanId'] !== spanIdFor($scenario)
    ));
    check(
        "$scenario: no parentless non-root span"
            . ($orphans ? ' (found: ' . implode(', ', array_slice(array_column($orphans, 'name'), 0, 5)) . ')' : ''),
        $orphans === []
    );
    // Generated Interceptors/Proxies (core di.xml puts a plugin on AdapterInterface; the event manager is a lazy Proxy)
    // must not double a call, and a collection's own row-count read must not load it again: no SQL, event or collection
    // load span may sit directly under a span of the same name and, for SQL, the same statement.
    $byId = [];
    foreach ($trace as $span) {
        $byId[$span['spanId']] = $span;
    }
    $nested = [];
    foreach ($trace as $span) {
        $parent = $byId[$span['parentSpanId']] ?? null;
        if ($parent === null || $parent['name'] !== $span['name']) {
            continue;
        }
        $sameSql = ($parent['attributes']['db.query.text'] ?? '') === ($span['attributes']['db.query.text'] ?? '');
        if ($sameSql && (
            str_starts_with($span['name'], 'event ')
            || str_starts_with($span['name'], 'collection load')
            || isset($span['attributes']['db.query.text'])
        )) {
            $nested[$span['name']] = ($nested[$span['name']] ?? 0) + 1;
        }
    }
    check(
        "$scenario: no SQL, event or collection span nested inside a same-name span"
            . ($nested ? ' (found: ' . json_encode(array_slice($nested, 0, 5)) . ')' : ''),
        $nested === []
    );
    $sqlSpans = count(array_filter($trace, static fn (array $s): bool => isset($s['attributes']['db.query.text'])));
    $eventSpans = count(array_filter($trace, static fn (array $s): bool => str_starts_with($s['name'], 'event ')));
    $collectionSpans = count(array_filter($trace, static fn (array $s): bool => str_starts_with($s['name'], 'collection load')));
    echo "info  $scenario: $sqlSpans sql spans, $eventSpans event spans, $collectionSpans collection spans\n";
    $roots = array_values(array_filter($tops, static fn (array $s): bool => $s['parentSpanId'] === spanIdFor($scenario)));
    check("$scenario: exactly one root, child of the remote parent (found " . count($roots) . ')', count($roots) === 1);
    if (count($roots) === 1) {
        check(
            "$scenario: the root is the HTTP request span ({$roots[0]['name']})",
            array_key_exists('http.request.method', $roots[0]['attributes'])
        );
    }
}

echo 'trace-integrity: ' . ($GLOBALS['expectedRed'] ?? 0) . " expected-red check(s); all other checks passed\n";
