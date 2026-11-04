<?php

declare(strict_types=1);

namespace Bode\Logs\Model\Processor;

use Bode\Logs\Model\Config;
use Bode\Logs\Model\RecordAssembler;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Monolog processor that assembles the structured part of a record once, while the code that logged is still on the
 * stack, and leaves it in the record's extra for the JSON formatter. Processors run once per record, however many
 * handlers write it, so the backtrace and the trace ids are taken exactly once.
 *
 * A failure here must never cost the log line: the formatter assembles again, or falls back to a minimal line.
 */
class StructuredContextProcessor implements ProcessorInterface
{
    public const EXTRA_KEY = 'bode_log';

    /**
     * @param Config $config
     * @param RecordAssembler $assembler
     */
    public function __construct(private readonly Config $config, private readonly RecordAssembler $assembler)
    {
    }

    /**
     * Adds the assembled context to the record's extra.
     *
     * @param LogRecord $record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (!$this->config->isEnabled() || isset($record->extra[self::EXTRA_KEY])) {
            return $record;
        }
        try {
            return $record->with(extra: $record->extra + [self::EXTRA_KEY => $this->assembler->assemble($record)]);
        } catch (Throwable) {
            return $record;
        }
    }
}
