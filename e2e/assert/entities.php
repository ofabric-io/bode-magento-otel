<?php

declare(strict_types=1);

require __DIR__ . '/../lib/tree.php';

/**
 * Entity spans on a real Magento with every group on (service ENTITIES_SERVICE, see `bin/e2e entities`):
 *
 *  - the product page and the category page produce sql and event spans, all inside their request's tree;
 *  - EXPECT_AFTER B: the "entities" group adds `entity load <type>` / `collection load ...` spans, and the queries a
 *    load issues are children of that load (`GET * > entity load catalog_product > SELECT catalog_product_entity`);
 *  - the span cap holds: a trace never exceeds MAGENTO_OTEL_SPAN_CAP (default 500) plus a small allowance for the
 *    root and collapse summaries;
 *  - nothing private: no SQL literal of the seed data, no query string in any attribute.
 *
 * Marked checks are expected red until the named rail merges; E2E_STRICT_EXPECT=1 makes them gate.
 * Usage: php entities.php [service-name]
 */

$service = $argv[1] ?? ENTITIES_SERVICE;
$traces = groupByTrace(spansOfService(loadSpans('/output/traces.jsonl'), $service));

$product = $traces[traceIdFor('entities-product')] ?? [];
$category = $traces[traceIdFor('entities-category')] ?? [];
if ($product === [] || $category === []) {
    fail('entities-product / entities-category traces missing: run the scenarios first (bin/e2e entities)');
}

$names = static fn (array $trace): array => array_column($trace, 'name');
$any = static fn (array $trace, string $pattern): bool => array_filter(
    $trace,
    static fn (array $s): bool => fnmatch($pattern, $s['name'])
) !== [];

foreach (['entities-product' => $product, 'entities-category' => $category] as $scenario => $trace) {
    check("$scenario: has sql spans (SELECT ...)", $any($trace, 'SELECT *'));
    check("$scenario: has event spans (event ...)", $any($trace, 'event *'));
    check("$scenario: root is GET *", pathExists($trace, 'GET *'));
    check("$scenario: " . count($trace) . ' spans, within the cap', count($trace) <= 600);
}

// Diagnostics for a failing run: the distinct non-SQL span names of each trace, so a wrong hook target or name is visible.
foreach (['entities-product' => $product, 'entities-category' => $category] as $scenario => $trace) {
    $distinct = array_values(array_unique(array_filter(
        $names($trace),
        static fn (string $n): bool => !str_starts_with($n, 'SELECT ') && !str_starts_with($n, 'redis ')
    )));
    echo "info  $scenario: " . count($distinct) . ' distinct non-SQL span names: ' . implode(' | ', array_slice($distinct, 0, 60)) . "\n";
    $sql = array_values(array_unique(array_map(
        static fn (array $s): string => substr($s['name'], 0, 60),
        array_filter($trace, static fn (array $s): bool => str_starts_with($s['name'], 'SELECT '))
    )));
    echo "info  $scenario: sql: " . implode(' | ', array_slice($sql, 0, 40)) . "\n";
    foreach ($trace as $span) {
        if (str_starts_with($span['name'], 'GET ')) {
            echo "info  $scenario: root attributes: " . json_encode($span['attributes']) . "\n";
        }
    }
}

check('entities-product: has entity load catalog_product', $any($product, 'entity load catalog_product'));
check(
    'entities-product: GET * > entity load catalog_product > SELECT catalog_product_entity',
    pathExists($product, 'GET * > entity load catalog_product > SELECT catalog_product_entity'),
);
check('entities-category: has entity load catalog_category', $any($category, 'entity load catalog_category'));
check('entities-category: has a collection load span', $any($category, 'collection load*'));

// Privacy: the seeded values must never appear (sku/name of the seeded product, query string markers).
$forbidden = ['Secret Name', '?bench', 'bench=', 'utm_source', '@example.'];
foreach ([$product, $category] as $trace) {
    foreach ($trace as $span) {
        $blob = json_encode([$span['name'], $span['attributes']]);
        foreach ($forbidden as $needle) {
            if (str_contains((string) $blob, $needle)) {
                fail("span {$span['name']} contains forbidden \"$needle\"");
            }
        }
    }
}
pass('no seeded values or query strings in any span name or attribute');

echo 'entities: ' . ($GLOBALS['expectedRed'] ?? 0) . " expected-red check(s); all other checks passed\n";
