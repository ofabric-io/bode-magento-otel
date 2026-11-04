<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

use Bode\Tracing\Api\TraceContextProviderInterface;

/**
 * Reads the current span from the OpenTelemetry API context. Safe without the SDK: the API classes are looked up
 * lazily, so a project without them simply never has a trace context.
 */
class TraceContextProvider implements TraceContextProviderInterface
{
    /**
     * @inheritdoc
     */
    public function current(): ?array
    {
        if (!class_exists(\OpenTelemetry\API\Trace\Span::class)) {
            return null;
        }
        $context = \OpenTelemetry\API\Trace\Span::getCurrent()->getContext();
        if (!$context->isValid()) {
            return null;
        }

        return [
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
            'trace_flags' => $context->isSampled() ? '01' : '00',
        ];
    }
}
