<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Monolog\Level;

// phpcs:disable Magento2.Functions.StaticFunction -- a table of constants, nothing to intercept
/**
 * Monolog level to OpenTelemetry severity number and text.
 *
 * See https://opentelemetry.io/docs/specs/otel/logs/data-model/#field-severitynumber
 */
class Severity
{
    /**
     * OpenTelemetry severity number of a level.
     *
     * @param Level $level
     * @return int
     */
    public static function number(Level $level): int
    {
        return match ($level) {
            Level::Debug => 5,
            Level::Info => 9,
            Level::Notice => 10,
            Level::Warning => 13,
            Level::Error => 17,
            Level::Critical => 21,
            Level::Alert => 22,
            Level::Emergency => 24,
        };
    }

    /**
     * Severity text of a level: Monolog's level name in capitals.
     *
     * @param Level $level
     * @return string
     */
    public static function text(Level $level): string
    {
        return strtoupper($level->getName());
    }
}
