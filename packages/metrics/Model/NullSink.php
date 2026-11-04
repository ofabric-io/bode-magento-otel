<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * Discards everything. Used when OpenTelemetry is not available.
 */
// Doing nothing is the whole point of this class.
// phpcs:disable Magento2.CodeAnalysis.EmptyBlock
class NullSink implements SinkInterface
{
    /**
     * @inheritdoc
     */
    public function add(string $name, int|float $value, array $attributes): void
    {
    }

    /**
     * @inheritdoc
     */
    public function record(string $name, float $value, array $attributes): void
    {
    }

    /**
     * @inheritdoc
     */
    public function gauge(string $name, int|float $value, array $attributes): void
    {
    }

    /**
     * @inheritdoc
     */
    public function flush(): void
    {
    }
}
// phpcs:enable Magento2.CodeAnalysis.EmptyBlock
