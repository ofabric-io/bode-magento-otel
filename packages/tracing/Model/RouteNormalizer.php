<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

use Bode\Tracing\Api\RouteNormalizerInterface;

/**
 * Low-cardinality names for routes. Never returns query strings or ids.
 */
class RouteNormalizer implements RouteNormalizerInterface
{
    private const OPERATION_MAX = 64;

    /**
     * @inheritdoc
     */
    public function path(string $uri): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- parse_url is the right tool here
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            // parse_url fails on things like "//" or "?x=1"; strip by hand.
            $path = (string) preg_replace('/[?#].*$/', '', $uri);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @inheritdoc
     */
    public function fullActionName(string $route, string $controller, string $action): string
    {
        $parts = array_map(
            static fn (string $part): string => strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', $part)),
            [$route, $controller, $action]
        );
        if ($parts[0] === '') {
            return 'unknown';
        }

        return implode('_', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @inheritdoc
     */
    public function restTemplate(string $path): string
    {
        $segments = explode('/', trim($this->path($path), '/'));
        $out = [];
        foreach ($segments as $segment) {
            $out[] = $this->isIdentifier($segment) ? ':id' : $segment;
        }

        return '/' . implode('/', $out);
    }

    /**
     * @inheritdoc
     */
    public function graphQlOperation(?string $operationName): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $operationName);

        return $name === '' ? 'anonymous' : substr($name, 0, self::OPERATION_MAX);
    }

    /**
     * @inheritdoc
     */
    public function statusClass(int $status): string
    {
        return $status >= 200 && $status < 600 ? intdiv($status, 100) . 'xx' : 'other';
    }

    /**
     * True when a segment carries data (id, SKU, email, hash, token) instead of being a route word.
     *
     * Route words in Magento REST are camelCase or kebab-case letters only (carts, mine, search, V1).
     *
     * @param string $segment
     * @return bool
     */
    private function isIdentifier(string $segment): bool
    {
        if ($segment === '' || preg_match('/^V\d+$/', $segment) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z][A-Za-z-]*$/', $segment) !== 1;
    }
}
