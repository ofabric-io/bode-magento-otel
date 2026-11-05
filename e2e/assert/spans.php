<?php

declare(strict_types=1);

require __DIR__ . '/../lib/otlp.php';

/**
 * Checkpoint span assertions: the two "home" scenario requests prove the new route-naming model survives a real
 * Magento (not just the stub classes in harness/run.php):
 *  - home-1 (first, uncached): the root span is named after the resolved route/controller/action
 *    (CatalogProductController#view-style), never "GET unknown" - and, since storefront has no declared route
 *    table, http.route is NOT set at all (OpenTelemetry HTTP spec: "if the route cannot be determined, http.route
 *    MUST NOT be set").
 *  - home-2 (second, full-page-cache hit): no controller resolves before the cache answers, so the root span falls
 *    back to a best-effort template of the raw path ("GET /" for the homepage), flagged with
 *    bode.magento.page_cache=hit - not the old "GET page_cache_hit"/http.route=page_cache_hit sentinel.
 */

$spans = loadSpans('/output/traces.jsonl');

/**
 * The HTTP root span of a trace. Unlike the old contract, http.route is not set for every area any more (only REST
 * and GraphQL have a declared route table), so the root span is identified by the two attributes HttpInstrumentation
 * sets on every area unconditionally instead: http.request.method and url.path (set once in begin(), before any
 * route resolves).
 */
function httpRootSpansForTrace(array $spans, string $traceId): array
{
    return array_values(array_filter(
        $spans,
        static fn (array $span): bool => $span['traceId'] === $traceId
            && array_key_exists('http.request.method', $span['attributes'])
            && array_key_exists('url.path', $span['attributes'])
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

// home-1: a real, uncached dispatch through Magento's default CMS home route (cms/index/index). No declared route
// table exists for storefront pages, so http.route must not be set at all - only REST and GraphQL set it.
if ($root1['name'] === 'GET unknown' || str_contains((string) $root1['name'], 'unknown')) {
    fail(sprintf('home-1 (first, uncached hit) never got a resolved route: name=%s', $root1['name']));
}
if ($root1['name'] !== 'GET CmsIndexController#index') {
    fail(sprintf(
        'home-1 root span is named "%s", expected "GET CmsIndexController#index" (Magento\'s default CMS home route)',
        $root1['name']
    ));
}
if (array_key_exists('http.route', $root1['attributes'])) {
    fail(sprintf(
        'home-1 carries http.route="%s", but storefront has no declared route table: http.route must not be set',
        $root1['attributes']['http.route']
    ));
}
pass(sprintf('home-1 root span resolved a real route, with no http.route attribute: %s', $root1['name']));

// home-2: the full page cache answers before any controller resolves, so the root span falls back to a best-effort
// template of the raw request path, same as a real URL would produce via RouteNormalizer::restTemplate() - the
// homepage's path is just "/".
if ($root2['name'] !== 'GET /') {
    fail(sprintf('home-2 (second, full-page-cache hit) root span is named "%s", expected "GET /"', $root2['name']));
}
if (($root2['attributes']['bode.magento.page_cache'] ?? null) !== 'hit') {
    fail(sprintf(
        'home-2 bode.magento.page_cache is "%s", expected "hit"',
        $root2['attributes']['bode.magento.page_cache'] ?? '(missing)'
    ));
}
if (array_key_exists('http.route', $root2['attributes'])) {
    fail(sprintf(
        'home-2 carries http.route="%s", but a cache hit has no declared route either: http.route must not be set',
        $root2['attributes']['http.route']
    ));
}
pass('home-2 root span is named "GET /" (not "GET unknown"/"GET page_cache_hit") with bode.magento.page_cache=hit');

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
