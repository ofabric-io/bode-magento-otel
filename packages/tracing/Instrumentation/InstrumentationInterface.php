<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Bode\Tracing\Hook\Runtime;

/**
 * One feature of the module (http, cron, cli, ...). Registers its hooks through the Instrumenter of the Runtime, which
 * applies group switches, span budgets and error handling.
 *
 * @api
 */
interface InstrumentationInterface
{
    /**
     * Registers the hooks of this feature.
     *
     * @param Runtime $runtime
     * @return void
     */
    public function register(Runtime $runtime): void;
}
