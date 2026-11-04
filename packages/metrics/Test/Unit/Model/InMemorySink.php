<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Model;

use Bode\Metrics\Model\SinkInterface;

/**
 * Test double that keeps what was recorded.
 */
class InMemorySink implements SinkInterface
{
    /**
     * @var array<int,array{string,string,int|float,array<string,string>}>
     */
    public array $points = [];

    /**
     * @var int
     */
    public int $flushes = 0;

    /**
     * @var bool
     */
    public bool $failing = false;

    /**
     * @inheritdoc
     */
    public function add(string $name, int|float $value, array $attributes): void
    {
        $this->push('add', $name, $value, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function record(string $name, float $value, array $attributes): void
    {
        $this->push('record', $name, $value, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function gauge(string $name, int|float $value, array $attributes): void
    {
        $this->push('gauge', $name, $value, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function flush(): void
    {
        $this->flushes++;
    }

    /**
     * Stores one point, or fails on request.
     *
     * @param string $kind
     * @param string $name
     * @param int|float $value
     * @param array<string,string> $attributes
     * @return void
     */
    private function push(string $kind, string $name, int|float $value, array $attributes): void
    {
        if ($this->failing) {
            throw new \RuntimeException('sink down');
        }
        $this->points[] = [$kind, $name, $value, $attributes];
    }
}
