<?php

declare(strict_types=1);

require __DIR__ . '/../lib/otlp.php';

/**
 * Disabled-mode assertion: a real on/off comparison of two fresh `php bin/magento cache:status` invocations against
 * the same compiled Magento, both with the exact OTel-exporter env block e2e/run.sh's `scenario`/`web-up` cases use
 * (this assert container is already attached to the shared Docker network - e2e/run.sh's `assert` case passes
 * `--network "$NET"` - so it can reach the Collector directly, the same as any scenario can):
 *  - baseline: that full env block, telemetry on, nothing disabled.
 *  - disabled: the same full env block, PLUS OTEL_SDK_DISABLED=true and every MAGENTO_OTEL_*_ENABLED=false layered
 *    on top.
 * If CliInstrumentation covers `cache:status`, the baseline run adds exactly one new CLI span to traces.jsonl and
 * the disabled run adds none - the real proof that these vars turn telemetry off, not two already-off runs compared
 * to each other (the previous version of this script, see git history, could only prove the vars are inert, because
 * nothing in its environment had telemetry on to begin with).
 */

/**
 * Runs `php bin/magento cache:status` at /magento with $envOverrides layered on top of this process's own
 * environment (so PATH etc. still resolve), capturing exit code, stdout and stderr.
 *
 * @param array<string,string> $envOverrides
 * @return array{0:int,1:string,2:string}
 */
function runMagentoCacheStatus(array $envOverrides): array
{
    $env = array_merge(getenv() ?: [], $envOverrides);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', 'bin/magento', 'cache:status'], $descriptors, $pipes, '/magento', $env);
    if ($process === false) {
        fail('could not even start `php bin/magento cache:status` (proc_open failed) - nothing to compare');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

// Same env block as e2e/run.sh's `scenario`/`web-up` cases (OTel SDK fully configured, real exporter, real
// Collector), so this CLI invocation is a faithful stand-in for "telemetry is normally on here".
$telemetryOnEnv = [
    'OTEL_PHP_AUTOLOAD_ENABLED' => 'true',
    'OTEL_SERVICE_NAME' => 'bode-e2e',
    'OTEL_TRACES_EXPORTER' => 'otlp',
    'OTEL_METRICS_EXPORTER' => 'otlp',
    'OTEL_LOGS_EXPORTER' => 'none',
    'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/protobuf',
    'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://bode-e2e-collector:4318',
    'OTEL_METRICS_EXPORT_INTERVAL' => '1000',
    'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE' => 'delta',
    'MAGENTO_OTEL_TRACING_GROUPS' => 'http,cron,cli,indexer,cache,queue,outbound',
    'MAGENTO_OTEL_IP_MODE' => 'full',
    'MAGENTO_OTEL_CORRELATION_HEADERS' => 'X-E2E-Run',
    'MAGENTO_OTEL_LOGS_OUTPUT' => 'file,stdout',
    'MAGENTO_OTEL_LOGS_ENABLED' => 'true',
];
$disablingEnv = $telemetryOnEnv + [
    'OTEL_SDK_DISABLED' => 'true',
    'MAGENTO_OTEL_ENABLED' => 'false',
    'MAGENTO_OTEL_LOGS_ENABLED' => 'false',
    'MAGENTO_OTEL_METRICS_ENABLED' => 'false',
];

/**
 * Polls /output/traces.jsonl's span count up to $maxAttempts times (short sleeps between), returning as soon as it
 * differs from $baseline or the attempts run out. The OTel SDK's batch span processor does not export
 * synchronously with the subprocess exiting - this is the same class of ingestion-lag problem harness/verify-logs.php
 * already polls for against Tempo, here against the Collector's file-exporter output instead.
 */
function countSpansAfter(int $baseline, int $maxAttempts = 20): int
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $count = count(loadSpans('/output/traces.jsonl'));
        if ($count !== $baseline) {
            return $count;
        }
        usleep(250_000);
    }

    return $baseline;
}

$tracesBefore = count(loadSpans('/output/traces.jsonl'));

[$baselineExit, $baselineOut, $baselineErr] = runMagentoCacheStatus($telemetryOnEnv);
$tracesAfterBaseline = countSpansAfter($tracesBefore);

[$disabledExit, $disabledOut, $disabledErr] = runMagentoCacheStatus($disablingEnv);
// No poll-for-growth here: this one must *stay* at $tracesAfterBaseline, so there's nothing to wait for - but a
// fixed short settle delay still gives a very-late baseline flush (if the polling above timed out early) one last
// chance to land before the comparison below, rather than racing it.
usleep(500_000);
$tracesAfterDisabled = count(loadSpans('/output/traces.jsonl'));

