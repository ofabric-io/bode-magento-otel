<?php

declare(strict_types=1);

require __DIR__ . '/../lib/otlp.php';
require __DIR__ . '/../lib/jsonschema.php';

/**
 * Log assertions against a real Magento process's actual log files (not the stub harness classes):
 *  - every line of var/log/system.log, var/log/debug.log, var/log/exception.log is valid JSON and validates against
 *    packages/logs/schema/log-record.schema.json, using the ported MiniJsonSchema validator (e2e/lib/jsonschema.php)
 *    rather than a Composer schema-validation dependency (AGENTS.md: allowed deps are tightly scoped).
 *  - every trace_id a log line carries actually exists in /output/traces.jsonl: proves logs and traces share the
 *    same ids on a real process (span-context injection is real, not a formatter detail that only looks right in
 *    the harness's FakeTraceProvider).
 *  - cron.job_code appears on some log lines and not others: the simplest reliable signal that CronJobContext scopes
 *    the tag to the window between Schedule::tryLockJob() winning the lock and the job reaching a final status /
 *    the observer finishing (packages/logs/Plugin/CronJobContext.php), not a cron attribute that leaks into every
 *    log line for the rest of the process's life.
 *  - exactly one uncaught-exception record exists: the regression test for the historical bug in AGENTS.md section 4
 *    ("An uncaught exception was reported three times (restore_exception_handler() re-installs the running
 *    handler)"). See the long comment near ARCHITECTURAL NOTE below for what this check can and cannot see.
 *  - no PII in any attribute or body value of any log line.
 */

const LOG_FILES = [
    '/magento/var/log/system.log',
    '/magento/var/log/debug.log',
    '/magento/var/log/exception.log',
];

/** @return list<array{0:string,1:int,2:array<string,mixed>}> [path, 1-based line number, decoded record] */
function readJsonLogLines(string $path): array
{
    if (!is_file($path)) {
        echo "skip  $path does not exist (not written yet, or MAGENTO_OTEL_LOGS_OUTPUT does not include file)\n";

        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fail("could not read $path");
    }
    $records = [];
    foreach ($lines as $index => $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            fail(sprintf(
                '%s:%d is not a valid JSON object log record: %s',
                $path,
                $index + 1,
                mb_strcut($line, 0, 300, 'UTF-8')
            ));
        }
        $records[] = [$path, $index + 1, $decoded];
    }

    return $records;
}

$schema = loadJsonSchema('/packages/logs/schema/log-record.schema.json');

/** @var list<array{0:string,1:int,2:array<string,mixed>}> $all */
$all = [];
foreach (LOG_FILES as $path) {
    $all = array_merge($all, readJsonLogLines($path));
}

if ($all === []) {
    fail(
        'no log lines found in any of ' . implode(', ', LOG_FILES) . ' - nothing to validate. Has any scenario run '
        . 'with MAGENTO_OTEL_LOGS_OUTPUT including "file" (the e2e web-up default, see e2e/run.sh)?'
    );
}

// 1. Schema validation of every line.
$schemaFailures = [];
foreach ($all as [$path, $lineNo, $record]) {
    $errors = jsonSchemaErrors($record, $schema);
    if ($errors !== []) {
        $schemaFailures[] = sprintf('%s:%d - %s', $path, $lineNo, implode('; ', $errors));
    }
}
if ($schemaFailures !== []) {
    foreach ($schemaFailures as $failure) {
        fwrite(STDERR, "FAIL  schema violation: $failure\n");
    }
    fail(sprintf('%d of %d log line(s) do not validate against log-record.schema.json', count($schemaFailures), count($all)));
}
pass(sprintf('%d log line(s) across %d file(s) all validate against log-record.schema.json', count($all), count(LOG_FILES)));

