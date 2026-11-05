<?php

declare(strict_types=1);

/**
 * One-shot process whose only job is to let a real, uncaught exception reach the exception handler that
 * Bode\Logs\Model\FatalErrorReporter::register() installs from packages/logs/registration.php at Composer autoload
 * time - before this script's own code runs, same as it would in any real PHP process serving this Magento.
 *
 * Run as a fresh subprocess (see the `uncaught` case in run.php), not required()'d into the long-lived scenario
 * runner process: set_exception_handler()/register_shutdown_function() install globally for the rest of the
 * process, so throwing here inside run.php's own process would hijack every scenario that ran after it.
 */

require '/magento/app/bootstrap.php';

// Bootstrapping (not running) Magento is enough: it loads app/etc/env.php via DeploymentConfig and constructs the
// object manager, which is what FatalErrorReporter's own dependencies (Config, ResourceAttributes, trace context)
// need to be available the same way they are in a real request/CLI process. Nothing here needs the object manager
// directly - FatalErrorReporter already built and installed itself at autoload time, before this line runs.
\Magento\Framework\App\Bootstrap::create(BP, $_SERVER);

throw new \RuntimeException('e2e trigger: uncaught exception');
