<?php

declare(strict_types=1);

require __DIR__ . '/../lib/trace.php';

/**
 * Scenario runner: `php run.php <scenario>`. Each scenario sends a known sampled traceparent where it makes an HTTP
 * request, so the assertions in e2e/assert/*.php can look for exact trace ids instead of discovering them after the
 * fact. HTTP scenarios talk to the long-lived web server (WEB_HOST:WEB_PORT); CLI scenarios shell out to
 * bin/magento in this same container, a fresh PHP process per call like a real CLI invocation.
 */

/** @return array{0:int,1:string} HTTP status, response body */
function httpRequest(
    string $method,
    string $path,
    string $traceparent,
    array $headers = [],
    ?string $body = null
): array {
    $host = getenv('WEB_HOST') ?: 'bode-e2e-web';
    $port = getenv('WEB_PORT') ?: '8000';
    $ch = curl_init("http://$host:$port$path");
    $allHeaders = array_merge(["traceparent: $traceparent"], $headers);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($responseBody === false) {
        fwrite(STDERR, "request to $path failed: $error\n");
        exit(1);
    }
    return [$status, $responseBody];
}

function httpGet(string $path, string $traceparent, array $headers = []): array
{
    return httpRequest('GET', $path, $traceparent, $headers);
}

/** @return array{0:int,1:string} HTTP status, response body */
function httpPost(string $path, string $traceparent, string $jsonBody, array $headers = []): array
{
    return httpRequest(
        'POST',
        $path,
        $traceparent,
        array_merge(['Content-Type: application/json'], $headers),
        $jsonBody
    );
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

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr, for a one-shot e2e/scenarios/*.php subprocess */
function runScript(string ...$args): array
{
    $cmd = array_merge(['php'], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, '/magento');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code, $out, $err];
}

/**
 * Runs one of the trigger-*.php one-shot scripts (uncaught/fatal) as a fresh subprocess so FatalErrorReporter's
 * globally-installed exception handler and shutdown function never leak into this long-lived runner process.
 * Inherits this process's environment (proc_open's default when no env array is passed), so the real
 * MAGENTO_OTEL_* and OTEL_* env vars e2e/run.sh's `scenario` subcommand set are visible to the child the same way they
 * would be for any other process in this container. Writes the child's stderr to $logPath in addition to whatever
 * FatalErrorReporter already wrote to its own configured stream (php://stderr), so it survives for a later
 * assertion script to read even after this process's own stderr has been consumed by run.sh's caller.
 *
 * @return int the child's exit code
 */
function runTrigger(string $name, string $logPath): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', "/e2e/scenarios/trigger-$name.php"], $descriptors, $pipes, '/magento');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    file_put_contents($logPath, $err);
    $hasRecord = str_contains($out, '"severity_number":21') || str_contains($err, '"severity_number":21');
    echo "$name: exit $code, stdout " . strlen($out) . " bytes, stderr " . strlen($err) . " bytes\n";
    echo "$name: " . ($hasRecord ? 'found' : 'did NOT find') . " a severity_number 21 JSON line in stdout/stderr\n";
    echo "$name: stderr written to $logPath\n";

    return $code;
}

