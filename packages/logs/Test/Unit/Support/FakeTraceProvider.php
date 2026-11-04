<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Support;

use Bode\Tracing\Api\TraceContextProviderInterface;

class FakeTraceProvider implements TraceContextProviderInterface
{
    /**
     * @param array{trace_id: string, span_id: string, trace_flags: string}|null $context
     */
    public function __construct(private readonly ?array $context)
    {
    }

    /**
     * @inheritdoc
     */
    public function current(): ?array
    {
        return $this->context;
    }
}
