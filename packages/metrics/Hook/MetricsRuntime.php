<?php

declare(strict_types=1);

namespace Bode\Metrics\Hook;

use Bode\Metrics\Instrumentation\CommerceMetrics;
use Bode\Metrics\Instrumentation\HttpMetrics;
use Bode\Metrics\Instrumentation\JobMetrics;
use Bode\Metrics\Instrumentation\ServiceMetrics;
use Bode\Metrics\Model\CardinalityGuard;
use Bode\Metrics\Model\LabelPolicy;
use Bode\Metrics\Model\OtelSink;
use Bode\Metrics\Model\Recorder;
use Bode\Tracing\Hook\OtelHookRegistrar;
use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\RouteNormalizer;
use Throwable;

/**
 * Process-wide holder of the recorder, for the static hook callbacks and, through Model\RecorderAccess, for code
 * created by dependency injection. Works without Bode_Tracing being switched on: it only reuses its Config for the
 * "is OpenTelemetry available" decision and its shared contracts.
 */
// The hook callbacks are static and registered before dependency injection exists, so this holder is static by
// necessity; nothing else in the module is.
// phpcs:disable Magento2.Functions.StaticFunction
class MetricsRuntime
{
    /**
     * @var Recorder|null
     */
    private static ?Recorder $recorder = null;

    /**
     * Registers the hooks once per process.
     *
     * A no-op when OpenTelemetry is absent or MAGENTO_OTEL_METRICS_ENABLED is "false".
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$recorder !== null) {
            return;
        }
        try {
            $env = new Environment();
            $switchedOff = strtolower($env->get('MAGENTO_OTEL_METRICS_ENABLED', 'true')) === 'false';
            if ($switchedOff || !(new Config($env))->isEnabled()) {
                return;
            }
            $recorder = new Recorder(new OtelSink(), new LabelPolicy(), new CardinalityGuard());
            $context = new MetricsContext($recorder, new OtelHookRegistrar(), $env, new RouteNormalizer());
            foreach ([new HttpMetrics(), new JobMetrics(), new ServiceMetrics(), new CommerceMetrics()] as $family) {
                $family->register($context);
            }
            self::$recorder = $recorder;
        } catch (Throwable) {
            // Never break the application because metrics could not start.
            self::$recorder = null;
        }
    }

    /**
     * The recorder, or null when metrics are not running in this process.
     *
     * @return Recorder|null
     */
    public static function recorder(): ?Recorder
    {
        return self::$recorder;
    }
}
// phpcs:enable Magento2.Functions.StaticFunction
