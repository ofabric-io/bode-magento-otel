<?php

declare(strict_types=1);

namespace Bode\Tracing\Api;

use Bode\Tracing\Hook\SpanHandleInterface;

/**
 * Read access, for code created by dependency injection, to what the static hook layer knows. Shared with
 * Bode_Metrics (self-metrics) and used by the plugin and observers of this module.
 *
 * @api
 */
interface TelemetryStateInterface
{
    /**
     * Root span of the current unit of work.
     *
     * @return SpanHandleInterface|null the root span of the current request, job or message, if one is open
     */
    public function rootSpan(): ?SpanHandleInterface;

    /**
     * Spans refused by the budget since the process started.
     *
     * @return int spans refused by the per-trace budget since the process started
     */
    public function droppedSpans(): int;

    /**
     * Hook failures that were swallowed, by group.
     *
     * @return array<string,int> hook failures that were swallowed, by feature group
     */
    public function swallowedErrors(): array;
}
