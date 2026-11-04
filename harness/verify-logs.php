<?php

declare(strict_types=1);

/**
 * Checks the lines of a Bode_Logs harness run read from stdin: every line is one JSON object that satisfies the
 * contract, lines logged inside the request carry a trace id that exists in Tempo, lines outside carry none, the
 * exception is structured, and nothing the module promises never to record is there.
 *
 * Usage: php verify-logs.php <tempo-url>   (reads the scenario's stdout)
 */

$tempo = $argv[1] ?? 'http://bode-lgtm:3200';
$lines = array_values(array_filter(array_map('trim', explode("\n", (string) stream_get_contents(STDIN)))));
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok    ' : 'FAIL  ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$records = [];
foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        $check(false, 'line is not a JSON object: ' . substr($line, 0, 120));
        continue;
    }
    $records[] = $decoded;
}
$check(count($records) === count($lines) && count($records) >= 5, sprintf('%d lines, every one valid JSON', count($lines)));

$required = ['timestamp', 'severity_text', 'severity_number', 'body', 'resource', 'attributes'];
$missing = [];
foreach ($records as $i => $record) {
    foreach ($required as $key) {
        if (!array_key_exists($key, $record)) {
            $missing[] = "line $i lacks $key";
        }
    }
}
$check($missing === [], 'every line has the required fields ' . implode('; ', $missing));

$byBody = static function (string $needle) use ($records): ?array {
    foreach ($records as $record) {
        if (str_contains($record['body'], $needle)) {
            return $record;
        }
    }

    return null;
};

$inside = $byBody('checkout rendered');
$check($inside !== null && preg_match('/^[0-9a-f]{32}$/', $inside['trace_id'] ?? '') === 1, 'line inside the request has a trace_id');
$check(($inside['span_id'] ?? '') !== '', 'line inside the request has a span_id');
$function = (string) ($inside['attributes']['code.function'] ?? '');
$check(str_starts_with($function, '{closure'), 'emitting code is resolved (a closure here): ' . $function);
$check(isset($inside['attributes']['code.file.path'], $inside['attributes']['code.line.number']), 'call site file and line are present');
$check(!str_contains(json_encode($inside), 'jane@example.com') && !str_contains(json_encode($inside), 'token=abc'), 'no query string, token or e-mail anywhere in the line');

$outside = $byBody('after the request');
$check($outside !== null && !isset($outside['trace_id']), 'line outside any span has no trace_id');

$declined = $byBody('payment declined');
$attributes = $declined['attributes'] ?? [];
$check(($attributes['exception.type'] ?? '') === 'RuntimeException', 'exception type is structured');
$check(($attributes['exception.cause'][0]['type'] ?? '') === 'LogicException', 'previous exception is in the cause chain');
$check(str_contains($attributes['exception.stacktrace'] ?? '', 'Cannot place order'), 'stack trace text is present');
$check(!str_contains(json_encode($attributes['exception.frames'] ?? []), '"args"'), 'frames carry no arguments');
$check(($attributes['context']['order_id'] ?? null) === 1001 && !isset($attributes['context']['exception']), 'context kept, exception not repeated in it');

$asMessage = $byBody('RuntimeException: Cannot place order');
$check($asMessage !== null && !str_contains($asMessage['body'], 'Stack trace') && isset($asMessage['attributes']['exception.stacktrace']), 'critical($e) became a one-line body plus structured exception');
$check(($asMessage['severity_number'] ?? 0) === 21, 'critical maps to severity number 21');
$check(($declined['attributes']['error.fingerprint'] ?? 'a') === ($asMessage['attributes']['error.fingerprint'] ?? 'b'), 'the same failure has the same fingerprint however it was logged');

$cron = $byBody('alert job is slow');
$check(($cron['attributes']['cron.job_code'] ?? '') === 'catalog_product_alert', 'line inside a cron job carries cron.job_code');
$between = $byBody('between jobs');
$check(!isset($between['attributes']['cron.job_code']), 'line between jobs carries none');

$check(($inside['resource']['service.name'] ?? '') !== '', 'resource has a service.name');
$check(($inside['attributes']['correlation.id'] ?? '') !== '', 'every line has a correlation id');

// The trace ids have to be real: Tempo knows the trace after the batch exporter flushed at shutdown.
$traceId = $inside['trace_id'] ?? '';
$found = false;
for ($try = 0; $try < 20 && $traceId !== ''; $try++) {
    $body = @file_get_contents($tempo . '/api/traces/' . $traceId);
    if ($body !== false && str_contains($body, 'batches')) {
        $found = true;
        break;
    }
    sleep(1);
}
$check($found, "trace $traceId exists in Tempo");

echo $failures === [] ? "\nall checks passed\n" : "\n" . count($failures) . " checks failed\n";
exit($failures === [] ? 0 : 1);
