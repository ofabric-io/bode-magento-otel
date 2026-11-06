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