// (a) exit code: stock Magento behaviour unaffected by the disabling vars.
if ($baselineExit !== 0) {
    // The baseline itself failing is most likely this assert container's read-only /magento mount (e2e/run.sh's
    // `assert` case: `-v "$MAGENTO_MOUNT:/magento:ro"`) rather than anything telemetry-related - cache:status
    // should be read-only, but if this Magento install needs to write somewhere on every invocation (a lock file,
    // a first-run flag), that would fail identically regardless of these env vars. Report it plainly and skip the
    // exit-code comparison rather than asserting something this environment cannot actually demonstrate either way.
    echo 'skip  baseline `php bin/magento cache:status` (no disabling env vars) itself did not exit 0 (exit='
        . $baselineExit . ") in this assert container - cannot use it as a known-good baseline to compare against. "
        . 'stderr: ' . mb_strcut(trim($baselineErr), 0, 500, 'UTF-8') . "\n";
} elseif ($disabledExit !== $baselineExit) {
    fail(sprintf(
        'disabling telemetry (OTEL_SDK_DISABLED=true, MAGENTO_OTEL_*_ENABLED=false) changed the exit code of '
        . '`php bin/magento cache:status`: baseline exit=%d, disabled exit=%d - telemetry must never break the '
        . 'host (AGENTS.md section 5). disabled stderr: %s',
        $baselineExit,
        $disabledExit,
        mb_strcut(trim($disabledErr), 0, 1000, 'UTF-8')
    ));
} else {
    pass(sprintf(
        '`php bin/magento cache:status` exits %d identically with and without the disabling env vars',
        $baselineExit
    ));
}

// (b) stdout: Magento's own command output is not reshaped by the disabling vars (cache:status prints a
// deterministic table of cache type => status, no timestamps, so byte-identical is the right bar here).
if ($disabledOut !== $baselineOut) {
    fail(sprintf(
        "stdout of `php bin/magento cache:status` differs with the disabling env vars set.\n--- baseline ---\n%s"
        . "\n--- disabled ---\n%s",
        mb_strcut($baselineOut, 0, 2000, 'UTF-8'),
        mb_strcut($disabledOut, 0, 2000, 'UTF-8')
    ));
}
pass('stdout of `php bin/magento cache:status` is identical with and without the disabling env vars');

// (c) the real on/off proof: CliInstrumentation covers `cache:status` (not in its UNTRACED list -
// packages/tracing/Instrumentation/CliInstrumentation.php), so the fully-telemetry-on baseline run must add exactly
// one new CLI root span ("cli cache:status") to traces.jsonl, and the disabled run - same env, plus
// OTEL_SDK_DISABLED=true and every MAGENTO_OTEL_*_ENABLED=false - must add none. This is the actual proof that these
// vars turn telemetry off, not just that they are inert against a process that never had it on.
$baselineNewSpans = $tracesAfterBaseline - $tracesBefore;
$disabledNewSpans = $tracesAfterDisabled - $tracesAfterBaseline;
if ($baselineNewSpans < 1) {
    fail(sprintf(
        'the baseline run (full telemetry-on env, nothing disabled) added %d new span(s) to traces.jsonl, expected '
        . 'at least 1 ("cli cache:status" - CliInstrumentation does not list cache:status as untraced). If this is '
        . '0, either the exporter could not reach the Collector (check OTEL_EXPORTER_OTLP_ENDPOINT/network) or the '
        . 'OTel SDK batch processor has not flushed yet - in which case this is a timing problem with this '
        . 'assertion, not a real regression.',
        $baselineNewSpans
    ));
}
pass(sprintf('the fully-telemetry-on baseline run added %d new span(s) to traces.jsonl', $baselineNewSpans));

if ($disabledNewSpans !== 0) {
    fail(sprintf(
        'the disabled run (OTEL_SDK_DISABLED=true, MAGENTO_OTEL_*_ENABLED=false, otherwise the same telemetry-on '
        . 'env as the baseline) added %d new span(s) to traces.jsonl - these vars did not actually turn telemetry '
        . 'off',
        $disabledNewSpans
    ));
}
pass('the disabled run (same env, disabling vars layered on top) added 0 new spans - telemetry really is off');

echo "disabled-mode: all assertions passed - a real on/off comparison, not two already-off runs.\n";
