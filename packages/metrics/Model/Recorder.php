<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

use Throwable;

/**
 * The one entry point for recording a measurement. Applies the cardinality contract (LabelPolicy allow-list, then
 * CardinalityGuard overflow) before anything reaches the sink, and never throws.
 */
class Recorder
{
    /**
     * @var int
     */
    private int $failures = 0;

    /**
     * @param SinkInterface $sink
     * @param LabelPolicy $policy
     * @param CardinalityGuard $guard
     */
    public function __construct(
        private readonly SinkInterface $sink,
        private readonly LabelPolicy $policy,
        private readonly CardinalityGuard $guard
    ) {
    }

    /**
     * Adds to a counter.
     *
     * @param string $instrument
     * @param array<string,scalar|null> $attributes
     * @param int|float $value
     * @return void
     */
    public function count(string $instrument, array $attributes = [], int|float $value = 1): void
    {
        $this->safely(fn () => $this->sink->add($instrument, $value, $this->prepare($instrument, $attributes)));
    }

    /**
     * Records a duration in seconds.
     *
     * @param string $instrument
     * @param float $seconds
     * @param array<string,scalar|null> $attributes
     * @return void
     */
    public function observe(string $instrument, float $seconds, array $attributes = []): void
    {
        $this->safely(fn () => $this->sink->record($instrument, $seconds, $this->prepare($instrument, $attributes)));
    }

    /**
     * Sets a gauge.
     *
     * @param string $instrument
     * @param int|float $value
     * @param array<string,scalar|null> $attributes
     * @return void
     */
    public function gauge(string $instrument, int|float $value, array $attributes = []): void
    {
        $this->safely(fn () => $this->sink->gauge($instrument, $value, $this->prepare($instrument, $attributes)));
    }

    /**
     * Exports now.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->safely(fn () => $this->sink->flush());
    }

    /**
     * Measurements lost to a sink failure.
     *
     * @return int
     */
    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * Allow-list, then overflow guard.
     *
     * @param string $instrument
     * @param array<string,scalar|null> $attributes
     * @return array<string,string>
     */
    private function prepare(string $instrument, array $attributes): array
    {
        return $this->guard->guard($this->policy->filter($instrument, $attributes));
    }

    /**
     * Runs a sink call; a failure is counted, never thrown.
     *
     * @param callable $call
     * @return void
     */
    private function safely(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            $this->failures++;
        }
    }
}