// 2. trace_id cross-correlation with /output/traces.jsonl.
$spans = loadSpans('/output/traces.jsonl');
$traceIdsSeen = array_values(array_unique(array_column($spans, 'traceId')));
$linesWithTraceId = array_values(array_filter(
    $all,
    static fn (array $entry): bool => array_key_exists('trace_id', $entry[2])
));
if ($linesWithTraceId === []) {
    echo "skip  no log line carries trace_id - nothing to cross-correlate with traces.jsonl yet (needs an HTTP or "
        . "cron scenario whose request carried a traceparent, e.g. home/search/cron-ok)\n";
} else {
    $unmatched = [];
    foreach ($linesWithTraceId as [$path, $lineNo, $record]) {
        if (!in_array($record['trace_id'], $traceIdsSeen, true)) {
            $unmatched[] = sprintf('%s:%d trace_id=%s', $path, $lineNo, $record['trace_id']);
        }
    }
    if ($unmatched !== []) {
        fail(sprintf(
            '%d log line(s) carry a trace_id not found in /output/traces.jsonl (logs and traces disagree on ids): %s',
            count($unmatched),
            implode('; ', $unmatched)
        ));
    }
    pass(sprintf(
        '%d log line(s) carrying trace_id all match a real trace id in traces.jsonl',
        count($linesWithTraceId)
    ));
}

// 3. cron.job_code: present on some lines, absent on others - the simplest reliable signal that it is scoped to the
// window a cron job is actually running (CronJobContext), not leaked onto every line for the rest of the process.
// A full "only lines logged strictly inside cron-ok/cron-fail timestamps carry it" check would need the scenario's
// own start/end timestamps, which this script does not have visibility into (no shared clock/IPC with the scenario
// runner) - noted as a gap below rather than guessed at.
$withCronJobCode = array_values(array_filter(
    $all,
    static fn (array $entry): bool => !empty($entry[2]['attributes']['cron.job_code'] ?? null)
));
$withoutCronJobCode = count($all) - count($withCronJobCode);
if ($withCronJobCode === []) {
    echo "skip  no log line carries a non-empty cron.job_code attribute - needs the cron-ok/cron-fail scenarios to "
        . "have run\n";
} elseif ($withoutCronJobCode === 0) {
    fail(
        'every single log line carries cron.job_code - CronJobContext is not scoping it to the running job, it is '
        . 'leaking onto unrelated lines (or only cron scenarios produced any logs at all in this run)'
    );
} else {
    pass(sprintf(
        'cron.job_code present on %d line(s) and absent on %d other line(s) - scoped, not leaked',
        count($withCronJobCode),
        $withoutCronJobCode
    ));
}

// 4. Exactly-once uncaught exception/fatal error report.
//
// ARCHITECTURAL NOTE on what this check can and cannot see:
// FatalErrorReporter (packages/logs/Model/FatalErrorReporter.php) writes straight to `php://stderr` by construction
// (its $target constructor parameter defaults to that and registration.php never overrides it) - never through
// Monolog, never through MAGENTO_OTEL_LOGS_OUTPUT, and never into var/log/*.log. Its body is a single, otherwise
// unmistakable record: severity_number 21 (CRITICAL), attributes['log.channel'] === 'php.uncaught', body starting
// with "Uncaught ". For an HTTP scenario (web container's stderr) that stream is only visible via `docker logs`,
// which this assertion - mounted with only /magento:ro, /output:ro, /e2e:ro, /packages:ro (e2e/run.sh's `assert`
// case) - has no access to. For that reason this check looks for the record in two defensive places instead of
// failing outright when it finds neither:
//   (a) any of the three files already parsed above (system.log/debug.log/exception.log) - reachable if a scenario,
//       or a future change to FatalErrorReporter's $target, ever routes it through a file handler;
//   (b) a convention this script documents for whoever writes the "uncaught"/"fatal" scenarios: redirect that one
//       process's stderr to /magento/var/log/e2e-uncaught.stderr.log (glob-matched below), which the scenario
//       process CAN do because e2e/run.sh's `scenario` case mounts $MAGENTO_MOUNT read-write, unlike `assert`.
// If no candidate source has any matching record, the regression test cannot run yet and this prints `skip`, not a
// false failure - but once a candidate source exists, a count other than exactly 1 is a hard failure: that's the bug
// this check exists to catch.
$uncaughtCandidateFiles = array_merge(
    LOG_FILES,
    glob('/magento/var/log/e2e-uncaught*.log') ?: [],
    glob('/magento/var/log/e2e-fatal*.log') ?: []
);
$uncaughtCandidateFiles = array_values(array_unique($uncaughtCandidateFiles));

