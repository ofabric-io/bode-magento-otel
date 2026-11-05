<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

use RuntimeException;

/**
 * Metadata accessor that blows up.
 */
class ThrowingResource
{
    /**
     * Always fails.
     *
     * @return string
     */
    public function getMainTable(): string
    {
        throw new RuntimeException('no table');
    }
}
