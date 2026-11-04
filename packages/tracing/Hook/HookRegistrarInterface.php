<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Closure;

/**
 * Registers a before/after callback pair on a class method. Wraps OpenTelemetry\Instrumentation\hook() so the
 * instrumentation logic can be unit-tested without ext-opentelemetry.
 *
 * @api
 */
interface HookRegistrarInterface
{
    /**
     * Callbacks receive ($subject, array $params, ...); the post callback also receives $returnValue and
     * ?Throwable $exception. Callbacks must not throw.
     *
     * @param string $class
     * @param string $method
     * @param Closure|null $pre function (?object $subject, array $params): ?array; may return modified params
     * @param Closure|null $post function (?object $subject, array $params, mixed $returnValue, ?\Throwable $e): void
     * @return void
     */
    public function register(string $class, string $method, ?Closure $pre, ?Closure $post): void;
}
