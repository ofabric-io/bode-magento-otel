<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

use Bode\Tracing\Api\TelemetryStateInterface;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;

/**
 * Bridges DI-created code to the process-wide hook runtime. All methods are safe when telemetry is not running.
 */
class TelemetryState implements TelemetryStateInterface
{
    /**
     * @inheritdoc
     */
    public function rootSpan(): ?SpanHandleInterface
    {
        return Runtime::get()?->stack->bottom();
    }

    /**
     * @inheritdoc
     */
    public function droppedSpans(): int
    {
        return Runtime::get()?->budget->droppedTotal() ?? 0;
    }

    /**
     * @inheritdoc
     */
    public function swallowedErrors(): array
    {
        return Runtime::get()?->errors->counts() ?? [];
    }

    /**
     * @inheritdoc
     */
    public function resolvedControllerClass(): ?string
    {
        return Runtime::get()?->action->controllerClass();
    }

    /**
     * @inheritdoc
     */
    public function resolvedActionName(): ?string
    {
        return Runtime::get()?->action->actionName();
    }
}
