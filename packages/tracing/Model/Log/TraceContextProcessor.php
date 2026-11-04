<?php

declare(strict_types=1);

namespace Bode\Tracing\Model\Log;

/**
 * Monolog processor adding trace_id, span_id and trace_flags to the "extra" of every log record, so a log line can be
 * found from its trace and the other way round. Does nothing when no valid span is current (no extension, no
 * sampling decision, outside a unit of work).
 *
 * In Magento's default line format the extra appears at the end of the line:
 *   [2026-11-04T10:00:00.000000+00:00] main.ERROR: message {...} {"trace_id":"...","span_id":"...","trace_flags":"01"}
 */
class TraceContextProcessor
{
    /**
     * Adds the trace context to a log record.
     *
     * @param array<string,mixed>|object $record Monolog 2 array record or Monolog 3 LogRecord
     * @return array<string,mixed>|object
     */
    public function __invoke(array|object $record): array|object
    {
        $context = $this->traceContext();
        if ($context === null) {
            return $record;
        }

        if (is_array($record)) {
            $record['extra'] = ($record['extra'] ?? []) + $context;

            return $record;
        }
        if (method_exists($record, 'with') && property_exists($record, 'extra')) {
            return $record->with(extra: $record->extra + $context);
        }

        return $record;
    }

    /**
     * Trace and span ids of the current span.
     *
     * @return array<string,string>|null
     */
    protected function traceContext(): ?array
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
