<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook\Otel;

use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * Starts spans through the global tracer provider (configured from OTEL_* env by the SDK autoloader).
 */
class SpanStarter
{
    public const SCOPE = 'bode.magento';

    /**
     * Creates the starter.
     *
     * @param TracerProviderInterface|null $provider null uses the global provider (tests inject their own)
     */
    public function __construct(private readonly ?TracerProviderInterface $provider = null)
    {
    }

    /**
     * Starts a child span and makes it current.
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
        return $this->begin($name, $kind, $attributes, $parent ?? Context::getCurrent());
    }

    /**
     * Starts the root span of a unit of work (request, cron job, queue message, CLI command) and makes it current.
     * Unlike start() it never inherits the current context: a job run while some other span is open is a new trace,
     * not a child of it. Only a remote parent extracted from incoming headers is honoured.
     *
     * @param string $name span name; must be low-cardinality
     * @param 0|1|2|3|4 $kind a SpanKind constant
     * @param array<string,mixed> $attributes
     * @param ContextInterface|null $remoteParent context extracted from a request, null starts a fresh trace
     * @return SpanHandleInterface
     */
    public function startUnit(
        string $name,
        int $kind = SpanKind::KIND_INTERNAL,
        array $attributes = [],
        ?ContextInterface $remoteParent = null
    ): SpanHandleInterface {
        return $this->begin($name, $kind, $attributes, $remoteParent ?? Context::getRoot());
    }

    /**
     * Starts the span under $parent and activates it.
     *
     * @param string $name
     * @param 0|1|2|3|4 $kind
     * @param array<string,mixed> $attributes
     * @param ContextInterface $parent
     * @return SpanHandleInterface
     */
    private function begin(string $name, int $kind, array $attributes, ContextInterface $parent): SpanHandleInterface
    {
        $span = ($this->provider ?? Globals::tracerProvider())
            ->getTracer(self::SCOPE)
            ->spanBuilder($name)
            ->setSpanKind($kind)
            ->setParent($parent)
            ->setAttributes($attributes)
            ->startSpan();

        try {
            $scope = $span->storeInContext($parent)->activate();
        } catch (Throwable $e) {
            // Never leave a started span without a scope owner: it would never be ended.
            $span->end();

            throw $e;
        }

        return new OtelSpanHandle($span, $scope);
    }
}
