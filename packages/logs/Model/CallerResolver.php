<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

/**
 * Finds the code that wrote a log line: the first frame outside the logging machinery (Monolog, PSR-3, Magento's logger
 * and interception classes, this module). The call site of the logger is the location; the class and method that made
 * the call are the emitter.
 */
class CallerResolver
{
    private const SKIPPED_PREFIXES = [
        'Monolog\\',
        'Psr\\Log\\',
        'Magento\\Framework\\Logger\\',
        'Magento\\Framework\\Interception\\',
        'Magento\\Framework\\ObjectManager\\',
        'Bode\\Logs\\',
    ];
    private const SKIPPED_SUFFIXES = ['\\Interceptor', '\\Proxy'];
    private const DEPTH = 30;

    /**
     * The emitter of the current log call.
     *
     * @param array<int,array<string,mixed>>|null $frames debug_backtrace() frames, null takes the live backtrace
     * @return array{namespace: string|null, function: string|null, file: string|null, line: int|null}|null
     */
    public function resolve(?array $frames = null): ?array
    {
        $frames ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::DEPTH);

        $site = null;
        foreach ($frames as $frame) {
            $class = (string) ($frame['class'] ?? '');
            if ($this->isLoggingMachinery($class)) {
                // Where this machinery was called from is, so far, the best guess for the call site.
                $site = [$frame['file'] ?? null, $frame['line'] ?? null];
                continue;
            }
            if ($site === null) {
                // Not seen the logger yet: our own entry frames before it.
                continue;
            }

            return [
                'namespace' => $class !== '' ? $class : null,
                'function' => isset($frame['function']) ? (string) $frame['function'] : null,
                'file' => $site[0],
                'line' => $site[1] === null ? null : (int) $site[1],
            ];
        }

        return null;
    }

    /**
     * Whether a class belongs to the logging machinery or to generated wrappers around real classes.
     *
     * @param string $class
     * @return bool
     */
    private function isLoggingMachinery(string $class): bool
    {
        if ($class === '') {
            return false;
        }
        foreach (self::SKIPPED_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }
        foreach (self::SKIPPED_SUFFIXES as $suffix) {
            if (str_ends_with($class, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
