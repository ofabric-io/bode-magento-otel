<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\GraphQl\Controller\GraphQl;
use Magento\Webapi\Controller\Rest\Router;
use Magento\Framework\App\State;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\PageCache\Kernel;
use Bode\Tracing\Api\RouteNormalizerInterface;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use Bode\Tracing\Model\ClientAddressResolver;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Web requests in every area (storefront, admin, REST, GraphQL): one SERVER root span per request.
 *
 * The span is named "<METHOD> <route>" where the route is the Magento full action name (catalog_product_view), the
 * REST route template (/V1/products/:id) or the GraphQL operation name; never a URL, never a query string.
 * Identity enrichment (customer or admin id, store, ...) is done by Plugin\EnrichRequestSpan, which has DI.
 */
// The SAPI superglobals are the request here: this runs before Magento builds its request object.
// phpcs:disable Magento2.Security.Superglobal
class HttpInstrumentation implements InstrumentationInterface
{
    private const FRONT_CONTROLLER = FrontController::class;
    private const BOOTSTRAP = Bootstrap::class;
    private const STATE = State::class;
    private const REST_ROUTER = Router::class;
    private const GRAPHQL = GraphQl::class;
    private const PAGE_CACHE = Kernel::class;
    private const CORRELATION_VALUE_MAX = 128;

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        // MAGENTO_OTEL_ASSUME_WEB exists only so the test harness can drive web scenarios from the CLI.
        if (PHP_SAPI === 'cli' && $runtime->env->get('MAGENTO_OTEL_ASSUME_WEB') !== 'true') {
            return;
        }
        $instrumenter = $runtime->instrumenter;

        $instrumenter->span(
            'http',
            self::BOOTSTRAP,
            'run',
            fn (): ?SpanHandleInterface => $this->begin($runtime),
            fn (SpanHandleInterface $span) => $this->finish($runtime, $span),
            true
        );

        $instrumenter->after(
            'http',
            self::STATE,
            'setAreaCode',
            static function (SpanHandleInterface $root, ?object $subject, array $params): void {
                if (isset($params[0]) && is_string($params[0])) {
                    $root->setAttribute('bode.magento.area', preg_replace('/[^a-z_]/', '', strtolower($params[0])));
                }
            },
            true
        );

        $instrumenter->after(
            'http',
            self::FRONT_CONTROLLER,
            'dispatch',
            function (SpanHandleInterface $root, ?object $subject, array $params) use ($runtime): void {
                $request = $params[0] ?? null;
                if (!is_object($request) || !method_exists($request, 'getFullActionName')) {
                    return;
                }
                $route = $runtime->routes->fullActionName(
                    (string) $request->getRouteName(),
                    (string) $request->getControllerName(),
                    (string) $request->getActionName()
                );
                // Nothing was routed (a cache hit, a redirect): keep the name the request already has.
                if ($route !== 'unknown') {
                    $this->setRoute($root, $request, $route);
                }
            },
            true
        );

        // The built-in full page cache answers before the router runs, so a cached storefront page has no action
        // name. Without this, most storefront traffic would be named "GET unknown".
        $instrumenter->after(
            'http',
            self::PAGE_CACHE,
            'load',
            function (SpanHandleInterface $root, ?object $subject, array $params, mixed $response): void {
                if (is_object($response)) {
                    $root->setAttribute('bode.magento.page_cache', 'hit');
                    $this->setRoute($root, null, RouteNormalizerInterface::PAGE_CACHE_HIT);
                }
            },
            true
        );

        $instrumenter->after(
            'http',
            self::REST_ROUTER,
            'match',
            function (SpanHandleInterface $root, ?object $subject, array $params, mixed $route) use ($runtime): void {
                if (is_object($route) && method_exists($route, 'getRoutePath')) {
                    $template = $runtime->routes->restTemplate((string) $route->getRoutePath());
                    $this->setRoute($root, $params[0] ?? null, $template);
                    $root->setAttribute('url.path', $template);
                    $root->setAttribute('bode.magento.api', 'rest');
                }
            },
            true
        );

