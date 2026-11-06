<?php

declare(strict_types=1);

require_once __DIR__ . '/otlp.php';
require_once __DIR__ . '/trace.php';

/**
 * Trace-tree helpers for the all-groups assertions (trace-integrity, entities). Spans come from loadSpans();
 * everything links through spanId / parentSpanId, never through timing or names.
 */

/** Service name of the web server the `entities` scenarios run against (see `bin/e2e entities`). */
const ENTITIES_SERVICE = 'bode-e2e-entities';

/** Scenarios of the all-groups run; each sends the deterministic traceparent of traceparentFor(<name>). */
const ENTITIES_SCENARIOS = [
    'entities-product',
    'entities-category',
    'entities-home',
    // Where compiled DI's generated Interceptors and Proxies sit in the call chain: login page, GraphQL, POST /V1/products.
    'wrap-admin-login',
    'wrap-graphql',
    'wrap-token',
    'wrap-product-save',
];

/** @return list<array> the spans exported by one service */
function spansOfService(array $spans, string $service): array
{
    return array_values(array_filter(
        $spans,
        static fn (array $s): bool => ($s['resourceAttributes']['service.name'] ?? '') === $service
    ));
}

/** @return array<string,array<string,array>> traceId => spanId => span */
function groupByTrace(array $spans): array
{
    $traces = [];
    foreach ($spans as $span) {
        $traces[$span['traceId']][$span['spanId']] = $span;
    }

    return $traces;
}

/** Spans of one trace whose parent is not in the trace: the root, and orphans. @param array<string,array> $trace */
function topLevelSpans(array $trace): array
{
    return array_values(array_filter($trace, static fn (array $s): bool => !isset($trace[$s['parentSpanId']])));
}

/**
 * Whether "root > child > grandchild" exists in the trace: each segment an fnmatch() pattern on the span name, the
 * first matched against a top-level span, every next one against a direct child of the previous.
 *
 * @param array<string,array> $trace
 */
function pathExists(array $trace, string $path): bool
{
    $children = [];
    foreach ($trace as $span) {
        $children[$span['parentSpanId']][] = $span;
    }
    $walk = static function (array $candidates, array $segments) use (&$walk, $children): bool {
        $segment = array_shift($segments);
        foreach ($candidates as $candidate) {
            if (!fnmatch($segment, $candidate['name'])) {
                continue;
            }
            if ($segments === [] || $walk($children[$candidate['spanId']] ?? [], $segments)) {
                return true;
            }
        }

        return false;
    };

    return $walk(topLevelSpans($trace), explode(' > ', $path));
}

/**
 * A check that may be "expected red": $marker names the rail whose merge makes it pass (EXPECT_AFTER). A marked check
 * that fails is reported RED and does not fail the run unless E2E_STRICT_EXPECT=1; delete the marker once the rail
 * has merged. Unmarked failures exit 1.
 */
function check(string $what, bool $ok, ?string $marker = null): void
{
    if ($ok) {
        echo "ok    $what" . ($marker !== null ? "   [EXPECT_AFTER $marker already passes: remove the marker]" : '') . "\n";

        return;
    }
    if ($marker !== null && getenv('E2E_STRICT_EXPECT') !== '1') {
        echo "RED   $what   [EXPECT_AFTER $marker: expected red until rail $marker has merged]\n";
        $GLOBALS['expectedRed'] = ($GLOBALS['expectedRed'] ?? 0) + 1;

        return;
    }
    fail($what);
}
