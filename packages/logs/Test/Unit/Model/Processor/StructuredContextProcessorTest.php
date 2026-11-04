<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model\Processor;

use Bode\Logs\Model\Processor\StructuredContextProcessor;
use Bode\Logs\Model\RecordAssembler;
use Bode\Logs\Test\Unit\Support\Fixture;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StructuredContextProcessorTest extends TestCase
{
    public function testLeavesTheAssembledContextInExtra(): void
    {
        $processor = new StructuredContextProcessor(Fixture::config(), Fixture::assembler());

        $record = $processor(Fixture::record('x', \Monolog\Level::Info, ['k' => 'v']));
        $parts = $record->extra[StructuredContextProcessor::EXTRA_KEY];

        self::assertSame(Fixture::TRACE, $parts['trace']);
        self::assertSame('main', $parts['attributes']['log.channel']);
        self::assertSame(['k' => 'v'], $parts['attributes']['context']);
    }

    public function testAssemblesOnlyOncePerRecord(): void
    {
        $processor = new StructuredContextProcessor(Fixture::config(), Fixture::assembler());
        $once = $processor(Fixture::record());

        self::assertSame($once, $processor($once));
    }

    public function testDoesNothingWhenTheModuleIsDisabled(): void
    {
        $env = ['MAGENTO_OTEL_LOGS_ENABLED' => 'false'];
        $processor = new StructuredContextProcessor(Fixture::config($env), Fixture::assembler($env));
        $record = Fixture::record();

        self::assertSame($record, $processor($record));
    }

    public function testNeverCostsTheLogLineWhenAssemblingFails(): void
    {
        $failing = new class (Fixture::config(), Fixture::assembler()) extends RecordAssembler {
            /**
             * @param \Bode\Logs\Model\Config $config
             * @param RecordAssembler $inner
             */
            public function __construct(\Bode\Logs\Model\Config $config, private readonly RecordAssembler $inner)
            {
            }

            /**
             * @inheritdoc
             */
            public function assemble(LogRecord $record): array
            {
                throw new RuntimeException('assembler broke');
            }
        };
        $processor = new StructuredContextProcessor(Fixture::config(), $failing);
        $record = Fixture::record();

        self::assertSame($record, $processor($record));
    }
}
