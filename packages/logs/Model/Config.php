<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Bode\Tracing\Model\Environment;
use Monolog\Level;

/**
 * Module configuration, read from environment variables only (like the rest of the Bode modules).
 *
 * MAGENTO_OTEL_LOGS_ENABLED         "false" leaves Magento's logging exactly as it is
 * MAGENTO_OTEL_LOGS_OUTPUT          comma separated: file (Magento's own var/log files, default), stdout, stderr
 * MAGENTO_OTEL_LOGS_LEVEL           lowest level written to stdout/stderr (default info, like Magento's system.log)
 * MAGENTO_OTEL_LOGS_CALLER          "false" skips resolving the emitting class (saves a backtrace per record)
 * MAGENTO_OTEL_LOGS_STACK_FRAMES    stack frames kept per exception (default 50)
 * MAGENTO_OTEL_LOGS_EXCEPTION_DEPTH previous exceptions followed per exception (default 5)
 * MAGENTO_OTEL_LOGS_CONTEXT_DEPTH   nesting depth kept in the log context (default 6)
 * MAGENTO_OTEL_LOGS_MAX_VALUE_BYTES longest single string value in bytes (default 8192)
 * MAGENTO_OTEL_LOGS_MAX_LINE_BYTES  longest JSON line in bytes (default 65536)
 * MAGENTO_OTEL_LOGS_FATAL           "false" does not register the fatal error and uncaught exception reporter
 */
class Config
{
    public const OUTPUTS = ['file', 'stdout', 'stderr'];
    public const DEFAULT_STACK_FRAMES = 50;
    public const DEFAULT_EXCEPTION_DEPTH = 5;
    public const DEFAULT_CONTEXT_DEPTH = 6;
    public const DEFAULT_MAX_VALUE_BYTES = 8192;
    public const DEFAULT_MAX_LINE_BYTES = 65536;
    private const MIN_LINE_BYTES = 2048;

    /**
     * @var string[]
     */
    private array $outputs;

    /**
     * @param Environment $env
     */
    public function __construct(private readonly Environment $env)
    {
        $requested = array_map('strtolower', $env->list('MAGENTO_OTEL_LOGS_OUTPUT'));
        $this->outputs = array_values(array_intersect(self::OUTPUTS, $requested)) ?: ['file'];
    }

    /**
     * Whether the module changes anything.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return strtolower($this->env->get('MAGENTO_OTEL_LOGS_ENABLED', 'true')) !== 'false';
    }

    /**
     * Whether the fatal error and uncaught exception reporter is registered.
     *
     * @return bool
     */
    public function isFatalReporterEnabled(): bool
    {
        return $this->isEnabled() && strtolower($this->env->get('MAGENTO_OTEL_LOGS_FATAL', 'true')) !== 'false';
    }

    /**
     * Whether Magento's own file handlers (var/log/*.log) keep writing.
     *
     * @return bool
     */
    public function isFileOutputEnabled(): bool
    {
        return in_array('file', $this->outputs, true);
    }

    /**
     * Whether JSON goes to the given stream.
     *
     * @param string $stream stdout or stderr
     * @return bool
     */
    public function isStreamEnabled(string $stream): bool
    {
        return $this->isEnabled() && in_array($stream, $this->outputs, true);
    }

    /**
     * Lowest level written to stdout/stderr.
     *
     * @return Level
     */
    public function streamLevel(): Level
    {
        return match (strtolower($this->env->get('MAGENTO_OTEL_LOGS_LEVEL', 'info'))) {
            'debug' => Level::Debug,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    /**
     * Whether the emitting class is resolved.
     *
     * @return bool
     */
    public function isCallerEnabled(): bool
    {
        return strtolower($this->env->get('MAGENTO_OTEL_LOGS_CALLER', 'true')) !== 'false';
    }

    /**
     * Stack frames kept per exception.
     *
     * @return int
     */
    public function stackFrames(): int
    {
        return $this->env->int('MAGENTO_OTEL_LOGS_STACK_FRAMES', self::DEFAULT_STACK_FRAMES);
    }

    /**
     * Previous exceptions followed per exception.
     *
     * @return int
     */
    public function exceptionDepth(): int
    {
        return $this->env->int('MAGENTO_OTEL_LOGS_EXCEPTION_DEPTH', self::DEFAULT_EXCEPTION_DEPTH);
    }

    /**
     * Nesting depth kept in the log context.
     *
     * @return int
     */
    public function contextDepth(): int
    {
        return max(1, $this->env->int('MAGENTO_OTEL_LOGS_CONTEXT_DEPTH', self::DEFAULT_CONTEXT_DEPTH));
    }

    /**
     * Longest single string value, in bytes.
     *
     * @return int
     */
    public function maxValueBytes(): int
    {
        return max(64, $this->env->int('MAGENTO_OTEL_LOGS_MAX_VALUE_BYTES', self::DEFAULT_MAX_VALUE_BYTES));
    }

    /**
     * Longest JSON line, in bytes.
     *
     * @return int
     */
    public function maxLineBytes(): int
    {
        return max(
            self::MIN_LINE_BYTES,
            $this->env->int('MAGENTO_OTEL_LOGS_MAX_LINE_BYTES', self::DEFAULT_MAX_LINE_BYTES)
        );
    }
}
