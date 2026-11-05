<?php

declare(strict_types=1);

/**
 * DI assertions against a real, compiled Magento:
 *  - every class under packages/* (excluding the Hook/Instrumentation namespaces, which are built by `new` from the
 *    ext-opentelemetry static hook runtime and were never meant to go through Magento's object manager) resolves
 *    through the compiled object manager in every area that matters: global, frontend, adminhtml, crontab. A
 *    constructor parameter compiled DI can't build (an untyped `object`/`mixed`/array) fails here, not at
 *    `setup:di:compile` time, because compile only validates the graph is wireable, not that every argument type is
 *    one DI can actually hand over.
 *  - Monolog's resolved `handlers` argument still carries Magento's own system/debug/syslog handlers next to Bode's:
 *    a module's di.xml argument replaces the same argument of Magento's primary app/etc/di.xml rather than merging
 *    into it, so declaring `handlers` without repeating Magento's defaults silently drops them.
 *
 * Magento\Framework\App\State::setAreaCode() throws on a second call in the same process (by design - it is meant
 * to be set once per bootstrap, the way a real request/cron/CLI process only ever has one area). So this runs one
 * area per process, the way a real request/cron/CLI process would, with no argument spawning a subprocess per area.
 */

const AREAS = ['global', 'frontend', 'adminhtml', 'crontab'];
const EXCLUDED_PREFIXES = [
    'Bode\\Tracing\\Hook\\',
    'Bode\\Tracing\\Instrumentation\\',
    'Bode\\Metrics\\Hook\\',
    'Bode\\Metrics\\Instrumentation\\',
];
// Exact classes that are real, concrete, DI-registered-nowhere classes by design, not an omission:
//  - Recorder/SinkInterface: the only DI-managed consumer (Collector) depends on RecorderAccess, never on these
//    directly; RecorderAccess falls back to `new Recorder(new NullSink(), ...)` when the static MetricsRuntime
//    singleton has not recorded one, precisely so metrics work before/without the object manager. No preference for
//    SinkInterface is a feature here, not a gap: nothing in the DI graph is ever asked to resolve it.
//  - StreamJsonHandler: only ever constructed through the bode_stdout/bode_stderr *virtual types* in logs/etc/di.xml,
//    which supply the required $stream constructor argument. The bare class has no default for it, same as any
//    Magento class meant to be used only through a virtualType with an argument override.
const EXCLUDED_CLASSES = [
    'Bode\\Metrics\\Model\\Recorder',
    'Bode\\Metrics\\Model\\SinkInterface',
    'Bode\\Logs\\Model\\Handler\\StreamJsonHandler',
];
const PACKAGES = [
    '/packages/tracing' => 'Bode\\Tracing\\',
    '/packages/metrics' => 'Bode\\Metrics\\',
    '/packages/logs' => 'Bode\\Logs\\',
];

/** @return list<string> fully-qualified class names under $dir, namespaced as $namespace, Test/ excluded */
function classesUnder(string $dir, string $namespace, array $excludedPrefixes): array
{
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($dir) + 1);
        if ($relative === 'registration.php') {
            // Not a class - a script that calls ComponentRegistrar::register() at autoload time. class_exists()
            // would make the autoloader execute it again, which throws ("Module ... has been already defined").
            continue;
        }
        if (str_contains($relative, 'Test' . DIRECTORY_SEPARATOR) || str_starts_with($relative, 'Test')) {
            continue;
        }
        $class = $namespace . str_replace(['/', '.php'], ['\\', ''], $relative);
        if (in_array($class, EXCLUDED_CLASSES, true)) {
            continue;
        }
        $excluded = false;
        foreach ($excludedPrefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                $excluded = true;
                break;
            }
        }
        if (!$excluded) {
            $classes[] = $class;
        }
    }
    return $classes;
}

function allClasses(): array
{
    $classes = [];
    foreach (PACKAGES as $dir => $namespace) {
        $classes = array_merge($classes, classesUnder($dir, $namespace, EXCLUDED_PREFIXES));
    }
    sort($classes);
    return $classes;
}

