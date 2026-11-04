<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model\Handler;

use Bode\Logs\Model\Handler\StreamJsonHandler;
use Bode\Logs\Test\Unit\Support\Fixture;
use Monolog\Level;
use PHPUnit\Framework\TestCase;

class StreamJsonHandlerTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @param string $stream
     * @return StreamJsonHandler
     */
    private function handler(array $env, string $stream = 'php://memory'): StreamJsonHandler
    {
        return new StreamJsonHandler($stream, Fixture::config($env), Fixture::formatter($env));
    }

    public function testIsSilentByDefault(): void
    {
        self::assertFalse($this->handler([])->isHandling(Fixture::record('x', Level::Emergency)));
    }

    public function testWritesJsonLinesWhenItsStreamIsSelected(): void
    {
        $handler = $this->handler(['MAGENTO_OTEL_LOGS_OUTPUT' => 'stdout']);
        $record = Fixture::record('to the stream', Level::Error);

        self::assertTrue($handler->isHandling($record));
        $handler->handle($record);

        $stream = $handler->getStream();
        self::assertIsResource($stream);
        rewind($stream);
        $line = Fixture::decode((string) stream_get_contents($stream));
        self::assertSame('to the stream', $line['body']);
        self::assertSame('ERROR', $line['severity_text']);
    }

    public function testRespectsTheMinimumLevel(): void
    {
        $handler = $this->handler(['MAGENTO_OTEL_LOGS_OUTPUT' => 'stdout', 'MAGENTO_OTEL_LOGS_LEVEL' => 'warning']);

        self::assertFalse($handler->isHandling(Fixture::record('x', Level::Info)));
        self::assertTrue($handler->isHandling(Fixture::record('x', Level::Warning)));
    }

    public function testEachStreamHandlerOnlyFollowsItsOwnSwitch(): void
    {
        $env = ['MAGENTO_OTEL_LOGS_OUTPUT' => 'stderr'];

        self::assertFalse($this->handler($env, 'php://stdout')->isHandling(Fixture::record('x', Level::Error)));
        self::assertTrue($this->handler($env, 'php://stderr')->isHandling(Fixture::record('x', Level::Error)));
    }

    public function testADisabledModuleWritesNothing(): void
    {
        $handler = $this->handler(['MAGENTO_OTEL_LOGS_ENABLED' => 'false', 'MAGENTO_OTEL_LOGS_OUTPUT' => 'stdout']);

        self::assertFalse($handler->isHandling(Fixture::record('x', Level::Error)));
    }
}
