<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook\Otel;

use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * SpanHandleInterface over the OpenTelemetry API.
 */
class OtelSpanHandle implements SpanHandleInterface
{
    /**
     * @param SpanInterface $span
     * @param ScopeInterface $scope
     */
    public function __construct(private readonly SpanInterface $span, private readonly ScopeInterface $scope)
    {
    }

    /**
     * @inheritdoc
     */
    public function setAttribute(string $name, mixed $value): void
    {
        $this->span->setAttribute($name, $value);
    }

    /**
     * @inheritdoc
     */
    public function setName(string $name): void
    {
        $this->span->updateName($name);
    }

    /**
     * @inheritdoc
     */
    public function addEvent(string $name, array $attributes = []): void
    {
        $this->span->addEvent($name, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function recordError(Throwable $exception): void
    {
        $this->span->setAttribute('error.type', $exception::class);
        $this->span->setStatus(StatusCode::STATUS_ERROR);
        // Exception class only: the message and trace can carry personal data and SQL.
        $this->span->addEvent('exception', ['exception.type' => $exception::class]);
    }

    /**
     * @inheritdoc
     */
    public function markError(string $type): void
    {
        $this->span->setAttribute('error.type', $type);
        $this->span->setStatus(StatusCode::STATUS_ERROR);
    }

    /**
     * @inheritdoc
     */
    public function end(): void
    {
        try {
            $this->scope->detach();
        } finally {
            $this->span->end();
        }
    }
}
