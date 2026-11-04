<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

use Bode\Metrics\Hook\MetricsRuntime;

/**
 * Gives DI-created code (observers, the collector) the process-wide recorder. When metrics are not running it hands
 * out a recorder over a sink that discards everything, so callers never need a null check.
 */
class RecorderAccess
{
    /**
     * @var Recorder|null
     */
    private ?Recorder $fallback = null;

    /**
     * The recorder to use.
     *
     * @return Recorder
     */
    public function recorder(): Recorder
    {
        return MetricsRuntime::recorder() ?? $this->fallback ??= new Recorder(
            new NullSink(),
            new LabelPolicy(),
            new CardinalityGuard()
        );
    }

    /**
     * Whether measurements are really being exported.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return MetricsRuntime::recorder() !== null;
    }
}
