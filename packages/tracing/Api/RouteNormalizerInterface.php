<?php

declare(strict_types=1);

namespace Bode\Tracing\Api;

/**
 * Turns request data into low-cardinality names. Shared with Bode_Metrics and Bode_Logs.
 *
 * Magento has no declared URL route table for storefront, admin or CMS pages (unlike REST or GraphQL): a path such
 * as /some-product.html is resolved through the URL Rewrite table to an arbitrary route/controller/action, not
 * matched against a literal pattern. Per the OpenTelemetry HTTP spec, a name must not be fabricated for a route that
 * was never declared, so those pages are named after the resolved route/controller/action instead of the URL
 * (resourceName()), the same way Datadog names resources Controller#action for frameworks without a route table.
 *
 * @api
 */
interface RouteNormalizerInterface
{
    /**
     * Path without query string, fragment or scheme/host.
     *
     * @param string $uri a request URI or URL
     * @return string
     */
    public function path(string $uri): string;

    /**
     * Datadog-style resource name for a resolved route with no declared route table:
     * {Route}{Controller}Controller#{action}, for example CatalogProductController#view. 'unknown' when the route
     * part is empty (nothing resolved yet).
     *
     * @param string $route
     * @param string $controller
     * @param string $action
     * @return string
     */
    public function resourceName(string $route, string $controller, string $action): string;

    /**
     * Route template with ids replaced by placeholders and route words lower-cased: /v1/products/:id. Used for the
     * REST API (a declared route table) and, as a best-effort fallback, for a full page cache hit (no controller is
     * known yet, only the raw request path). Bounded: long paths are truncated, visibly.
     *
     * @param string $path
     * @return string
     */
    public function restTemplate(string $path): string;

    /**
     * Bounded, safe GraphQL operation name, or 'anonymous'.
     *
     * @param string|null $operationName
     * @return string
     */
    public function graphQlOperation(?string $operationName): string;

    /**
     * Class of an HTTP status: 2xx, 3xx, 4xx, 5xx or other.
     *
     * @param int $status
     * @return string
     */
    public function statusClass(int $status): string;
}
