<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Throwable;

/**
 * A started span that is current until end() is called. Implemented over the OpenTelemetry API in production and by
 * a fake in tests.
 *
 * @api
 */
interface SpanHandleInterface
{
    /**
     * Sets an attribute on the span.
     *
     * @param string $name
     * @param bool|int|float|string|array<mixed>|null $value
     * @return void
     */
    public function setAttribute(string $name, mixed $value): void;

    /**
     * Renames the span.
     *
     * @param string $name
     * @return void
     */
    public function setName(string $name): void;

    /**
     * Adds a named point-in-time event, with attributes that are never PII.
     *
     * @param string $name
     * @param array<string,mixed> $attributes
     * @return void
     */
    public function addEvent(string $name, array $attributes = []): void;

    /**
     * Marks the span failed with the exception class only (no message, which may carry personal data).
     *
     * @param Throwable $exception
     * @return void
     */
    public function recordError(Throwable $exception): void;

    /**
     * Marks the span failed without an exception (for example an HTTP 5xx response).
     *
     * @param string $type bounded error type: an exception class or a status code
     * @return void
     */
    public function markError(string $type): void;

    /**
     * Ends the span and makes the previous span current again.
     *
     * @return void
     */
    public function end(): void;
}
