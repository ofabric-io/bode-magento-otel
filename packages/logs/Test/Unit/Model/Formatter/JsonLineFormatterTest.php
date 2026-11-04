<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model\Formatter;

use Bode\Logs\Model\Processor\StructuredContextProcessor;
use Bode\Logs\Test\Unit\Support\Fixture;
use JsonSerializable;
use LogicException;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JsonLineFormatterTest extends TestCase
{
    public function testWritesOneJsonLineWithTheOpenTelemetryFields(): void
    {
        $line = Fixture::decode(Fixture::formatter()->format(Fixture::record('Cron job started', Level::Info)));

        self::assertSame('2026-11-04T20:03:41.123456Z', $line['timestamp'], 'UTC with microseconds');
        self::assertSame('INFO', $line['severity_text']);
        self::assertSame(9, $line['severity_number']);
        self::assertSame('Cron job started', $line['body']);
        self::assertSame('main', $line['attributes']['log.channel']);
        self::assertSame('shop-test', $line['resource']['service.name']);
    }

    /**
     * @return array<string,array{Level,string,int}>
     */
    public static function levels(): array
    {
        return [
            'debug' => [Level::Debug, 'DEBUG', 5],
            'info' => [Level::Info, 'INFO', 9],
            'notice' => [Level::Notice, 'NOTICE', 10],
            'warning' => [Level::Warning, 'WARNING', 13],
            'error' => [Level::Error, 'ERROR', 17],
            'critical' => [Level::Critical, 'CRITICAL', 21],
            'alert' => [Level::Alert, 'ALERT', 22],
            'emergency' => [Level::Emergency, 'EMERGENCY', 24],
        ];
    }

    #[DataProvider('levels')]
    public function testMapsEveryMonologLevelToTheOpenTelemetrySeverity(Level $level, string $text, int $number): void
    {
        $line = Fixture::decode(Fixture::formatter()->format(Fixture::record('x', $level)));

        self::assertSame($text, $line['severity_text']);
        self::assertSame($number, $line['severity_number']);
    }

    public function testCarriesTheTraceAndSpanIdsAtTheTopLevel(): void
    {
        $line = Fixture::decode(Fixture::formatter()->format(Fixture::record()));

        self::assertSame(Fixture::TRACE['trace_id'], $line['trace_id']);
        self::assertSame(Fixture::TRACE['span_id'], $line['span_id']);
        self::assertSame('01', $line['trace_flags']);
    }

    public function testLeavesOutTheTraceFieldsWithoutASpan(): void
    {
        $line = Fixture::decode(Fixture::formatter([], null)->format(Fixture::record()));

        self::assertArrayNotHasKey('trace_id', $line);
        self::assertArrayNotHasKey('span_id', $line);
    }

    public function testTakesTheIdsFromAnEarlierProcessorWhenThereIsNoLiveSpan(): void
    {
        $record = Fixture::record('x', Level::Info, [], [
            'trace_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'span_id' => 'bbbbbbbbbbbbbbbb',
            'trace_flags' => '00',
        ]);
        $line = Fixture::decode(Fixture::formatter([], null)->format($record));

        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $line['trace_id']);
        self::assertSame('00', $line['trace_flags']);
        self::assertArrayNotHasKey('extra', $line['attributes'], 'the ids are not repeated inside extra');
    }

    public function testAnExceptionInTheContextBecomesStructuredAttributes(): void
    {
        $e = new RuntimeException('Cannot reserve stock', 42, new LogicException('SKU 123 is not stocked'));
        $line = Fixture::decode(Fixture::formatter()->format(
            Fixture::record('Order failed', Level::Error, ['exception' => $e, 'order_id' => 1001])
        ));
        $attributes = $line['attributes'];

        self::assertSame(RuntimeException::class, $attributes['exception.type']);
        self::assertSame('Cannot reserve stock', $attributes['exception.message']);
        self::assertSame(42, $attributes['exception.code']);
        self::assertStringContainsString('RuntimeException: Cannot reserve stock', $attributes['exception.stacktrace']);
        self::assertSame(LogicException::class, $attributes['exception.cause'][0]['type']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $attributes['error.fingerprint']);
        self::assertSame(['order_id' => 1001], $attributes['context'], 'the exception is not repeated in the context');
    }

    public function testNormalisesWhatCallersPutInTheContext(): void
    {
        $object = new class () {
            /**
             * @var string
             */
            public string $secret = 'not exported';
        };
        $line = Fixture::decode(Fixture::formatter()->format(Fixture::record('x', Level::Info, [
            'at' => new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC')),
            'object' => $object,
            'nan' => NAN,
            'bad' => "caf\xE9",
            'stream' => fopen('php://memory', 'r'),
        ])));
        $context = $line['attributes']['context'];

        self::assertStringStartsWith('2026-01-02T03:04:05', $context['at']);
        self::assertSame(['class' => $object::class], $context['object'], 'objects are named, never dumped');
        self::assertSame('NAN', $context['nan']);
        self::assertStringStartsWith('caf', $context['bad']);
        self::assertTrue(mb_check_encoding($context['bad'], 'UTF-8'));
        self::assertSame('[resource (stream)]', $context['stream']);
    }

    public function testKeepsUnicodeReadableAndSlashesUnescaped(): void
    {
        $raw = Fixture::formatter()->format(Fixture::record('Pagamento recusado em /checkout/onepage ✓'));

        self::assertStringContainsString('Pagamento recusado em /checkout/onepage ✓', $raw);
    }

    public function testRepairsInvalidUtf8InTheMessage(): void
    {
        $line = Fixture::decode(Fixture::formatter()->format(Fixture::record("broken \xC3\x28 bytes")));

        self::assertTrue(mb_check_encoding($line['body'], 'UTF-8'));
    }

    public function testKeepsALineUnderTheConfiguredSizeAndSaysWhatWasShed(): void
    {
        $record = Fixture::record('Big', Level::Error, [
            'exception' => new RuntimeException(str_repeat('boom ', 200)),
            'payload' => array_fill(0, 90, str_repeat('x', 400)),
        ]);
        $raw = Fixture::formatter(['MAGENTO_OTEL_LOGS_MAX_LINE_BYTES' => '4096'])->format($record);
        $line = Fixture::decode($raw);

        self::assertLessThanOrEqual(4096, strlen($raw));
        self::assertNotEmpty($line['attributes']['log.truncated']);
        self::assertSame(RuntimeException::class, $line['attributes']['exception.type'], 'the essentials survive');
    }

    public function testNeverThrowsAndWritesAMinimalRecordInstead(): void
    {
        $failing = new class () implements JsonSerializable {
            /**
             * @return mixed
             */
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('cannot serialise');
            }
        };
        $line = Fixture::decode(Fixture::formatter()->format(
            Fixture::record('still logged', Level::Error, ['bad' => $failing])
        ));

        self::assertSame('still logged', $line['body']);
        self::assertSame('ERROR', $line['severity_text']);
        self::assertStringContainsString('cannot serialise', $line['attributes']['bode.logs.error']);
    }

    public function testUsesTheContextAssembledByTheProcessor(): void
    {
        $assembler = Fixture::assembler();
        $processor = new StructuredContextProcessor(Fixture::config(), $assembler);
        $record = $processor(Fixture::record('x', Level::Info, ['k' => 'v']));

        self::assertArrayHasKey(StructuredContextProcessor::EXTRA_KEY, $record->extra);
        $line = Fixture::decode(Fixture::formatter([], null)->format($record));
        self::assertSame(Fixture::TRACE['trace_id'], $line['trace_id'], 'the ids were taken when the processor ran');
        self::assertSame(['k' => 'v'], $line['attributes']['context']);
    }

    public function testFormatBatchWritesOneLinePerRecord(): void
    {
        $raw = Fixture::formatter()->formatBatch([Fixture::record('a'), Fixture::record('b')]);

        self::assertSame(2, substr_count($raw, "\n"));
    }
}
