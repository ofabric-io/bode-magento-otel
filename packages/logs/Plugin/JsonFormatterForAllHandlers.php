<?php

declare(strict_types=1);

namespace Bode\Logs\Plugin;

use Bode\Logs\Model\Config;
use Bode\Logs\Model\Formatter\JsonLineFormatter;

/**
 * Magento's file handlers (system, debug, exception and every custom handler built on Handler\Base) set a text
 * formatter in their constructor and offer no way to change it. This plugin on Base hands the JSON formatter out
 * instead, so all of them write JSON, including handlers added by other modules.
 *
 * When the file output is switched off (MAGENTO_OTEL_LOGS_OUTPUT without "file") the same plugin makes those handlers
 * decline every record, so a container that ships stdout does not also fill its disk.
 */
class JsonFormatterForAllHandlers
{
    /**
     * @param Config $config
     * @param JsonLineFormatter $formatter
     */
    public function __construct(private readonly Config $config, private readonly JsonLineFormatter $formatter)
    {
    }

    /**
     * Replaces the formatter.
     *
     * @param object $subject the handler
     * @param mixed $result its own formatter
     * @return mixed
     */
    public function afterGetFormatter(object $subject, mixed $result): mixed
    {
        return $this->config->isEnabled() ? $this->formatter : $result;
    }

    /**
     * Makes file handlers decline records when the file output is off.
     *
     * @param object $subject the handler
     * @param callable $proceed
     * @param mixed $record
     * @return bool
     */
    public function aroundIsHandling(object $subject, callable $proceed, mixed $record): bool
    {
        if ($this->config->isEnabled() && !$this->config->isFileOutputEnabled()) {
            return false;
        }

        return (bool) $proceed($record);
    }
}
