<?php

declare(strict_types=1);

namespace Bode\Logs\Model\Handler;

use Bode\Logs\Model\Config;
use Bode\Logs\Model\Formatter\JsonLineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\LogRecord;

/**
 * Writes the JSON lines to stdout or stderr, for containers that ship logs from the process streams. Registered on
 * Magento's logger in di.xml (once per stream) and silent unless MAGENTO_OTEL_LOGS_OUTPUT names its stream, so by
 * default it costs nothing and opens nothing.
 */
class StreamJsonHandler extends StreamHandler
{
    /**
     * @var bool
     */
    private readonly bool $active;

    /**
     * @param string $stream php://stdout or php://stderr
     * @param Config $config
     * @param JsonLineFormatter $formatter
     */
    public function __construct(string $stream, Config $config, JsonLineFormatter $formatter)
    {
        parent::__construct($stream, $config->streamLevel());
        $this->setFormatter($formatter);
        $this->active = $config->isStreamEnabled(str_contains($stream, 'stderr') ? 'stderr' : 'stdout');
    }

    /**
     * @inheritdoc
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->active && parent::isHandling($record);
    }
}
