<?php

declare(strict_types=1);

/**
 * Reads the OTel Collector's file-exporter output: one OTLP-JSON-encoded ExportXRequest per line. Bytes fields
 * (trace/span ids) are base64 in that encoding, not hex, so every helper here re-encodes to the hex a traceparent
 * header or a grep would use, to keep the assertions readable.
 */

/**
 * The Collector's file exporter marshals pdata with its own internal JSON encoding, which renders trace/span ids as
 * plain lowercase hex already - not the base64 the strict OTLP/protobuf-JSON wire format would use for a `bytes`
 * field. Handle both: hex passes through, anything else is assumed base64 and decoded to hex.
 */
function otlpBytesToHex(string $value): string
{
    if ($value === '' || preg_match('/^[0-9a-f]+$/i', $value) === 1) {
        return strtolower($value);
    }
    return bin2hex((string) base64_decode($value, true));
}

function otlpAttributesToAssoc(array $attributes): array
{
    $out = [];
    foreach ($attributes as $attribute) {
        $value = $attribute['value'] ?? [];
        $out[$attribute['key']] = $value['stringValue']
            ?? $value['intValue']
            ?? $value['boolValue']
            ?? $value['doubleValue']
            ?? ($value['arrayValue'] ?? null);
    }
    return $out;
}

/** @return list<array{traceId:string,spanId:string,parentSpanId:string,name:string,kind:string,
 *                      attributes:array,status:array,resourceAttributes:array}>
 */
function loadSpans(string $path): array
{
    $spans = [];
    if (!is_file($path)) {
        return $spans;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded['resourceSpans'] ?? [] as $resourceSpan) {
            $resourceAttributes = otlpAttributesToAssoc($resourceSpan['resource']['attributes'] ?? []);
            foreach ($resourceSpan['scopeSpans'] ?? [] as $scopeSpan) {
                foreach ($scopeSpan['spans'] ?? [] as $span) {
                    $spans[] = [
                        'traceId' => otlpBytesToHex($span['traceId'] ?? ''),
                        'spanId' => otlpBytesToHex($span['spanId'] ?? ''),
                        'parentSpanId' => otlpBytesToHex($span['parentSpanId'] ?? ''),
                        'name' => $span['name'] ?? '',
                        'kind' => $span['kind'] ?? '',
                        'attributes' => otlpAttributesToAssoc($span['attributes'] ?? []),
                        'status' => $span['status'] ?? [],
                        'resourceAttributes' => $resourceAttributes,
                    ];
                }
            }
        }
    }
    return $spans;
}

/** @return list<array{name:string,attributes:array,resourceAttributes:array}> */
function loadMetricPoints(string $path): array
{
    $points = [];
    if (!is_file($path)) {
        return $points;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded['resourceMetrics'] ?? [] as $resourceMetric) {
            $resourceAttributes = otlpAttributesToAssoc($resourceMetric['resource']['attributes'] ?? []);
            foreach ($resourceMetric['scopeMetrics'] ?? [] as $scopeMetric) {
                foreach ($scopeMetric['metrics'] ?? [] as $metric) {
                    $name = $metric['name'] ?? '';
                    $dataPoints = $metric['sum']['dataPoints']
                        ?? $metric['gauge']['dataPoints']
                        ?? $metric['histogram']['dataPoints']
                        ?? [];
                    foreach ($dataPoints as $dataPoint) {
                        $points[] = [
                            'name' => $name,
                            'attributes' => otlpAttributesToAssoc($dataPoint['attributes'] ?? []),
                            'resourceAttributes' => $resourceAttributes,
                        ];
                    }
                }
            }
        }
    }
    return $points;
}

function fail(string $message): void
{
    fwrite(STDERR, "FAIL  $message\n");
    exit(1);
}

function pass(string $message): void
{
    echo "ok    $message\n";
}
