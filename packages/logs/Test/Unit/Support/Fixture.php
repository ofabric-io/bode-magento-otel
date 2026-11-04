<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Support;

use Bode\Logs\Model\CallerResolver;
use Bode\Logs\Model\Config;
use Bode\Logs\Model\ExceptionNormalizer;
use Bode\Logs\Model\ExecutionContext;
use Bode\Logs\Model\Formatter\JsonLineFormatter;
use Bode\Logs\Model\RecordAssembler;
use Bode\Logs\Model\ResourceAttributes;
use Bode\Logs\Model\ValueNormalizer;
use Bode\Tracing\Model\ClientAddressResolver;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\Privacy;
use Bode\Tracing\Model\RouteNormalizer;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Builds the module's object graph from plain arrays, the way the tests need it: no Magento, no process environment.
 */
class Fixture
{
    public const TRACE = [
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
        'trace_flags' => '01',
    ];

    /**
     * @param array<string,string> $env
     * @return Config
     */
    public static function config(array $env = []): Config
    {
        return new Config(new Environment($env));
    }

    /**
     * @param array<string,string> $env
     * @param array<string,string>|null $trace
     * @param array<string,mixed> $server
     * @param array<int,string> $argv
     * @param string $sapi
     * @param FakeRequestProvider|null $request
     * @return ExecutionContext
     */
    public static function execution(
        array $env = [],
        array $server = [],
        array $argv = [],
        string $sapi = 'cli',
        ?FakeRequestProvider $request = null
    ): ExecutionContext {
        $environment = new Environment($env);

        return new ExecutionContext(
            $environment,
            new RouteNormalizer(),
            new Privacy($environment),
            new ClientAddressResolver($environment),
            $request ?? new FakeRequestProvider(),
            $server,
            $argv,
            $sapi
        );
    }

    /**
     * @param array<string,string> $env
     * @param array<string,string>|null $trace
     * @param ExecutionContext|null $execution
     * @return RecordAssembler
     */
    public static function assembler(
        array $env = [],
        ?array $trace = self::TRACE,
        ?ExecutionContext $execution = null
    ): RecordAssembler {
        $config = self::config($env);

        return new RecordAssembler(
            $config,
            new FakeTraceProvider($trace),
            new CallerResolver(),
            $execution ?? self::execution($env),
            new ExceptionNormalizer($config),
            new ValueNormalizer($config, new ExceptionNormalizer($config)),
            new ResourceAttributes(new Environment($env + ['OTEL_SERVICE_NAME' => 'shop-test']))
        );
    }

    /**
     * @param array<string,string> $env
     * @param array<string,string>|null $trace
     * @param ExecutionContext|null $execution
     * @return JsonLineFormatter
     */
    public static function formatter(
        array $env = [],
        ?array $trace = self::TRACE,
        ?ExecutionContext $execution = null
    ): JsonLineFormatter {
        $config = self::config($env);

        return new JsonLineFormatter(
            $config,
            self::assembler($env, $trace, $execution),
            new ValueNormalizer($config, new ExceptionNormalizer($config))
        );
    }

    /**
     * @param string $message
     * @param Level $level
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extra
     * @return LogRecord
     */
    public static function record(
        string $message = 'hello',
        Level $level = Level::Info,
        array $context = [],
        array $extra = []
    ): LogRecord {
        return new LogRecord(
            new DateTimeImmutable('2026-11-04 20:03:41.123456', new \DateTimeZone('Europe/Lisbon')),
            'main',
            $level,
            $message,
            $context,
            $extra
        );
    }

    /**
     * Decodes one formatted line and checks it is exactly one line.
     *
     * @param string $line
     * @return array<string,mixed>
     */
    public static function decode(string $line): array
    {
        if (!str_ends_with($line, "\n") || substr_count($line, "\n") !== 1) {
            throw new \RuntimeException('Not exactly one newline-terminated line: ' . json_encode($line));
        }

        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
}
