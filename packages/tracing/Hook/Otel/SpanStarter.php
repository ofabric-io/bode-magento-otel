<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook\Otel;

use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * Starts spans through the global tracer provider (configured from OTEL_* env by the SDK autoloader).
 */
class SpanStarter
{
    public const SCOPE = 'bode.magento';

    /**
     * Starts a span and makes it current.
     *
     * @param string $name span name; must be low-cardinality
     * @param 0|1|2|3|4 $kind a SpanKind constant
     * @param array<string,mixed> $attributes
     * @param ContextInterface|null $parent null uses the current context
     * @return SpanHandleInterface
     */
    public function start(
        string $name,
        int $kind = SpanKind::KIND_INTERNAL,
        array $attributes = [],
        ?ContextInterface $parent = null
    ): SpanHandleInterface {
        $parent ??= Context::getCurrent();
        $span = Globals::tracerProvider()
            ->getTracer(self::SCOPE)
            ->spanBuilder($name)
            ->setSpanKind($kind)
            ->setParent($parent)
            ->setAttributes($attributes)
            ->startSpan();

        return new OtelSpanHandle($span, $span->storeInContext($parent)->activate());
    }
}
