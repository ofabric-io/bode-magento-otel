<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Model\Config;
use Bode\Logs\Test\Unit\Support\Fixture;
use Monolog\Level;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testDefaultsKeepMagentosFilesAndLeaveTheStreamsOff(): void
    {
        $config = Fixture::config();

        self::assertTrue($config->isEnabled());
        self::assertTrue($config->isFatalReporterEnabled());
        self::assertTrue($config->isFileOutputEnabled());
        self::assertFalse($config->isStreamEnabled('stdout'));
        self::assertFalse($config->isStreamEnabled('stderr'));
        self::assertSame(Level::Info, $config->streamLevel());
        self::assertTrue($config->isCallerEnabled());
        self::assertSame(50, $config->stackFrames());
        self::assertSame(5, $config->exceptionDepth());
        self::assertSame(6, $config->contextDepth());
        self::assertSame(8192, $config->maxValueBytes());
        self::assertSame(65536, $config->maxLineBytes());
    }

    public function testReadsTheOutputList(): void
    {
        $config = Fixture::config(['MAGENTO_OTEL_LOGS_OUTPUT' => 'Stdout, file']);

        self::assertTrue($config->isFileOutputEnabled());
        self::assertTrue($config->isStreamEnabled('stdout'));
        self::assertFalse($config->isStreamEnabled('stderr'));
    }

    public function testStreamOnlyOutputTurnsTheFilesOff(): void
    {
        $config = Fixture::config(['MAGENTO_OTEL_LOGS_OUTPUT' => 'stderr']);

        self::assertFalse($config->isFileOutputEnabled());
        self::assertTrue($config->isStreamEnabled('stderr'));
    }

    public function testFallsBackToFilesForAnOutputListWithNothingValid(): void
    {
        $config = Fixture::config(['MAGENTO_OTEL_LOGS_OUTPUT' => 'syslog, kafka']);

        self::assertTrue($config->isFileOutputEnabled());
        self::assertFalse($config->isStreamEnabled('stdout'));
    }

    public function testDisablingTheModuleSwitchesEverythingOff(): void
    {
        $config = Fixture::config(['MAGENTO_OTEL_LOGS_ENABLED' => 'FALSE', 'MAGENTO_OTEL_LOGS_OUTPUT' => 'stdout']);

        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isFatalReporterEnabled());
        self::assertFalse($config->isStreamEnabled('stdout'));
    }

    public function testTheFatalReporterHasItsOwnSwitch(): void
    {
        $config = Fixture::config(['MAGENTO_OTEL_LOGS_FATAL' => 'false']);

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isFatalReporterEnabled());
    }

    public function testMapsTheStreamLevel(): void
    {
        self::assertSame(Level::Warning, Fixture::config(['MAGENTO_OTEL_LOGS_LEVEL' => 'WARN'])->streamLevel());
        self::assertSame(Level::Debug, Fixture::config(['MAGENTO_OTEL_LOGS_LEVEL' => 'debug'])->streamLevel());
        self::assertSame(Level::Info, Fixture::config(['MAGENTO_OTEL_LOGS_LEVEL' => 'nonsense'])->streamLevel());
    }

    public function testKeepsLimitsInSaneBounds(): void
    {
        $config = Fixture::config([
            'MAGENTO_OTEL_LOGS_MAX_LINE_BYTES' => '10',
            'MAGENTO_OTEL_LOGS_MAX_VALUE_BYTES' => '1',
            'MAGENTO_OTEL_LOGS_CONTEXT_DEPTH' => '0',
        ]);

        self::assertSame(2048, $config->maxLineBytes());
        self::assertSame(64, $config->maxValueBytes());
        self::assertSame(1, $config->contextDepth());
    }

    public function testCallerResolutionCanBeSwitchedOff(): void
    {
        self::assertFalse(Fixture::config(['MAGENTO_OTEL_LOGS_CALLER' => 'false'])->isCallerEnabled());
    }
}
