<?php

declare(strict_types=1);

namespace Bode\Tracing\Api;

/**
 * The trace and span ids of the current span, for anything that wants to correlate with traces (log lines,
 * error reports, outgoing messages).
 *
 * @api
 */
interface TraceContextProviderInterface
{
    /**
     * Ids of the current span.
     *
     * @return array{trace_id: string, span_id: string, trace_flags: string}|null null without a valid current span
     *         (no extension, no SDK, outside a unit of work, or tracing disabled)
     */
    public function current(): ?array;
}
