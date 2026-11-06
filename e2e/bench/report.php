<?php

declare(strict_types=1);

require __DIR__ . '/../lib/otlp.php';

/**
 * Benchmark report: joins the latency lines of run.php (/output/bench/<mode>.jsonl) with the spans the Collector
 * received (/output/traces.jsonl) and writes /output/bench-results.json and /output/bench-results.md.
 *
 * Per mode and target: requests, HTTP 200 rate, p50 and p95 latency (nearest rank), spans per request (mean, max).
 * Per target, mode "on" against mode "off": the p50/p95 change in percent and the extra spans per request.
 * Verdict thresholds (the go/no-go numbers of docs/canary-runbook.md, overridable for experiments):
 *   BENCH_MAX_P95_DELTA_PCT   default 10   p95 of "on" may exceed "off" by at most this
 *   BENCH_MAX_SPANS           default 600  mean spans per request in "on" (span cap 500 plus root/summary allowance)
 *
 * Usage: php report.php [off-mode=off] [on-mode=on]    exit 1 when a threshold is exceeded.
 */

$off = $argv[1] ?? 'off';
$on = $argv[2] ?? 'on';
$maxDelta = (float) (getenv('BENCH_MAX_P95_DELTA_PCT') ?: 10);
$maxSpans = (int) (getenv('BENCH_MAX_SPANS') ?: 600);

$spanCount = [];
foreach (loadSpans('/output/traces.jsonl') as $span) {
    $spanCount[$span['traceId']] = ($spanCount[$span['traceId']] ?? 0) + 1;
}

/** @param list<float> $sorted */
function percentile(array $sorted, float $p): float
{
    if ($sorted === []) {
        return 0.0;
    }

    return $sorted[max(0, (int) ceil($p * count($sorted)) - 1)];
}

$stats = [];
foreach ([$off, $on] as $mode) {
    $file = "/output/bench/$mode.jsonl";
    if (!is_file($file)) {
        fwrite(STDERR, "missing $file: run bench for mode $mode first\n");
        exit(2);
    }
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) {
            $rows[$r['target']][] = $r;
        }
    }
    foreach ($rows as $target => $list) {
        $ok = array_filter($list, static fn (array $r): bool => $r['status'] === 200);
        $latency = array_column($ok, 'latency_ms');
        sort($latency);
        $spans = array_map(static fn (array $r): int => $spanCount[$r['trace_id']] ?? 0, $ok);
        $stats[$target][$mode] = [
            'requests' => count($list),
            'ok' => count($ok),
            'p50_ms' => percentile($latency, 0.50),
            'p95_ms' => percentile($latency, 0.95),
            'spans_mean' => $spans === [] ? 0.0 : round(array_sum($spans) / count($spans), 1),
            'spans_max' => $spans === [] ? 0 : max($spans),
        ];
    }
}

$pct = static fn (float $a, float $b): float => $b > 0 ? round(($a - $b) / $b * 100, 1) : 0.0;
$violations = [];
$result = [];
$md = "| target | mode | n | ok | p50 ms | p95 ms | spans/req | max spans |\n|---|---|---:|---:|---:|---:|---:|---:|\n";
foreach ($stats as $target => $byMode) {
    foreach ([$off, $on] as $mode) {
        $s = $byMode[$mode] ?? null;
        if ($s === null) {
            continue;
        }
        $md .= sprintf("| %s | %s | %d | %d | %.1f | %.1f | %.1f | %d |\n", $target, $mode, $s['requests'], $s['ok'], $s['p50_ms'], $s['p95_ms'], $s['spans_mean'], $s['spans_max']);
    }
    if (isset($byMode[$off], $byMode[$on])) {
        $delta = [
            'p50_delta_pct' => $pct($byMode[$on]['p50_ms'], $byMode[$off]['p50_ms']),
            'p95_delta_pct' => $pct($byMode[$on]['p95_ms'], $byMode[$off]['p95_ms']),
            'extra_spans_per_request' => round($byMode[$on]['spans_mean'] - $byMode[$off]['spans_mean'], 1),
        ];
        $byMode['delta'] = $delta;
        if ($delta['p95_delta_pct'] > $maxDelta) {
            $violations[] = "$target: p95 +{$delta['p95_delta_pct']}% exceeds $maxDelta%";
        }
        if ($byMode[$on]['spans_mean'] > $maxSpans) {
            $violations[] = "$target: {$byMode[$on]['spans_mean']} spans/request exceeds $maxSpans";
        }
        if ($byMode[$on]['ok'] < $byMode[$on]['requests'] || $byMode[$off]['ok'] < $byMode[$off]['requests']) {
            $violations[] = "$target: some requests did not return HTTP 200 (latency counts successful ones only)";
        }
    }
    $result[$target] = $byMode;
}
$md .= "\n";
foreach ($result as $target => $byMode) {
    if (isset($byMode['delta'])) {
        $d = $byMode['delta'];
        $md .= sprintf("- %s: p50 %+.1f%%, p95 %+.1f%%, %+.1f spans/request\n", $target, $d['p50_delta_pct'], $d['p95_delta_pct'], $d['extra_spans_per_request']);
    }
}
$md .= $violations === [] ? "\nVerdict: within thresholds (p95 <= +$maxDelta%, spans/request <= $maxSpans)\n" : "\nVerdict: NO-GO\n- " . implode("\n- ", $violations) . "\n";

file_put_contents('/output/bench-results.json', json_encode(['off' => $off, 'on' => $on, 'results' => $result, 'violations' => $violations], JSON_PRETTY_PRINT) . "\n");
file_put_contents('/output/bench-results.md', $md);
echo $md;
exit($violations === [] ? 0 : 1);
