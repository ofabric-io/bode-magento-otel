<?php

declare(strict_types=1);

namespace Bode\Tracing\Api;

/**
 * Turns request data into low-cardinality names. Shared with Bode_Metrics.
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
     * Magento full action name (catalog_product_view) built from the route parts, or 'unknown'.
     *
     * @param string $route
     * @param string $controller
     * @param string $action
     * @return string
     */
    public function fullActionName(string $route, string $controller, string $action): string;

    /**
     * REST route template with ids replaced by placeholders: /V1/products/:id.
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
