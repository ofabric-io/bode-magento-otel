<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Model\CallerResolver;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class CallerResolverTest extends TestCase
{
    public function testReportsTheCodeThatCalledTheLogger(): void
    {
        $frames = [
            ['class' => CallerResolver::class, 'function' => 'resolve', 'file' => '/m/a.php', 'line' => 1],
            ['class' => 'Bode\Logs\Model\RecordAssembler', 'function' => 'assemble', 'file' => '/m/b.php', 'line' => 2],
            ['class' => 'Monolog\Logger', 'function' => 'addRecord', 'file' => '/v/Logger.php', 'line' => 10],
            ['class' => 'Monolog\Logger', 'function' => 'info', 'file' => '/app/Foo.php', 'line' => 42],
            ['class' => 'Acme\Shop\Foo', 'function' => 'doThing', 'file' => '/app/Bar.php', 'line' => 7],
        ];

        self::assertSame([
            'namespace' => 'Acme\Shop\Foo',
            'function' => 'doThing',
            'file' => '/app/Foo.php',
            'line' => 42,
        ], (new CallerResolver())->resolve($frames));
    }

    public function testSeesThroughMagentosInterceptorsAndProxies(): void
    {
        $frames = [
            ['class' => CallerResolver::class, 'function' => 'resolve', 'file' => '/m/a.php', 'line' => 1],
            ['class' => 'Monolog\Logger', 'function' => 'info', 'file' => '/gen/Interceptor.php', 'line' => 3],
            ['class' => 'Magento\Framework\Logger\Monolog\Interceptor', 'function' => 'info',
                'file' => '/gen/I.php', 'line' => 4],
            ['class' => 'Magento\Framework\Interception\Interceptor', 'function' => 'pluginInfo',
                'file' => '/f/I.php', 'line' => 5],
            ['class' => 'Magento\Framework\Logger\LoggerProxy', 'function' => 'info',
                'file' => '/app/Foo.php', 'line' => 99],
            ['class' => 'Psr\Log\LoggerInterface', 'function' => 'info', 'file' => '/app/Foo.php', 'line' => 99],
            ['class' => 'Acme\Shop\Service', 'function' => 'run', 'file' => '/gen/Proxy.php', 'line' => 8],
            ['class' => 'Acme\Shop\Service\Proxy', 'function' => 'run', 'file' => '/app/Ctl.php', 'line' => 11],
        ];

        $caller = (new CallerResolver())->resolve($frames);

        self::assertSame('Acme\Shop\Service', $caller['namespace']);
        self::assertSame('run', $caller['function']);
        self::assertSame('/app/Foo.php', $caller['file']);
        self::assertSame(99, $caller['line']);
    }

    public function testNamesAClosureWithoutAClass(): void
    {
        $frames = [
            ['class' => 'Monolog\Logger', 'function' => 'error', 'file' => '/app/job.php', 'line' => 5],
            ['function' => '{closure}', 'file' => '/app/run.php', 'line' => 9],
        ];

        $caller = (new CallerResolver())->resolve($frames);

        self::assertNull($caller['namespace']);
        self::assertSame('{closure}', $caller['function']);
        self::assertSame('/app/job.php', $caller['file']);
    }

    public function testReturnsNullWhenTheLoggerIsNotOnTheStack(): void
    {
        self::assertNull((new CallerResolver())->resolve([
            ['class' => 'Acme\Shop\Foo', 'function' => 'doThing', 'file' => '/app/Bar.php', 'line' => 7],
        ]));
    }

    public function testResolvesTheRealCallSiteOfARealMonologCall(): void
    {
        $handler = new TestHandler();
        $resolver = new CallerResolver();
        $logger = new Logger('t', [$handler], [
            static fn (LogRecord $record): LogRecord => $record->with(extra: ['caller' => $resolver->resolve()]),
        ]);

        $emitter = new class ($logger) {
            /**
             * @var int
             */
            public int $line = 0;

            /**
             * @param Logger $logger
             */
            public function __construct(private readonly Logger $logger)
            {
            }

            /**
             * @return void
             */
            public function emitFromHere(): void
            {
                $this->line = __LINE__ + 1;
                $this->logger->log(Level::Warning, 'real call');
            }
        };
        $emitter->emitFromHere();

        $caller = $handler->getRecords()[0]->extra['caller'];
        self::assertSame($emitter::class, $caller['namespace']);
        self::assertSame('emitFromHere', $caller['function']);
        self::assertSame(__FILE__, $caller['file']);
        self::assertSame($emitter->line, $caller['line']);
    }
}
