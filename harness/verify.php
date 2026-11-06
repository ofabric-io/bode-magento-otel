<?php

declare(strict_types=1);

/**
 * Prints every span Tempo holds for the harness service and checks the trace trees.
 *
 *  1. Forbidden content (exit 1): no span may carry query strings, e-mail addresses, the raw URL, exception messages,
 *     cache keys or SQL literals.
 *  2. Trace trees per scenario (harness/expectations.php): number of traces, no orphan (non-root span whose parent is
 *     not in its trace), expected parent/child paths, names that must be absent, spans per trace. Spans are linked by
 *     spanId/parentSpanId, never by timing or names.
 *     A failing check that carries an EXPECT_AFTER marker is "expected red" until that rail has merged and does not
 *     fail the run, unless HARNESS_STRICT_EXPECT=1.
 *
 * Usage: php verify.php [tempo-url] [run-id]
 * run-id (default: harness/.last-run, written by `harness/run.sh scenarios`) limits the check to one run of the
 * scenarios: every scenario process tags its spans with resource attributes harness.run and harness.scenario.
 */

$tempo = $argv[1] ?? 'http://bode-lgtm:3200';
$run = $argv[2] ?? (is_file(__DIR__ . '/.last-run') ? trim((string) file_get_contents(__DIR__ . '/.last-run')) : '');
$strictExpect = getenv('HARNESS_STRICT_EXPECT') === '1';
$expectations = require __DIR__ . '/expectations.php';

$query = $run !== '' ? '{ resource.harness.run = "' . $run . '" }' : '{ resource.service.name = "bode-harness" }';
// Tempo makes traces searchable a little after the exporting processes end: poll until the number of traces is stable.
$search = [];
$previous = -1;
for ($attempt = 0; $attempt < 12; $attempt++) {
    $search = json_decode((string) file_get_contents($tempo . '/api/search?limit=1000&q=' . rawurlencode($query)), true) ?: [];
    $count = count($search['traces'] ?? []);
    if ($count > 0 && $count === $previous) {
        break;
    }
    $previous = $count;
    sleep(3);
}
$bad = [];
$rows = [];
/** @var array<string,array<string,array<string,array>>> $byScenario scenario => traceId => spanId => row */
$byScenario = [];
$legacyOrphans = [];

$value = static fn (array $v) => reset($v);
$attributes = static function (array $list) use ($value): array {
    $out = [];
    foreach ($list as $a) {
        $out[$a['key']] = $value($a['value']);
    }

    return $out;
};
/** Tempo returns ids as hex or base64 depending on version. */
$hex = static fn (string $id): string => ($id === '' || preg_match('/^[0-9a-f]+$/i', $id) === 1)
    ? strtolower($id)
    : bin2hex((string) base64_decode($id, true));
$isRoot = static fn (array $attrs): bool => isset($attrs['http.request.method'])
    || isset($attrs['bode.magento.cron.job_code'])
    || isset($attrs['messaging.system'])
    || isset($attrs['bode.magento.cli.command']);

