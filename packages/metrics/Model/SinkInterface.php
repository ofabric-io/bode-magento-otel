<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * Where measurements end up. The OpenTelemetry implementation is OtelSink; tests use an in-memory one.
 *
 * @api
 */
interface SinkInterface
{
    /**
     * Adds to a counter.
     *
     * @param string $name instrument name from InstrumentCatalog
     * @param int|float $value
     * @param array<string,string> $attributes
     * @return void
     */
    public function add(string $name, int|float $value, array $attributes): void;

    /**
     * Records one observation in a histogram.
     *
     * @param string $name
     * @param float $value
     * @param array<string,string> $attributes
     * @return void
     */
    public function record(string $name, float $value, array $attributes): void;

    /**
     * Sets a gauge to its current value.
     *
     * @param string $name
     * @param int|float $value
     * @param array<string,string> $attributes
     * @return void
     */
    public function gauge(string $name, int|float $value, array $attributes): void;

    /**
     * Exports what was recorded now (short-lived processes and the collector).
     *
     * @return void
     */
    public function flush(): void;
}
