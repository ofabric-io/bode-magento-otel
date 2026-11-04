<?php

declare(strict_types=1);

/**
 * Run from the root of a throwaway project that has just required the packages (see bin/verify-install).
 */

use Magento\Framework\Component\ComponentRegistrar;

require 'vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$classes = ['Bode\Tracing\Hook\Runtime', 'Bode\Metrics\Hook\MetricsRuntime', 'Bode\Logs\Model\FatalErrorReporter'];
foreach ($classes as $class) {
    class_exists($class) || $fail("missing class $class");
}

$registered = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
foreach (['Bode_Tracing', 'Bode_Metrics', 'Bode_Logs'] as $module) {
    if (!isset($registered[$module]) || !is_file($registered[$module] . '/etc/module.xml')) {
        $fail("$module is not registered");
    }
}

$map = require 'vendor/composer/autoload_classmap.php';
foreach (array_keys($map) as $class) {
    if (str_starts_with($class, 'Bode\\') && str_contains($class, '\Test\\')) {
        $fail("test class in the class map: $class");
    }
}

echo "OK: all modules installed, registered and free of test classes\n";
