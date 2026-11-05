<?php

declare(strict_types=1);

require __DIR__ . '/../lib/otlp.php';
// InstrumentCatalog and LabelPolicy have no dependencies outside their own namespace (plain constant tables), so
// they're required directly from the read-only /packages mount rather than duplicating their contents here: the
// allow-list this script enforces is the actual LabelPolicy the metrics module runs with, not a copy that could
// drift from it.
require '/packages/metrics/Model/InstrumentCatalog.php';
require '/packages/metrics/Model/LabelPolicy.php';

use Bode\Metrics\Model\InstrumentCatalog;
use Bode\Metrics\Model\LabelPolicy;

/**
 * Metrics assertions against a real Magento process's OTel SDK, read back from the Collector's file-exporter output:
 *  - the HTTP request-duration histogram exists and every data point's attributes are exactly a subset of
 *    LabelPolicy::DEFAULT_ALLOWED for that instrument - the hard low-cardinality contract (AGENTS.md section 5).
 *  - at least one cron, one indexer and one queue instrument reflects the cron-ok/cron-fail/indexer/queue scenarios
 *    having actually run (counters/histograms, emitted per run) - and, where present, the periodic collector's
 *    gauges (magento.cron.oldest_pending_age, magento.indexer.status, magento.mview.backlog, magento.queue.backlog,
 *    magento.cron.jobs_stuck) are checked too, but only as a soft "ok/skip", never a hard failure: those are written
 *    by `bode:metrics:collect` on its own minute-ly cron tick (see packages/metrics/README.md "The collector"), and
 *    a short e2e run has no guarantee that tick fired before assertions run.
 *  - no PII in any attribute value of any metric point.
 */

$points = loadMetricPoints('/output/metrics.jsonl');
if ($points === []) {
    fail(
        'no metric data points found in /output/metrics.jsonl - either no scenario has run yet, or the Collector '
        . 'file exporter / web-up is not configured correctly'
    );
}

/**
 * @param list<array{name:string,attributes:array,resourceAttributes:array}> $points
 * @return list<array{name:string,attributes:array,resourceAttributes:array}>
 */
function pointsNamed(array $points, string $name): array
{
    return array_values(array_filter($points, static fn (array $point): bool => $point['name'] === $name));
}

/**
 * Fails loudly, printing the exact unexpected keys found, if any data point of $instrument carries an attribute key
 * outside LabelPolicy::DEFAULT_ALLOWED[$instrument] (or one of the globally forbidden keys, belt and braces).
 *
 * @param list<array{name:string,attributes:array,resourceAttributes:array}> $points
 */
function assertOnlyAllowedAttributes(array $points, string $instrument): void
{
    $allowed = LabelPolicy::DEFAULT_ALLOWED[$instrument] ?? null;
    if ($allowed === null) {
        fail("$instrument has no entry in LabelPolicy::DEFAULT_ALLOWED - add one before shipping the instrument");
    }
    foreach ($points as $point) {
        $keys = array_keys($point['attributes']);
        $unexpectedAllowList = array_diff($keys, $allowed);
        $forbidden = array_intersect($keys, LabelPolicy::FORBIDDEN_KEYS);
        if ($unexpectedAllowList !== [] || $forbidden !== []) {
            fail(sprintf(
                '%s data point carries attribute key(s) outside the allow-list: %s (forbidden: %s) - full '
                . 'attributes: %s',
                $instrument,
                implode(', ', $unexpectedAllowList) ?: '(none)',
                implode(', ', $forbidden) ?: '(none)',
                json_encode($point['attributes'])
            ));
        }
    }
    pass(sprintf(
        '%s: %d data point(s), attributes always a subset of {%s}',
        $instrument,
        count($points),
        implode(', ', $allowed)
    ));
}

// 1. HTTP request duration: the "home" scenario (already implemented) alone must produce at least one point.
$httpDuration = 'http.server.request.duration';
if (!array_key_exists($httpDuration, InstrumentCatalog::INSTRUMENTS)) {
    fail("$httpDuration is not in InstrumentCatalog::INSTRUMENTS any more - update this script to the renamed name");
}
$httpPoints = pointsNamed($points, $httpDuration);
if ($httpPoints === []) {
    fail("expected at least one $httpDuration data point (the home scenario alone should produce one), found none");
}
assertOnlyAllowedAttributes($httpPoints, $httpDuration);

// 2. Cron / indexer / queue instruments reflecting the cron-ok/cron-fail/indexer/queue scenarios having run.
// These are the per-run counters/histograms (not the periodic collector's gauges): a scenario that ran a cron job,
// an indexer or a queue message must have produced one of these in the same process, synchronously - no cron tick
// to wait for.
$perRunInstruments = [
    'magento.cron.job.runs' => 'cron-ok / cron-fail scenarios (a cron job actually completed, success or error)',
    'magento.indexer.runs' => 'indexer scenario (an indexer actually ran)',
    'magento.queue.messages' => 'queue scenario (a queue message was actually handled)',
];
foreach ($perRunInstruments as $instrument => $reflects) {
    if (!array_key_exists($instrument, InstrumentCatalog::INSTRUMENTS)) {
        fail("$instrument is not in InstrumentCatalog::INSTRUMENTS any more - update this script");
    }
    $found = pointsNamed($points, $instrument);
    if ($found === []) {
        fail("expected at least one $instrument data point, reflecting the $reflects - found none");
    }
    assertOnlyAllowedAttributes($found, $instrument);
}

// 3. The periodic collector's gauges: present only if bode:metrics:collect (cron, every minute) happened to tick
// during this run. Checked when present (same allow-list contract), otherwise reported as skipped, not failed - a
// short e2e run has no guarantee of a minute-ly tick landing inside its window.
$collectorGauges = [
    'magento.cron.oldest_pending_age',
    'magento.cron.jobs_stuck',
    'magento.indexer.status',
    'magento.mview.backlog',
    'magento.queue.backlog',
];
foreach ($collectorGauges as $instrument) {
    if (!array_key_exists($instrument, InstrumentCatalog::INSTRUMENTS)) {
        fail("$instrument is not in InstrumentCatalog::INSTRUMENTS any more - update this script");
    }
    $found = pointsNamed($points, $instrument);
    if ($found === []) {
        echo "skip  $instrument: no data point found (bode:metrics:collect may not have ticked in this run's window)\n";
        continue;
    }
    assertOnlyAllowedAttributes($found, $instrument);
}

// 4. No PII anywhere: every attribute value of every data point, not just the instruments checked above - a
// defensive sweep across the whole file, same forbidden-substring approach as e2e/assert/spans.php. A bare "?" was
// tried first and dropped (packages/metrics' own attribute values are short labels like route templates and status
// classes, never free text, but a global sweep should still avoid markers broad enough to flag legitimate content
// elsewhere) - kept to unambiguous query-string-shaped and email markers only.
$forbidden = ['q=widget', 'email=', 'utm_source=', 'token=', '@example.com', 'searchCriteria'];
foreach ($points as $point) {
    foreach ($point['attributes'] as $key => $value) {
        $haystack = is_string($value) ? $value : json_encode($value);
        if (!is_string($haystack)) {
            continue;
        }
        foreach ($forbidden as $needle) {
            if (str_contains($haystack, $needle)) {
                fail(sprintf(
                    '%s attribute %s="%s" contains forbidden substring "%s"',
                    $point['name'],
                    $key,
                    $haystack,
                    $needle
                ));
            }
        }
    }
}
pass('no forbidden substrings (query string markers, email, searchCriteria) in any metric attribute');

echo "metrics: all checkpoint assertions passed\n";
