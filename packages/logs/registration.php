<?php

declare(strict_types=1);

use Bode\Logs\Model\FatalErrorReporter;
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Bode_Logs', __DIR__);

// Uncaught exceptions and fatal errors end the process before Magento's logger can see them. Registered at autoload
// time, like the other Bode modules' hooks; with MAGENTO_OTEL_LOGS_ENABLED=false or MAGENTO_OTEL_LOGS_FATAL=false this
// does nothing, and it never throws.
FatalErrorReporter::register();
