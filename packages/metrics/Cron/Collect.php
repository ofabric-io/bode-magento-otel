<?php

declare(strict_types=1);

namespace Bode\Metrics\Cron;

use Bode\Metrics\Model\Collector;

/**
 * Cron job bode_metrics_collect, every minute: exports the state gauges.
 */
class Collect
{
    /**
     * @param Collector $collector
     */
    public function __construct(private readonly Collector $collector)
    {
    }

    /**
     * Runs the collector.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->collector->collect();
    }
}