foreach ($search['traces'] ?? [] as $t) {
    $trace = json_decode((string) file_get_contents($tempo . '/api/traces/' . $t['traceID']), true);
    foreach ($trace['batches'] ?? [] as $batch) {
        $resource = $attributes($batch['resource']['attributes'] ?? []);
        $scenario = (string) ($resource['harness.scenario'] ?? '');
        // Every scenario of every run sends the same traceparent, so Tempo merges their spans into one trace id: keep only
        // the spans of the run being verified.
        if ($run !== '' && (string) ($resource['harness.run'] ?? '') !== $run) {
            continue;
        }
        foreach ($batch['scopeSpans'] ?? [] as $scope) {
            foreach ($scope['spans'] ?? [] as $span) {
                $attrs = $attributes($span['attributes'] ?? []);
                $events = array_map(static fn (array $e): string => $e['name'] . ' ' . json_encode(
                    array_column(array_map(static fn (array $x): array => ['k' => $x['key'], 'v' => reset($x['value'])], $e['attributes'] ?? []), 'v', 'k')
                ), $span['events'] ?? []);
                $row = [
                    'trace' => substr($t['traceID'], 0, 8),
                    'traceId' => $t['traceID'],
                    'spanId' => $hex($span['spanId'] ?? ''),
                    'parent' => $hex($span['parentSpanId'] ?? ''),
                    'name' => $span['name'],
                    'kind' => str_replace('SPAN_KIND_', '', (string) ($span['kind'] ?? '')),
                    'status' => $span['status']['code'] ?? 'UNSET',
                    'attrs' => $attrs,
                    'events' => $events,
                    'root' => $isRoot($attrs),
                    'scenario' => $scenario,
                ];
                $rows[] = $row;
                if ($scenario !== '') {
                    $byScenario[$scenario][$t['traceID']][$row['spanId']] = $row;
                }
                $blob = json_encode([$span['name'], $attrs, $events]);
                $needles = ['?utm', 'token=', '@example.com', 'secret detail', 'abcdef', 'searchCriteria', 'CONFIG_cache_key', 'entity_id = 1'];
                foreach ($needles as $needle) {
                    if (str_contains((string) $blob, $needle)) {
                        $bad[] = "{$span['name']} contains $needle";
                    }
                }
            }
        }
    }
}

