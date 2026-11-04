<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use GuzzleHttp\Client;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Outbound HTTP through Guzzle (payment and shipping extensions, feeds). The span is named "HTTP <METHOD>" and records
 * the host and the status, never the URL (paths and queries carry ids and tokens). The W3C trace context is added to
 * the request so the callee can join the trace.
 */
class OutboundHttpInstrumentation implements InstrumentationInterface
{
    private const GUZZLE = Client::class;

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        // send(RequestInterface $request, array $options = []) and request(string $method, $uri, array $options = [])
        $runtime->instrumenter->span(
            'outbound',
            self::GUZZLE,
            'send',
            fn (?object $subject, array $params): SpanHandleInterface => $this->start(
                $runtime,
                is_object($params[0] ?? null) ? (string) $params[0]->getMethod() : 'GET',
                is_object($params[0] ?? null) ? (string) $params[0]->getUri()->getHost() : ''
            ),
            fn (SpanHandleInterface $span, ?object $s, array $p, mixed $response) => $this->finish($span, $response),
            false,
            function (SpanHandleInterface $span, ?object $subject, array $params): ?array {
                $request = $params[0] ?? null;
                if (!is_object($request) || !method_exists($request, 'withHeader')) {
                    return null;
                }
                foreach ($this->traceHeaders() as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                return [0 => $request];
            }
        );

        $runtime->instrumenter->span(
            'outbound',
            self::GUZZLE,
            'request',
            fn (?object $subject, array $params): SpanHandleInterface => $this->start(
                $runtime,
                (string) ($params[0] ?? 'GET'),
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                (string) parse_url((string) ($params[1] ?? ''), PHP_URL_HOST)
            ),
            fn (SpanHandleInterface $span, ?object $s, array $p, mixed $response) => $this->finish($span, $response),
            false,
            function (SpanHandleInterface $span, ?object $subject, array $params): array {
                $options = is_array($params[2] ?? null) ? $params[2] : [];
                $options['headers'] = ($options['headers'] ?? []) + $this->traceHeaders();

                return [2 => $options];
            }
        );
    }

    /**
     * Starts a span and makes it current.
     *
     * @param Runtime $runtime
     * @param string $method
     * @param string $host
     * @return SpanHandleInterface
     */
    private function start(Runtime $runtime, string $method, string $host): SpanHandleInterface
    {
        $method = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $method)) ?: 'GET';

        return $runtime->spans->start(
            'HTTP ' . $method,
            SpanKind::KIND_CLIENT,
            [
                'http.request.method' => $method,
                'server.address' => substr((string) preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host), 0, 255),
            ]
        );
    }

    /**
     * Adds the response facts and releases the client.
     *
     * @param SpanHandleInterface $span
     * @param mixed $response
     * @return void
     */
    private function finish(SpanHandleInterface $span, mixed $response): void
    {
        if (is_object($response) && method_exists($response, 'getStatusCode')) {
            $status = (int) $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $span->markError((string) $status);
            }
        }
    }

    /**
     * Trace context of the span that was just started (it is current inside the mutate callback).
     *
     * @return array<string,string>
     */
    private function traceHeaders(): array
    {
        $carrier = [];
        try {
            TraceContextPropagator::getInstance()->inject($carrier);
        } catch (Throwable) {
            return [];
        }

        return $carrier;
    }
}
