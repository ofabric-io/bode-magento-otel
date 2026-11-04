<?php

declare(strict_types=1);

/**
 * Prints every span Tempo holds for the harness service: name, kind, status, attributes and events. Also fails (exit
 * 1) when any span carries something the Bode modules promise never to export: query strings, e-mail addresses, the
 * raw URL, exception messages.
 * Usage: php verify.php [tempo-url]
 */

$tempo = $argv[1] ?? 'http://bode-lgtm:3200';
$search = json_decode((string) file_get_contents($tempo . '/api/search?limit=100&q=' . rawurlencode('{ resource.service.name = "bode-harness" }')), true);
$bad = [];
$rows = [];

foreach ($search['traces'] ?? [] as $t) {
    $trace = json_decode((string) file_get_contents($tempo . '/api/traces/' . $t['traceID']), true);
    foreach ($trace['batches'] ?? [] as $batch) {
        foreach ($batch['scopeSpans'] ?? [] as $scope) {
            foreach ($scope['spans'] ?? [] as $span) {
                $attrs = [];
                foreach ($span['attributes'] ?? [] as $a) {
                    $attrs[$a['key']] = reset($a['value']);
                }
                $events = array_map(static fn (array $e): string => $e['name'] . ' ' . json_encode(
                    array_column(array_map(static fn (array $x): array => ['k' => $x['key'], 'v' => reset($x['value'])], $e['attributes'] ?? []), 'v', 'k')
                ), $span['events'] ?? []);
                $kinds = ['SPAN_KIND_INTERNAL', 'SPAN_KIND_SERVER', 'SPAN_KIND_CLIENT', 'SPAN_KIND_PRODUCER', 'SPAN_KIND_CONSUMER'];
                $rows[] = [
                    'trace' => substr($t['traceID'], 0, 8),
                    'name' => $span['name'],
                    'kind' => str_replace('SPAN_KIND_', '', (string) ($span['kind'] ?? '')),
                    'status' => $span['status']['code'] ?? 'UNSET',
                    'attrs' => $attrs,
                    'events' => $events,
                ];
                $blob = json_encode([$span['name'], $attrs, $events]);
                foreach (['?utm', 'token=', '@example.com', 'secret detail', 'abcdef', 'searchCriteria'] as $needle) {
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
    printf("%s  %-8s %-9s %s\n", $r['trace'], $r['kind'], $r['status'], $r['name']);
    foreach ($r['attrs'] as $k => $v) {
        if (str_starts_with($k, 'bode.') || str_starts_with($k, 'http') || in_array($k, ['url.path', 'client.address', 'enduser.id', 'error.type', 'messaging.destination.name', 'process.exit.code', 'session.id'], true)) {
            printf("            %s = %s\n", $k, $v);
        }
    }
    foreach ($r['events'] as $e) {
        printf("            event: %s\n", $e);
    }
}
printf("\n%d spans in %d traces\n", count($rows), count($search['traces'] ?? []));
if ($bad !== []) {
    fwrite(STDERR, "FORBIDDEN CONTENT:\n  " . implode("\n  ", $bad) . "\n");
    exit(1);
}
echo "no forbidden content found\n";