        $instrumenter->after(
            'http',
            self::GRAPHQL,
            'dispatch',
            function (SpanHandleInterface $root, ?object $subject, array $params) use ($runtime): void {
                $request = $params[0] ?? null;
                $operation = $runtime->routes->graphQlOperation($this->graphQlOperationName($request));
                $this->setRoute($root, $request, 'graphql ' . $operation);
                $root->setAttribute('bode.magento.api', 'graphql');
            },
            true
        );
    }

    /**
     * Starts the root span of a request.
     *
     * @param Runtime $runtime
     * @return SpanHandleInterface|null
     */
    private function begin(Runtime $runtime): ?SpanHandleInterface
    {
        $server = $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = $runtime->routes->path($uri);
        if ($runtime->config->isPathExcluded($path)) {
            return null;
        }

        $method = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($server['REQUEST_METHOD'] ?? 'GET')) ?? 'GET');
        // Until a route is known (router, REST, GraphQL, or a page cache hit) the request is "unknown": a redirect or a
        // request that never reaches routing stays that way.
        $attributes = [
            'http.request.method' => $method,
            'http.route' => 'unknown',
            'url.scheme' => (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http',
            'user_agent.original' => $runtime->privacy->userAgent((string) ($server['HTTP_USER_AGENT'] ?? '')),
        ];

        $client = $runtime->privacy->clientAddress((new ClientAddressResolver($runtime->env))->resolve($server));
        if ($client !== null) {
            $attributes['client.address'] = $client;
        }
        foreach ($runtime->env->list('MAGENTO_OTEL_CORRELATION_HEADERS') as $header) {
            $value = $server['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? null;
            if (is_string($value) && $value !== '') {
                $key = 'http.request.header.' . strtolower($header);
                $attributes[$key] = substr($value, 0, self::CORRELATION_VALUE_MAX);
            }
        }

        return $runtime->spans->start(
            $method . ' unknown',
            SpanKind::KIND_SERVER,
            $attributes,
            TraceContextPropagator::getInstance()->extract($this->propagationHeaders($server))
        );
    }

    /**
     * Adds the response facts and releases the client.
     *
     * @param Runtime $runtime
     * @param SpanHandleInterface $span
     * @return void
     */
    private function finish(Runtime $runtime, SpanHandleInterface $span): void
    {
        // No url.path here: a storefront or admin path can carry slugs, ids and one-time keys. The route (full
        // action name) identifies the page; REST gets its route template from the router.
        $status = (int) http_response_code();
        if ($status > 0) {
            $span->setAttribute('http.response.status_code', $status);
            $span->setAttribute('bode.magento.http.status_class', $runtime->routes->statusClass($status));
            if ($status >= 500) {
                $span->markError((string) $status);
            }
        }
        if ($runtime->budget->dropped() > 0) {
            $span->setAttribute('bode.magento.spans.dropped', $runtime->budget->dropped());
        }

        // Hand the response to the client before the SDK flushes at shutdown: telemetry must never add latency.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Names the root span after the resolved route.
     *
     * @param SpanHandleInterface $root
     * @param object|null $request
     * @param string $route
     * @return void
     */
    private function setRoute(SpanHandleInterface $root, ?object $request, string $route): void
    {
        $method = $request !== null && method_exists($request, 'getMethod')
            ? strtoupper((string) $request->getMethod())
            : strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) ?? 'GET');
        $root->setAttribute('http.route', $route);
        $root->setName(trim($method . ' ' . $route));
    }

    /**
     * Operation name from the request body without recording the body.
     *
     * @param object|null $request
     * @return string|null
     */
    private function graphQlOperationName(?object $request): ?string
    {
        if ($request === null || !method_exists($request, 'getContent')) {
            return null;
        }
        try {
            $body = json_decode((string) $request->getContent(), true, 8);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($body)) {
            return null;
        }
        if (isset($body['operationName']) && is_string($body['operationName'])) {
            return $body['operationName'];
        }
        if (isset($body['query']) && is_string($body['query'])
            && preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $body['query'], $m) === 1
        ) {
            return $m[1];
        }

        return null;
    }

    /**
     * Trace context headers of the incoming request.
     *
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    private function propagationHeaders(array $server): array
    {
        $headers = [];
        $map = ['traceparent' => 'HTTP_TRACEPARENT', 'tracestate' => 'HTTP_TRACESTATE', 'baggage' => 'HTTP_BAGGAGE'];
        foreach ($map as $name => $key) {
            if (isset($server[$key]) && is_string($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }

        return $headers;
    }
}
// phpcs:enable Magento2.Security.Superglobal