/** One area, this process: instantiate every packages/* class through the compiled object manager. */
function checkArea(string $area): int
{
    require '/magento/app/bootstrap.php';

    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
    $objectManager = $bootstrap->getObjectManager();

    if ($area !== 'global') {
        $appState = $objectManager->get(\Magento\Framework\App\State::class);
        $appState->setAreaCode($area);
        $configLoader = $objectManager->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class);
        $objectManager->configure($configLoader->load($area));
    }

    $failures = [];
    foreach (allClasses() as $class) {
        if (!class_exists($class) && !interface_exists($class)) {
            $failures[] = "$class does not exist (autoload not regenerated?)";
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() && !$reflection->isInterface()) {
            continue;
        }
        try {
            $objectManager->get($class);
        } catch (\Throwable $exception) {
            $failures[] = sprintf('%s failed through the compiled object manager: %s', $class, $exception->getMessage());
        }
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "FAIL  $area: $failure\n");
        }
        return 1;
    }
    echo "ok    $area: all " . count(allClasses()) . " packages/* classes instantiate through the compiled object manager\n";
    return 0;
}

/** Global area, this process: Monolog's resolved handlers keep Magento's defaults next to Bode's. */
function checkMonologHandlers(): int
{
    require '/magento/app/bootstrap.php';

    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
    $objectManager = $bootstrap->getObjectManager();
    $logger = $objectManager->get(\Magento\Framework\Logger\Monolog::class);
    $handlers = $logger->getHandlers();
    $handlerClasses = array_map(static fn (object $handler): string => $handler::class, $handlers);

    // instanceof, not exact class equality: Magento's own Magento_Developer module legitimately substitutes richer
    // subclasses for Debug/Syslog (Magento\Developer\Model\Logger\Handler\Debug extends ...\Handler\Debug), and the
    // JsonFormatterForAllHandlers plugin on Handler\Base makes Magento generate Interceptor subclasses for every
    // handler extending it. Either is still the real handler slot; only its absence is the historical bug.
    $expectedMagentoDefaults = [
        \Magento\Framework\Logger\Handler\System::class,
        \Magento\Framework\Logger\Handler\Debug::class,
        \Magento\Framework\Logger\Handler\Syslog::class,
    ];
    $missing = array_filter(
        $expectedMagentoDefaults,
        static fn (string $expected): bool => !array_any($handlers, static fn (object $h): bool => $h instanceof $expected)
    );
    if ($missing !== []) {
        fwrite(STDERR, sprintf(
            "FAIL  Monolog's resolved handlers argument is missing Magento's defaults: %s (resolved: %s)\n",
            implode(', ', $missing),
            implode(', ', $handlerClasses)
        ));
        return 1;
    }

    $bodeHandlerCount = count(array_filter(
        $handlers,
        static fn (object $handler): bool => $handler instanceof \Bode\Logs\Model\Handler\StreamJsonHandler
    ));
    if ($bodeHandlerCount < 2) {
        fwrite(STDERR, sprintf(
            "FAIL  expected 2 Bode stdout/stderr handlers (StreamJsonHandler), found %d among: %s\n",
            $bodeHandlerCount,
            implode(', ', $handlerClasses)
        ));
        return 1;
    }
    echo "ok    Monolog's resolved handlers keep system/debug/syslog next to Bode's stdout/stderr handlers\n";
    return 0;
}

$area = $argv[1] ?? null;
if ($area !== null) {
    exit(checkArea($area));
}

// Coordinator: one subprocess per area (a fresh PHP process per area, like a real request/cron/CLI process would
// be), then the Monolog check in this (still un-area-coded) process.
$exitCode = 0;
foreach (AREAS as $areaToCheck) {
    $descriptors = [1 => STDOUT, 2 => STDERR];
    $process = proc_open(['php', __FILE__, $areaToCheck], $descriptors, $pipes);
    if ($process === false || proc_close($process) !== 0) {
        $exitCode = 1;
    }
}
if (checkMonologHandlers() !== 0) {
    $exitCode = 1;
}
exit($exitCode);
