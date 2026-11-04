<?php

declare(strict_types=1);

use Bode\Metrics\Hook\MetricsRuntime;
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Bode_Metrics', __DIR__);

// Hooks must be registered before Magento runs, so this happens at autoload time. Without ext-opentelemetry, the SDK
// or with MAGENTO_OTEL_METRICS_ENABLED=false this does nothing and never throws.
MetricsRuntime::boot();