/** @return array{0:int,1:string,2:string} exit code, stdout, stderr; kills the process after $timeoutSeconds */
function magentoWithTimeout(int $timeoutSeconds, string ...$args): array
{
    $cmd = array_merge(['php', 'bin/magento'], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, '/magento');
    if ($process === false) {
        return [1, '', 'proc_open failed'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    while (true) {
        $status = proc_get_status($process);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(100_000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [$timedOut ? 124 : $code, $out, $timedOut ? $err . "\n(killed after {$timeoutSeconds}s timeout)" : $err];
}

$scenario = $argv[1] ?? '';

switch ($scenario) {
    case 'home':
        // First hit: a real request through the full stack, no page cache yet - names the root span after the
        // resolved route/controller/action (CatalogProductController#view-style, no http.route: storefront has no
        // declared route table). Second hit: the built-in full page cache answers it, before any controller
        // resolves - the span assertion checks the second trace's root span falls back to a best-effort template
        // of the raw path ("GET /") instead of the old "GET unknown"/"GET page_cache_hit" sentinel.
        [$status1] = httpGet('/', traceparentFor('home-1'));
        echo "home-1: HTTP $status1, trace " . traceIdFor('home-1') . "\n";
        [$status2] = httpGet('/', traceparentFor('home-2'));
        echo "home-2: HTTP $status2, trace " . traceIdFor('home-2') . "\n";
        if ($status1 !== 200 || $status2 !== 200) {
            fwrite(STDERR, "expected HTTP 200 on both requests\n");
            exit(1);
        }
        break;

    case 'search':
        // PII-leak check: an email and a bearer-looking token, both synthetic/fake, appear only in the query string
        // of a real storefront search request. AGENTS.md: "never record query strings ... SQL literals, e-mail
        // addresses" - none of q/utm_source/email/token may ever reach a span attribute.
        [$status] = httpGet(
            '/catalogsearch/result/?q=widget&utm_source=newsletter&email=shopper%40example.com&token=abc123def456789',
            traceparentFor('search')
        );
        echo "search: HTTP $status, trace " . traceIdFor('search') . "\n";
        if ($status !== 200) {
            fwrite(STDERR, "expected HTTP 200 from catalogsearch, got $status\n");
            exit(1);
        }
        break;

    case 'not-found':
        // A path with no matching route in any module: Magento's default 404 controller/page. The root span should
        // still resolve a real (low-cardinality) route, never the raw unmatched path.
        [$status] = httpGet('/this-page-does-not-exist-e2e', traceparentFor('not-found'));
        echo "not-found: HTTP $status, trace " . traceIdFor('not-found') . "\n";
        if ($status !== 404) {
            fwrite(STDERR, "expected HTTP 404, got $status\n");
            exit(1);
        }
        break;

    case 'rest':
        // /V1/directory/countries is `<resource ref="anonymous"/>` in magento/module-directory/etc/webapi.xml -
        // confirmed by reading it directly (storeConfigs, tried first, turned out to require auth in this version:
        // 401). searchCriteria is appended even though this endpoint ignores it: the point is that the REST route
        // *template* (/V1/directory/countries) is what ends up as http.route/the span name, and the searchCriteria
        // query parameters never leak into a span attribute even though they're in the query string.
        [$status] = httpGet(
            '/rest/V1/directory/countries?searchCriteria[pageSize]=1&searchCriteria[currentPage]=1',
            traceparentFor('rest')
        );
        echo "rest: HTTP $status, trace " . traceIdFor('rest') . "\n";
        if ($status !== 200) {
            fwrite(STDERR, "expected HTTP 200 from REST directory/countries, got $status\n");
            exit(1);
        }
        break;

    case 'graphql':
        // A fabricated/masked cart id: GraphQl::dispatch still runs and resolves an operation name, the resolver
        // then fails because the cart doesn't exist. GraphQL reports resolver errors as HTTP 200 with an "errors"
        // array in the body, not as a non-200 status - this scenario is about span/attribute redaction (the query
        // string/body must never leak), not about the query succeeding.
        $body = json_encode([
            'query' => '{ cart(cart_id: "e2e0000000000000000000000000000") { id } }',
            'operationName' => null,
        ]);
        if ($body === false) {
            fwrite(STDERR, "failed to encode the graphql request body\n");
            exit(1);
        }
        [$status] = httpPost('/graphql', traceparentFor('graphql'), $body);
        echo "graphql: HTTP $status, trace " . traceIdFor('graphql') . "\n";
        if ($status !== 200) {
            fwrite(STDERR, "expected HTTP 200 (GraphQL reports errors in the body, not the status), got $status\n");
            exit(1);
        }
        break;

    case 'admin-login':
        // Magento's default admin login page given --backend-frontname=admin (e2e/install.sh). Unauthenticated GET,
        // just enough to exercise the admin-area HTTP root span and routing.
        [$status] = httpGet('/admin/admin/auth/login/', traceparentFor('admin-login'));
        echo "admin-login: HTTP $status, trace " . traceIdFor('admin-login') . "\n";
        if ($status !== 200) {
            fwrite(STDERR, "expected HTTP 200 from the admin login page, got $status\n");
            exit(1);
        }
        break;

    case 'admin-save':
        // No traceparent: this is a one-shot bootstrap process (Bootstrap::create(), never ->run()), not an HTTP
        // request, so there is nothing for httpGet/httpPost to call and no root span to inject a trace context
        // into. See trigger-admin-save.php for exactly what this does and does not exercise/verify (a documented
        // limitation: AdminAudit's compiled-DI construction and no-throw execution are covered; the admin.audit
        // span event itself is not, because no HTTP/CLI instrumentation hook opens a root span in this process).
        [$code, $out, $err] = runScript('/e2e/scenarios/trigger-admin-save.php');
        echo $out;
        echo "admin-save: exit $code\n";
        if ($code !== 0) {
            fwrite(STDERR, "trigger-admin-save.php failed: $err\n");
            exit(1);
        }
        break;

    case 'cache-status':
        [$code, $out, $err] = magento('cache:status');
        echo $out;
        echo "cache-status: exit $code\n";
        if ($code !== 0) {
            fwrite(STDERR, "cache:status failed: $err\n");
            exit(1);
        }
        break;

    case 'indexer':
        [$code, $out, $err] = magento('indexer:reindex');
        echo $out;
        echo "indexer: exit $code\n";
        if ($code !== 0) {
            fwrite(STDERR, "indexer:reindex failed: $err\n");
            exit(1);
        }
        break;

    case 'cron-ok':
        // No special setup: confirms cron tracing fires naturally on whatever jobs are actually due.
        [$code, $out, $err] = magento('cron:run');
        echo $out;
        echo "cron-ok: exit $code\n";
        if ($code !== 0) {
            fwrite(STDERR, "cron:run failed: $err\n");
            exit(1);
        }
        break;

    case 'cron-fail':
        // Seeds one cron_schedule row with a job_code that exists in no module's crontab.xml (see
        // trigger-cron-fail-seed.php for why this approximation - "job code not found" rather than an in-job
        // thrown exception - was chosen), then runs cron:run so it deterministically fails that job.
        [$seedCode, $seedOut, $seedErr] = runScript('/e2e/scenarios/trigger-cron-fail-seed.php');
        echo $seedOut;
        if ($seedCode !== 0) {
            fwrite(STDERR, "failed to seed the bogus cron_schedule row: $seedErr\n");
            exit(1);
        }
        [$code, $out, $err] = magento('cron:run');
        echo $out;
        echo "cron-fail: exit $code\n";
        // cron:run's own process exit code reflects the runner, not each individual job: a job-code-not-found
        // failure is recorded against that cron_schedule row (status=error), not surfaced as a nonzero process
        // exit. This scenario only needs the failing job to have actually run; asserting the resulting span/log
        // content belongs to a different assertion script.
        if ($err !== '') {
            fwrite(STDERR, "cron-fail: cron:run stderr: $err\n");
        }
        break;

    case 'queue':
        // See trigger-queue-publish.php for why this publishes directly to inventory.reservations.cleanup (a plain
        // Magento\Framework\MessageQueue\Consumer-backed queue, the class packages/tracing's QueueInstrumentation
        // actually hooks) rather than through the async/bulk REST API, as an earlier version of this scenario did -
        // that path's consumer (async.operations.all -> MassConsumer) really did process the message (confirmed:
        // queue_message_status ended up COMPLETE) but is a different, uninstrumented class, so it never produced a
        // span. A real, separate finding about packages/tracing's queue coverage, reported in the PR body, not
        // fixed here.
        //
        // The consumer is run with a hard wall-clock timeout (magentoWithTimeout) regardless: queue:consumers:start
        // is a long-running process by design (CliInstrumentation.php lists it UNTRACED for exactly this reason),
        // and nothing about this e2e job should be able to hang CI indefinitely if the publish step above did not
        // actually enqueue anything, for whatever reason.
        [$publishCode, $publishOut, $publishErr] = runScript('/e2e/scenarios/trigger-queue-publish.php');
        echo $publishOut;
        if ($publishCode !== 0) {
            fwrite(STDERR, "queue: failed to publish to inventory.reservations.cleanup: $publishErr\n");
            exit(1);
        }

        [$code, $out, $err] = magentoWithTimeout(30, 'queue:consumers:start', 'inventory.reservations.cleanup', '--max-messages=1');
        echo $out;
        echo "queue: consumer exit $code" . ($code === 124 ? ' (timed out, killed - see stderr)' : '') . "\n";
        if ($err !== '') {
            fwrite(STDERR, "queue: consumer stderr: $err\n");
        }
        break;

    case 'uncaught':
        // See trigger-uncaught.php. The pass/fail assertion for "reported exactly once" belongs to a different
        // assertion script; this scenario only needs to reliably produce the artifact.
        $code = runTrigger('uncaught', '/magento/var/log/e2e-uncaught.stderr.log');
        if ($code === 0) {
            // An uncaught exception always ends the process non-zero; exit 0 here means the throw never happened.
            fwrite(STDERR, "trigger-uncaught.php exited 0, expected a nonzero exit from the uncaught exception\n");
            exit(1);
        }
        break;

    case 'fatal':
        // See trigger-fatal.php. Same note as 'uncaught': assertion is a different script's job.
        $code = runTrigger('fatal', '/magento/var/log/e2e-fatal.stderr.log');
        if ($code === 0) {
            fwrite(STDERR, "trigger-fatal.php exited 0, expected a nonzero exit from the fatal error\n");
            exit(1);
        }
        break;

    case 'entities-seed':
        // Seeds a category and a product (no sample data on a fresh install) for the entities-* scenarios below.
        [$code, $out, $err] = runScript('/e2e/scenarios/trigger-seed-product.php');
        echo $out;
        echo "entities-seed: exit $code\n";
        if ($code !== 0) {
            fwrite(STDERR, "trigger-seed-product.php failed: $err\n");
            exit(1);
        }
        break;

    case 'entities-product':
    case 'entities-category':
    case 'entities-home':
        // Run against the all-groups web server (`bin/e2e entities`, service bode-e2e-entities): a product page, a
        // category page and the home page, each under its own deterministic traceparent. The query string is there to
        // prove it never reaches a span. First hits, so the full page cache cannot answer them.
        $paths = [
            'entities-product' => '/e2e-widget.html?utm_source=e2e',
            'entities-category' => '/e2e-gear.html',
            'entities-home' => '/',
        ];
        [$status] = httpGet($paths[$scenario], traceparentFor($scenario));
        echo "$scenario: HTTP $status, trace " . traceIdFor($scenario) . "\n";
        if ($status !== 200) {
            fwrite(STDERR, "expected HTTP 200 from {$paths[$scenario]}, got $status (was entities-seed run?)\n");
            exit(1);
        }
        break;

    case 'wrap-admin-login':
    case 'wrap-graphql':
    case 'wrap-product-save':
        // Against the all-groups web server: requests whose call chain goes through Magento's generated wrappers
        // (Mysql\Interceptor around every statement, Event\Manager\Proxy around every dispatch). They carry no
        // assertion of their own; trace-integrity checks the traces for nested same-name SQL and event spans.
        if ($scenario === 'wrap-admin-login') {
            [$status] = httpGet('/admin/admin/auth/login/', traceparentFor($scenario));
        } elseif ($scenario === 'wrap-graphql') {
            [$status] = httpPost(
                '/graphql',
                traceparentFor($scenario),
                (string) json_encode(['query' => '{ cart(cart_id: "e2e0000000000000000000000000000") { id } }'])
            );
        } else {
            [$tokenStatus, $token] = httpPost(
                '/rest/V1/integration/admin/token',
                traceparentFor('wrap-token'),
                (string) json_encode(['username' => 'admin', 'password' => 'Adm1n_Pass!123'])
            );
            if ($tokenStatus !== 200) {
                fwrite(STDERR, "admin token request failed: HTTP $tokenStatus\n");
                exit(1);
            }
            [$status] = httpPost(
                '/rest/V1/products',
                traceparentFor($scenario),
                (string) json_encode(['product' => [
                    'sku' => 'e2e-wrap-' . bin2hex(random_bytes(4)),
                    'name' => 'E2E Wrap',
                    'attribute_set_id' => 4,
                    'price' => 5,
                    'type_id' => 'simple',
                    'status' => 1,
                    'visibility' => 4,
                    'weight' => 1,
                ]]),
                ['Authorization: Bearer ' . json_decode($token)]
            );
        }
        echo "$scenario: HTTP $status, trace " . traceIdFor($scenario) . "\n";
        if ($status !== 200) {
            fwrite(STDERR, "expected HTTP 200 from $scenario, got $status\n");
            exit(1);
        }
        break;

    default:
        fwrite(STDERR, "unknown scenario: $scenario\n");
        exit(2);
}
