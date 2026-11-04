<?php

declare(strict_types=1);

use Bode\Tracing\Hook\Runtime;
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Bode_Tracing', __DIR__);

// Hooks must be registered before Magento runs, so this happens at autoload time. Without ext-opentelemetry, the SDK
// or with MAGENTO_OTEL_ENABLED=false this does nothing and never throws.
Runtime::boot();
