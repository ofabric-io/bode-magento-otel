<?php

declare(strict_types=1);

/**
 * Benchmark load generator of the entity-span spike and canary protocol (docs/canary-runbook.md). Runs inside the
 * scenario container: `bin/e2e bench` starts the web server with the groups of one mode, then runs this once per mode:
 *
 *   php /e2e/bench/run.php <mode>        mode: a label (off, on, ...), only used in the output file name
 *
 * Environment: BENCH_N requests per target (default 50), BENCH_WARMUP (default 5, not recorded), BENCH_TARGETS
 * (default "product category order-save admin-grid"), WEB_HOST/WEB_PORT as for scenarios.
 *
 * Each recorded request carries a random sampled traceparent; the line written to /output/bench/<mode>.jsonl holds that
 * trace id and the wall-clock latency measured by curl. report.php later counts the spans each trace id has in the
 * Collector's traces.jsonl, so "spans per request" is what the Collector really received.
 *
 * Targets (cache-busting query strings keep the full page cache out of the measurement; they are never recorded):
 *   product     GET /e2e-widget.html?bench=<n>            seeded by `bin/e2e scenario entities-seed`
 *   category    GET /e2e-gear.html?bench=<n>
 *   order-save  the final call of a guest checkout over REST (POST payment-information, which places the order);
 *               the cart, item and shipping calls before it are set-up and are not recorded
 *   admin-grid  GET /rest/V1/orders?searchCriteria[pageSize]=20 with an admin token: the data the admin order grid
 *               lists, over REST (the grid UI needs a session and a form key; this keeps the load generator HTTP-only)
 */

$mode = $argv[1] ?? 'run';
$n = (int) (getenv('BENCH_N') ?: 50);
$warmup = (int) (getenv('BENCH_WARMUP') ?: 5);
$targets = preg_split('/\s+/', trim((string) (getenv('BENCH_TARGETS') ?: 'product category order-save admin-grid')));
$base = 'http://' . (getenv('WEB_HOST') ?: 'bode-e2e-web') . ':' . (getenv('WEB_PORT') ?: '8000');

/** @return array{0:int,1:string,2:float} status, body, seconds */
function call(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $seconds = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    return [$status, $response === false ? '' : (string) $response, $seconds];
}

function newTrace(): array
{
    $traceId = bin2hex(random_bytes(16));

    return [$traceId, sprintf('00-%s-%s-01', $traceId, bin2hex(random_bytes(8)))];
}

$json = ['Content-Type: application/json'];

$adminToken = static function () use ($base, $json): string {
    static $token = null;
    if ($token === null) {
        [$status, $body] = call(
            'POST',
            "$base/rest/V1/integration/admin/token",
            $json,
            json_encode(['username' => 'admin', 'password' => 'Adm1n_Pass!123'])
        );
        $token = $status === 200 ? (string) json_decode($body) : '';
    }

    return $token;
};

/** One recorded request of $target; returns [traceId, status, seconds]. */
$request = static function (string $target, int $i) use ($base, $json, $adminToken): array {
    [$traceId, $traceparent] = newTrace();
    $tp = ["traceparent: $traceparent"];
    switch ($target) {
        case 'product':
            [$status, , $seconds] = call('GET', "$base/e2e-widget.html?bench=$i-" . $traceId, $tp);
            break;
        case 'category':
            [$status, , $seconds] = call('GET', "$base/e2e-gear.html?bench=$i-" . $traceId, $tp);
            break;
        case 'admin-grid':
            [$status, , $seconds] = call(
                'GET',
                "$base/rest/V1/orders?searchCriteria[pageSize]=20&searchCriteria[currentPage]=1",
                array_merge($tp, ['Authorization: Bearer ' . $adminToken()])
            );
            break;
        case 'order-save':
            [$s, $cart] = call('POST', "$base/rest/V1/guest-carts", $json, '{}');
            $cartId = (string) json_decode($cart);
            if ($s !== 200 || $cartId === '') {
                return [$traceId, -1, 0.0];
            }
            call(
                'POST',
                "$base/rest/V1/guest-carts/$cartId/items",
                $json,
                json_encode(['cartItem' => ['sku' => 'e2e-widget', 'qty' => 1, 'quote_id' => $cartId]])
            );
            $address = [
                'firstname' => 'Bench', 'lastname' => 'Bot', 'street' => ['1 Bench Way'], 'city' => 'Austin',
                'region' => 'Texas', 'region_id' => 57, 'region_code' => 'TX', 'postcode' => '78701',
                'country_id' => 'US', 'telephone' => '5125550100', 'email' => 'bench@example.test',
            ];
            call(
                'POST',
                "$base/rest/V1/guest-carts/$cartId/shipping-information",
                $json,
                json_encode(['addressInformation' => [
                    'shipping_address' => $address, 'billing_address' => $address,
                    'shipping_carrier_code' => 'flatrate', 'shipping_method_code' => 'flatrate',
                ]])
            );
            [$status, , $seconds] = call(
                'POST',
                "$base/rest/V1/guest-carts/$cartId/payment-information",
                array_merge($json, $tp),
                json_encode([
                    'email' => 'bench@example.test', 'paymentMethod' => ['method' => 'checkmo'],
                    'billing_address' => $address,
                ])
            );
            break;
        default:
            fwrite(STDERR, "unknown target $target\n");
            exit(2);
    }

    return [$traceId, $status, $seconds];
};

$outDir = '/output/bench';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}
$file = "$outDir/$mode.jsonl";
file_put_contents($file, '');
foreach ($targets as $target) {
    for ($i = 0; $i < $warmup; $i++) {
        $request($target, -1 - $i);
    }
    $ok = 0;
    for ($i = 0; $i < $n; $i++) {
        [$traceId, $status, $seconds] = $request($target, $i);
        file_put_contents($file, json_encode([
            'mode' => $mode, 'target' => $target, 'trace_id' => $traceId, 'status' => $status,
            'latency_ms' => round($seconds * 1000, 2),
        ]) . "\n", FILE_APPEND);
        $ok += $status === 200 ? 1 : 0;
    }
    printf("bench %s %s: %d/%d requests returned HTTP 200\n", $mode, $target, $ok, $n);
}
