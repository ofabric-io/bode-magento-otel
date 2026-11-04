<?php

declare(strict_types=1);

namespace Bode\Metrics\Instrumentation;

use Bode\Metrics\Hook\MetricsContext;

/**
 * One family of metrics (http, cron, indexer, ...), registered through the MetricsContext.
 *
 * @api
 */
interface InstrumentationInterface
{
    /**
     * Registers the hooks of this family.
     *
     * @param MetricsContext $context
     * @return void
     */
    public function register(MetricsContext $context): void;
}
