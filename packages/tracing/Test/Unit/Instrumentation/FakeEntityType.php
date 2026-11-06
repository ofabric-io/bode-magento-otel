<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

/**
 * Stand-in for an EAV entity type.
 */
class FakeEntityType
{
    /**
     * @param string $code
     */
    public function __construct(private readonly string $code)
    {
    }

    /**
     * Entity type code.
     *
     * @return string
     */
    public function getEntityTypeCode(): string
    {
        return $this->code;
    }
}
