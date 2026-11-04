<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

use Throwable;

/**
 * Instrumentation must never break the application, so hook failures are swallowed. They are not silent: every one
 * is counted (exposed by Bode_Metrics as telemetry.errors) and, with MAGENTO_OTEL_DEBUG=true, written to the PHP
 * error log.
 */
class ErrorCounter
{
    /**
     * @var array<string,int>
     */
    private array $counts = [];

    /**
     * @param bool $debug
     */
    public function __construct(private readonly bool $debug = false)
    {
    }

    /**
     * Counts a swallowed failure and optionally logs it.
     *
     * @param string $where short, bounded label such as a hook group
     * @param Throwable $error
     * @return void
     */
    public function record(string $where, Throwable $error): void
    {
        $this->counts[$where] = ($this->counts[$where] ?? 0) + 1;
        if ($this->debug) {
            // Debug aid only, off by default; there is no logger available inside a static hook callback.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            error_log(sprintf('[bode-tracing] %s: %s: %s', $where, $error::class, $error->getMessage()));
        }
    }

    /**
     * Swallowed errors by group.
     *
     * @return array<string,int> swallowed errors by group
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * All swallowed errors.
     *
     * @return int
     */
    public function total(): int
    {
        return array_sum($this->counts);
    }
}