usort($rows, static fn (array $a, array $b): int => [$a['trace'], $a['name']] <=> [$b['trace'], $b['name']]);
foreach ($rows as $r) {
    printf("%s  %-8s %-9s %s
", $r['trace'], $r['kind'], $r['status'], $r['name']);
    foreach ($r['attrs'] as $k => $v) {
        if (str_starts_with($k, 'bode.') || str_starts_with($k, 'http') || in_array($k, ['url.path', 'client.address', 'enduser.id', 'error.type', 'messaging.destination.name', 'process.exit.code', 'session.id'], true)) {
            printf("            %s = %s
", $k, $v);
        }
    }
    foreach ($r['events'] as $e) {
        printf("            event: %s
", $e);
    }
}
printf("
%d spans in %d traces
", count($rows), count($search['traces'] ?? []));

// ---- tree checks -------------------------------------------------------------------------------------------------

$failures = [];
$expectedRed = [];
$unexpectedlyGreen = [];
$report = static function (string $scenario, string $what, bool $ok, ?string $marker) use (&$failures, &$expectedRed, &$unexpectedlyGreen, $strictExpect): void {
    $label = "$scenario: $what";
    if ($ok) {
        echo "ok    $label
";
        if ($marker !== null) {
            $unexpectedlyGreen[] = "$label (EXPECT_AFTER $marker passes already: remove the marker)";
        }

        return;
    }
    if ($marker !== null && !$strictExpect) {
        echo "RED   $label   [EXPECT_AFTER $marker: expected red until rail $marker has merged]
";
        $expectedRed[] = "$label (rail $marker)";

        return;
    }
    echo "FAIL  $label
";
    $failures[] = $label;
};

/** Names of the path from the root: the spans of $spans matching every segment, parent to child. */
$pathExists = static function (array $spans, string $path): bool {
    $segments = explode(' > ', $path);
    $children = [];
    foreach ($spans as $s) {
        $children[$s['parent']][] = $s;
    }
    $roots = array_filter($spans, static fn (array $s): bool => !isset($spans[$s['parent']]));
    $walk = static function (array $candidates, array $segments) use (&$walk, $children): bool {
        $segment = array_shift($segments);
        foreach ($candidates as $c) {
            if (!fnmatch($segment, $c['name'])) {
                continue;
            }
            if ($segments === [] || $walk($children[$c['spanId']] ?? [], $segments)) {
                return true;
            }
        }

        return false;
    };

    return $walk($roots, $segments);
};

foreach ($expectations as $scenario => $expect) {
    $traces = $byScenario[$scenario] ?? [];
    $flat = array_merge([], ...array_values($traces));
    [$count, $marker] = $expect['traces'];
    $report($scenario, "exactly $count trace(s), found " . count($traces), count($traces) === $count, $marker);

    if (array_key_exists('orphans', $expect)) {
        $orphans = [];
        foreach ($traces as $spans) {
            foreach ($spans as $s) {
                if (!$s['root'] && !isset($spans[$s['parent']])) {
                    $orphans[] = $s['name'];
                }
            }
        }
        $report($scenario, 'no parentless non-root span' . ($orphans ? ' (found: ' . implode(', ', array_slice($orphans, 0, 5)) . ')' : ''), $orphans === [], $expect['orphans']);
    }
    foreach ($expect['paths'] ?? [] as [$path, $marker]) {
        $found = false;
        foreach ($traces as $spans) {
            $found = $found || $pathExists($spans, $path);
        }
        $report($scenario, "tree has $path", $found, $marker);
    }
    foreach ($expect['absent'] ?? [] as [$pattern, $marker]) {
        $hits = array_filter($flat, static fn (array $s): bool => fnmatch($pattern, $s['name']));
        $report($scenario, "no span named $pattern", $hits === [], $marker);
    }
    // Generated Interceptors and Proxies must not double a call: no span may sit directly under a span of the same name
    // (and, for SQL, the same statement text). Real nesting of the same name (an event dispatched by an observer of the
    // same event) is not expected in these scenarios.
    $nested = [];
    foreach ($traces as $spans) {
        foreach ($spans as $s) {
            $parent = $spans[$s['parent']] ?? null;
            if ($parent !== null && $parent['name'] === $s['name']
                && ($parent['attrs']['db.query.text'] ?? '') === ($s['attrs']['db.query.text'] ?? '')) {
                $nested[$s['name']] = true;
            }
        }
    }
    $report($scenario, 'no span nested inside a same-name span' . ($nested ? ' (found: ' . implode(', ', array_slice(array_keys($nested), 0, 5)) . ')' : ''), $nested === [], null);
    foreach ($expect['counts'] ?? [] as $pattern => $want) {
        $got = count(array_filter($flat, static fn (array $s): bool => fnmatch($pattern, $s['name'])));
        $report($scenario, "exactly $want span(s) named $pattern, found $got", $got === $want, null);
    }
    if (isset($expect['max'])) {
        [$limit, $marker] = $expect['max'];
        $largest = $traces === [] ? 0 : max(array_map('count', $traces));
        $report($scenario, "at most $limit spans per trace, largest has $largest", $largest <= $limit, $marker);
    }
}

// Informational for every other scenario: parentless non-root spans (indexer and cache spans started without a unit of
// work are the known ones; roots only HTTP/cron/queue/cli is the rule from ADR 0021).
foreach ($byScenario as $scenario => $traces) {
    if (isset($expectations[$scenario])) {
        continue;
    }
    foreach ($traces as $spans) {
        foreach ($spans as $s) {
            if (!$s['root'] && !isset($spans[$s['parent']])) {
                $legacyOrphans[$scenario][$s['name']] = true;
            }
        }
    }
}
foreach ($legacyOrphans as $scenario => $names) {
    echo "info  $scenario: parentless non-root spans: " . implode(', ', array_keys($names)) . "
";
}

if ($unexpectedlyGreen !== []) {
    echo "
Markers that can go:
  " . implode("
  ", $unexpectedlyGreen) . "
";
}
if ($expectedRed !== []) {
    echo "
Expected red until the marked rail merges (" . count($expectedRed) . "):
  " . implode("
  ", $expectedRed) . "
";
}
if ($bad !== []) {
    fwrite(STDERR, "FORBIDDEN CONTENT:
  " . implode("
  ", $bad) . "
");
}
if ($failures !== []) {
    fwrite(STDERR, "TREE CHECKS FAILED:
  " . implode("
  ", $failures) . "
");
}
if ($bad !== [] || $failures !== []) {
    exit(1);
}
echo "no forbidden content found, tree checks passed" . ($expectedRed !== [] ? ' (apart from the expected red ones)' : '') . "
";
