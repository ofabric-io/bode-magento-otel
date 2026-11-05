<?php

declare(strict_types=1);

require __DIR__ . '/../lib/trace.php';

/**
 * Scenario runner: `php run.php <scenario>`. Each scenario sends a known sampled traceparent where it makes an HTTP
 * request, so the assertions in e2e/assert/*.php can look for exact trace ids instead of discovering them after the
 * fact. HTTP scenarios talk to the long-lived web server (WEB_HOST:WEB_PORT); CLI scenarios shell out to
 * bin/magento in this same container, a fresh PHP process per call like a real CLI invocation.
 */

function httpGet(string $path, string $traceparent, array $headers = []): array
{
    $host = getenv('WEB_HOST') ?: 'bode-e2e-web';
    $port = getenv('WEB_PORT') ?: '8000';
    $ch = curl_init("http://$host:$port$path");
    $allHeaders = array_merge(["traceparent: $traceparent"], $headers);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) {
        fwrite(STDERR, "request to $path failed: $error\n");
        exit(1);
    }
    return [$status, $body];
}

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
function magento(string ...$args): array
{
    $cmd = array_merge(['php', 'bin/magento'], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, '/magento');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code, $out, $err];
}

$scenario = $argv[1] ?? '';

switch ($scenario) {
    case 'home':
        // First hit: a real request through the full stack, no page cache yet. Second hit: the built-in full page
        // cache answers it, which is exactly the path that used to be named "GET unknown" (AGENTS.md section 6) —
        // the span assertion checks the second trace's root span is named "GET page_cache_hit" instead.
        [$status1] = httpGet('/', traceparentFor('home-1'));
        echo "home-1: HTTP $status1, trace " . traceIdFor('home-1') . "\n";
        [$status2] = httpGet('/', traceparentFor('home-2'));
        echo "home-2: HTTP $status2, trace " . traceIdFor('home-2') . "\n";
        if ($status1 !== 200 || $status2 !== 200) {
            fwrite(STDERR, "expected HTTP 200 on both requests\n");
            exit(1);
        }
        break;

    default:
        fwrite(STDERR, "unknown scenario: $scenario\n");
        exit(2);
}
