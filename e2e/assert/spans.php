<?php

declare(strict_types=1);

require __DIR__ . '/../lib/otlp.php';

/**
 * Checkpoint span assertions: the two "home" scenario requests prove the page-cache-hit naming fix survives a real
 * Magento (not just the stub classes in harness/run.php) — the second, cache-answered request must be named
 * "GET page_cache_hit" with http.route=page_cache_hit, not "GET unknown".
 */

$spans = loadSpans('/output/traces.jsonl');

/**
 * The scenario sends a traceparent header carrying a fake remote parent span id, so Magento's HTTP root span is a
 * real OTel *child* of that injected parent (correct W3C trace-context propagation) - not an orphan with an empty
 * parentSpanId. It is still "the" span of interest per trace here: identify it by the http.route attribute, which
 * only the HTTP root span carries.
 */
function httpRootSpansForTrace(array $spans, string $traceId): array
{
    return array_values(array_filter(
        $spans,
        static fn (array $span): bool => $span['traceId'] === $traceId && array_key_exists('http.route', $span['attributes'])
    ));
}

require __DIR__ . '/../lib/trace.php';

$trace1 = traceIdFor('home-1');
$trace2 = traceIdFor('home-2');

$roots1 = httpRootSpansForTrace($spans, $trace1);
$roots2 = httpRootSpansForTrace($spans, $trace2);

if (count($roots1) !== 1) {
    fail(sprintf('expected exactly one HTTP root span for trace %s (home-1), found %d', $trace1, count($roots1)));
}
if (count($roots2) !== 1) {
    fail(sprintf('expected exactly one HTTP root span for trace %s (home-2), found %d', $trace2, count($roots2)));
}

$root1 = $roots1[0];
$root2 = $roots2[0];

if ($root1['attributes']['http.route'] === 'unknown' || $root1['name'] === 'GET unknown') {
    fail(sprintf(
        'home-1 (first, uncached hit) never got a resolved route: name=%s http.route=%s',
        $root1['name'],
        $root1['attributes']['http.route'] ?? '(missing)'
    ));
}
pass(sprintf('home-1 root span resolved a real route: %s', $root1['name']));

if ($root2['name'] !== 'GET page_cache_hit') {
    fail(sprintf('home-2 (second, full-page-cache hit) root span is named "%s", expected "GET page_cache_hit"', $root2['name']));
}
if (($root2['attributes']['http.route'] ?? null) !== 'page_cache_hit') {
    fail(sprintf(
        'home-2 http.route is "%s", expected "page_cache_hit"',
        $root2['attributes']['http.route'] ?? '(missing)'
    ));
}
pass('home-2 root span is named "GET page_cache_hit" (not "GET unknown") with http.route=page_cache_hit');

// No PII in any attribute of either trace, as a defensive general check (home has none on purpose; other scenarios
// carry the scenarios that actually try to leak an email/token/query string).
$forbidden = ['q=widget', 'email=', 'utm_source=', 'token=', '@example.com', 'searchCriteria'];
foreach (array_merge($roots1, $roots2) as $span) {
    foreach ($span['attributes'] as $key => $value) {
        $haystack = is_string($value) ? $value : json_encode($value);
        foreach ($forbidden as $needle) {
            if (is_string($haystack) && str_contains($haystack, $needle)) {
                fail(sprintf('span attribute %s="%s" contains forbidden substring "%s"', $key, $haystack, $needle));
            }
        }
    }
}
pass('no forbidden substrings (query string markers, email, searchCriteria) in the home traces');

echo "spans: all checkpoint assertions passed\n";