/** @var list<array{0:string,1:int,2:array<string,mixed>}> $candidateLines */
$candidateLines = [];
foreach ($uncaughtCandidateFiles as $path) {
    if (in_array($path, LOG_FILES, true)) {
        // Already parsed and schema-validated above; reuse instead of re-reading.
        $candidateLines = array_merge(
            $candidateLines,
            array_values(array_filter($all, static fn (array $entry): bool => $entry[0] === $path))
        );
        continue;
    }
    $candidateLines = array_merge($candidateLines, readJsonLogLines($path));
}

$uncaughtRecords = array_values(array_filter(
    $candidateLines,
    static fn (array $entry): bool => ($entry[2]['attributes']['log.channel'] ?? null) === 'php.uncaught'
        && ($entry[2]['severity_number'] ?? null) === 21
        && str_starts_with((string) ($entry[2]['body'] ?? ''), 'Uncaught ')
));

if ($uncaughtRecords === []) {
    echo "skip  no uncaught-exception record (attributes['log.channel']==='php.uncaught') found in any of: "
        . implode(', ', $uncaughtCandidateFiles) . " - the \"uncaught\" scenario has not run yet, or its process's "
        . "stderr (where FatalErrorReporter writes, by construction) was not captured to a file this assertion can "
        . "read. See the ARCHITECTURAL NOTE above for exactly what is needed.\n";
} else {
    $locations = array_map(
        static fn (array $entry): string => sprintf('%s:%d', $entry[0], $entry[1]),
        $uncaughtRecords
    );
    if (count($uncaughtRecords) !== 1) {
        fail(sprintf(
            'expected exactly ONE uncaught-exception record (the historical bug: it was reported three times '
            . 'because restore_exception_handler() re-installs the running handler - AGENTS.md section 4), found '
            . '%d at: %s',
            count($uncaughtRecords),
            implode(', ', $locations)
        ));
    }
    pass(sprintf('exactly one uncaught-exception record found, at %s', $locations[0]));
}

// 5. No PII: scan every attribute value and the body of every log line, same forbidden-substring approach as
// e2e/assert/spans.php and e2e/assert/metrics.php. Kept to unambiguous markers only (query-string-shaped PII, an
// email, a literal "token=", searchCriteria): words like "password" or "Authorization" also appear in entirely
// legitimate messages (e.g. an admin-login-failure's "invalid password" body, or a stack frame naming a class) with
// no actual secret value in them, which would make this check noisy without catching a real leak any better. A bare
// "?" and "://" were tried first and dropped: real Magento log lines legitimately end sentences in "?" ("Are there
// any items in source table `sales_order`?" - a genuine module-data-exporter message, not a query string) and
// legitimately contain "://" (phar:// stream wrappers in stack traces, documentation URLs in warnings) - verified
// against this e2e run's own real log output, not assumed.
$forbidden = ['q=widget', 'email=', 'utm_source=', 'token=', '@example.com', 'searchCriteria'];

/** @param mixed $value */
function scanForForbidden(mixed $value, string $where, array $forbidden): void
{
    if (is_string($value)) {
        $haystack = $value;
    } elseif (is_array($value)) {
        $haystack = json_encode($value);
    } else {
        return;
    }
    if (!is_string($haystack)) {
        return;
    }
    foreach ($forbidden as $needle) {
        if (str_contains($haystack, $needle)) {
            fail(sprintf('%s="%s" contains forbidden substring "%s"', $where, mb_strcut($haystack, 0, 300, 'UTF-8'), $needle));
        }
    }
}

foreach ($all as [$path, $lineNo, $record]) {
    scanForForbidden($record['body'] ?? null, "$path:$lineNo body", $forbidden);
    foreach (($record['attributes'] ?? []) as $key => $value) {
        // exception.stacktrace legitimately contains file paths with "?" or "://" only in the rarest of cases
        // (phar:// stream wrappers); it is still scanned like everything else - a real leak matters more than a
        // false positive here, and none of the scenarios are expected to trigger one.
        scanForForbidden($value, "$path:$lineNo attributes.$key", $forbidden);
    }
}
pass('no forbidden substrings (query string markers, email, searchCriteria) in any log line');

echo "logs: all checkpoint assertions passed (see skips above for what has not run yet)\n";
