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
    private const MAX_SEGMENTS = 8;
    private const MAX_TEMPLATE_LENGTH = 200;

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
    public function resourceName(string $route, string $controller, string $action): string
    {
        $routePart = $this->pascalWord($route);
        if ($routePart === '') {
            return 'unknown';
        }
        $controllerPart = $this->pascalWord($controller);
        $actionPart = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $action));

        return $routePart . $controllerPart . 'Controller#' . ($actionPart !== '' ? $actionPart : 'unknown');
    }

    /**
     * @inheritdoc
     */
    public function restTemplate(string $path): string
    {
        $segments = explode('/', trim($this->path($path), '/'));
        $truncatedSegments = count($segments) > self::MAX_SEGMENTS;
        if ($truncatedSegments) {
            $segments = array_slice($segments, 0, self::MAX_SEGMENTS);
        }
        $out = [];
        foreach ($segments as $segment) {
            $out[] = $this->isIdentifier($segment) ? ':id' : strtolower($segment);
        }
        $template = '/' . implode('/', $out);
        if ($truncatedSegments) {
            $template .= '/...';
        }
        if (strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            $template = substr($template, 0, self::MAX_TEMPLATE_LENGTH) . '...';
        }

        return $template;
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
     * PascalCase word(s) of a route/controller segment.
     *
     * Non-alphanumeric characters split words, each word is capitalised and lower-cased otherwise, then joined
     * with no separator.
     *
     * @param string $value
     * @return string
     */
    private function pascalWord(string $value): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9]+/', ' ', $value);
        $words = array_filter(explode(' ', trim($clean)), static fn (string $word): bool => $word !== '');

        return implode('', array_map(static fn (string $word): string => ucfirst(strtolower($word)), $words));
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
