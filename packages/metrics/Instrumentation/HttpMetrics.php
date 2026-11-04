<?php

declare(strict_types=1);

namespace Bode\Metrics\Instrumentation;

use Bode\Metrics\Hook\MetricsContext;
use Bode\Tracing\Api\RouteNormalizerInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\PageCache\Kernel;
use Magento\Framework\App\State;
use Magento\GraphQl\Controller\GraphQl;
use Magento\Webapi\Controller\Rest\Router;
use Throwable;

/**
 * http.server.request.duration and the error counter, labelled by route (Magento full action name, REST route
 * template or GraphQL operation), method, status class and area. No paths, no ids, no query strings.
 */
class HttpMetrics implements InstrumentationInterface
{
    /**
     * @var string
     */
    private string $route = 'unknown';

    /**
     * @var string
     */
    private string $area = 'unknown';

    /**
     * @var string
     */
    private string $method = 'GET';

    /**
     * @inheritdoc
     */
    public function register(MetricsContext $context): void
    {
        // MAGENTO_OTEL_ASSUME_WEB exists only so the test harness can drive web scenarios from the CLI.
        if (PHP_SAPI === 'cli' && $context->env->get('MAGENTO_OTEL_ASSUME_WEB') !== 'true') {
            return;
        }

        $context->timed(
            'http',
            Bootstrap::class,
            'run',
            function (float $seconds) use ($context): void {
                $status = (int) http_response_code();
                $class = $status > 0 ? $context->routes->statusClass($status) : 'unknown';
                $attributes = [
                    'http.route' => $this->route,
                    'http.request.method' => $this->method,
                    'http.response.status_class' => $class,
                    'area' => $this->area,
                ];
                $context->recorder->observe('http.server.request.duration', $seconds, $attributes);
                if (in_array($class, ['4xx', '5xx'], true)) {
                    $context->recorder->count('magento.http.server.errors', $attributes);
                }
                $this->route = 'unknown';
                $this->area = 'unknown';
                $context->reportSelf();
            }
        );

        $context->after('http', State::class, 'setAreaCode', function (?object $s, array $params): void {
            $this->area = preg_replace('/[^a-z_]/', '', strtolower((string) ($params[0] ?? ''))) ?: 'unknown';
        });

        $context->after(
            'http',
            FrontController::class,
            'dispatch',
            function (?object $s, array $params) use ($context): void {
                $request = $params[0] ?? null;
                if (is_object($request) && method_exists($request, 'getRouteName')) {
                    $route = $context->routes->fullActionName(
                        (string) $request->getRouteName(),
                        (string) $request->getControllerName(),
                        (string) $request->getActionName()
                    );
                    // Nothing was routed (a cache hit, a redirect): keep the route the request already has.
                    if ($route !== 'unknown') {
                        $this->route = $route;
                    }
                    $this->method = $this->methodOf($request);
                }
            }
        );

        // The built-in full page cache answers before the router runs, so a cached storefront page has no action
        // name: label it explicitly instead of "unknown" (the same value as the trace's http.route).
        $context->after(
            'http',
            Kernel::class,
            'load',
            function (?object $s, array $params, mixed $response): void {
                if (is_object($response)) {
                    $this->route = RouteNormalizerInterface::PAGE_CACHE_HIT;
                }
            }
        );

        $context->after(
            'http',
            Router::class,
            'match',
            function (?object $s, array $params, mixed $route) use ($context): void {
                if (is_object($route) && method_exists($route, 'getRoutePath')) {
                    $this->route = $context->routes->restTemplate((string) $route->getRoutePath());
                    $this->method = $this->methodOf($params[0] ?? null);
                }
            }
        );

        $context->after(
            'http',
            GraphQl::class,
            'dispatch',
            function (?object $s, array $params) use ($context): void {
                $request = $params[0] ?? null;
                $name = null;
                if (is_object($request) && method_exists($request, 'getContent')) {
                    try {
                        $body = json_decode((string) $request->getContent(), true, 8);
                        $name = is_array($body) && is_string($body['operationName'] ?? null)
                            ? $body['operationName']
                            : null;
                    } catch (Throwable) {
                        $name = null;
                    }
                }
                $this->route = 'graphql ' . $context->routes->graphQlOperation($name);
                $this->method = $this->methodOf($request);
            }
        );
    }

    /**
     * HTTP method of a request object, upper-case letters only.
     *
     * @param object|null $request
     * @return string
     */
    private function methodOf(?object $request): string
    {
        $method = $request !== null && method_exists($request, 'getMethod') ? (string) $request->getMethod() : 'GET';

        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $method)) ?: 'GET';
    }
}
