<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\Framework\App\ActionFactory;
use Magento\GraphQl\Controller\GraphQl;
use Magento\Webapi\Controller\Rest\Router;
use Magento\Framework\App\State;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\PageCache\Kernel;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use Bode\Tracing\Model\ClientAddressResolver;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Web requests in every area (storefront, admin, REST, GraphQL): one SERVER root span per request.
 *
 * The span is named "<METHOD> <resource>". Magento has no declared route table for storefront/admin/CMS pages (a
 * URL is resolved through the URL Rewrite table, never matched against a literal pattern), so those pages are named
 * after the resolved route/controller/action (CatalogProductController#view), the same way Datadog names resources
 * Controller#action for frameworks without one. REST and GraphQL do have a declared route table, so they keep their
 * route template (/v1/products/:id) and operation name (graphql GetCart); http.route is only ever set for those
 * two, per the OpenTelemetry HTTP spec ("if the route cannot be determined, http.route MUST NOT be set"). A full
 * page cache hit has no controller yet, so it falls back to a best-effort template of the raw path. Never a raw URL,
 * never a query string, in the span name.
 *
 * Identity enrichment (customer or admin id, store, ...) is done by Plugin\EnrichRequestSpan, which has DI.
 */
// The SAPI superglobals are the request here: this runs before Magento builds its request object.
// phpcs:disable Magento2.Security.Superglobal
class HttpInstrumentation implements InstrumentationInterface
{
    /** Magento's own marker for a request forwarded to its "no route" (404) handler (App\Router\Base::NO_ROUTE). */
    private const NO_ROUTE_ACTION = 'noroute';

    private const FRONT_CONTROLLER = FrontController::class;
    private const ACTION_FACTORY = ActionFactory::class;
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

        // Fires once per request, the moment Magento (or its REST/noroute router) instantiates the controller that
        // will handle it: the one place that gives the real PHP class, not the route/controller/action strings.
        $instrumenter->after(
            'http',
            self::ACTION_FACTORY,
            'create',
            function (
                SpanHandleInterface $root,
                ?object $subject,
                array $params,
                mixed $returnValue
            ) use ($runtime): void {
                if (is_object($returnValue)) {
                    $class = get_class($returnValue);
                    $root->setAttribute('code.namespace', $class);
                    $runtime->action->setControllerClass($class);
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
                if (!is_object($request) || !method_exists($request, 'getActionName')) {
                    return;
                }
                $actionName = (string) $request->getActionName();
                if ($actionName === self::NO_ROUTE_ACTION) {
                    // The controller segment of a 404 is whatever the visitor typed (unbounded); only the front
                    // name is validated against a known module before Magento falls back to noroute. Never use the
                    // raw controller segment here, or every probed URL would mint its own resource name.
                    $resource = $runtime->routes->resourceName(
                        (string) $request->getRouteName(),
                        self::NO_ROUTE_ACTION,
                        self::NO_ROUTE_ACTION
                    );
                    $this->setResource($root, $request, $resource);
                    $root->setAttribute('bode.magento.route_resolution', 'noroute');
                    $root->setAttribute('bode.magento.action', $actionName);
                    $runtime->action->setActionName($actionName);

                    return;
                }
                $resource = $runtime->routes->resourceName(
                    (string) $request->getRouteName(),
                    (string) $request->getControllerName(),
                    $actionName
                );
                // Nothing was routed (a cache hit, a redirect): keep the name the request already has.
                if ($resource !== 'unknown') {
                    $this->setResource($root, $request, $resource);
                    $root->setAttribute('bode.magento.action', $actionName);
                    $runtime->action->setActionName($actionName);
                }
            },
            true
        );

        // The built-in full page cache answers before the router runs, so a cached storefront page has no action
        // name: fall back to a best-effort template of the raw path (identifiers collapsed, bounded), the one case
        // where that template is used outside REST. No controller is known, so code.namespace/bode.magento.action
        // are not set here.
        $instrumenter->after(
            'http',
            self::PAGE_CACHE,
            'load',
            function (
                SpanHandleInterface $root,
                ?object $subject,
                array $params,
                mixed $response
            ) use ($runtime): void {
                if (is_object($response)) {
                    $root->setAttribute('bode.magento.page_cache', 'hit');
                    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
                    $this->setResource($root, null, $runtime->routes->restTemplate($uri));
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
                    $this->setResource($root, $params[0] ?? null, $template);
                    $root->setAttribute('http.route', $template);
                    $root->setAttribute('bode.magento.api', 'rest');
                    if (method_exists($route, 'getServiceClass') && method_exists($route, 'getServiceMethod')) {
                        $serviceClass = (string) $route->getServiceClass();
                        $serviceMethod = (string) $route->getServiceMethod();
                        if ($serviceClass !== '') {
                            $root->setAttribute('code.namespace', $serviceClass);
                            $runtime->action->setControllerClass($serviceClass);
                        }
                        if ($serviceMethod !== '') {
                            $root->setAttribute('bode.magento.action', $serviceMethod);
                            $runtime->action->setActionName($serviceMethod);
                        }
                    }
                }
            },
            true
        );

        // GraphQL has no single resolved class per operation (many field resolvers, not one controller): deliberately
        // not setting code.namespace/bode.magento.action here, unlike REST and storefront/admin.
        $instrumenter->after(
            'http',
            self::GRAPHQL,
            'dispatch',
            function (SpanHandleInterface $root, ?object $subject, array $params) use ($runtime): void {
                $request = $params[0] ?? null;
                $operation = $runtime->routes->graphQlOperation($this->graphQlOperationName($request));
                $this->setResource($root, $request, 'graphql ' . $operation);
                $root->setAttribute('http.route', 'graphql ' . $operation);
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
        $runtime->action->reset();
        $server = $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = $runtime->routes->path($uri);
        if ($runtime->config->isPathExcluded($path)) {
            return null;
        }

        $method = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($server['REQUEST_METHOD'] ?? 'GET')) ?? 'GET');
        // Until a resource is known (router, REST, GraphQL, or a page cache hit) the request is "unknown": a
        // redirect or a request that never reaches routing stays that way. url.path is the literal, query-free
        // path: it is set once, here, for every area, independent of whatever resource name ends up on the span.
        $attributes = [
            'http.request.method' => $method,
            'url.path' => $path,
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
     * Names the root span after the resolved resource.
     *
     * Does not set http.route: only REST and GraphQL have a declared route table, and that attribute is set
     * directly at their own call sites.
     *
     * @param SpanHandleInterface $root
     * @param object|null $request
     * @param string $resource
     * @return void
     */
    private function setResource(SpanHandleInterface $root, ?object $request, string $resource): void
    {
        $method = $request !== null && method_exists($request, 'getMethod')
            ? strtoupper((string) $request->getMethod())
            : strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) ?? 'GET');
        $root->setName(trim($method . ' ' . $resource));
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
