<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit;

use Bode\Logs\Test\Unit\Support\Fixture;
use Bode\Logs\Test\Unit\Support\MiniJsonSchema;
use LogicException;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * schema/log-record.schema.json is the contract of the output. Everything the formatter can produce has to satisfy it.
 */
class SchemaTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        $file = dirname(__DIR__, 2) . '/schema/log-record.schema.json';

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,array{0: \Monolog\LogRecord, 1: array<string,string>}>
     */
    public static function records(): array
    {
        $deep = new RuntimeException('outer', 12, new LogicException('inner'));

        return [
            'plain info' => [Fixture::record('hello'), []],
            'every level' => [Fixture::record('boom', Level::Emergency, ['a' => [1, 2, ['b' => null]]]), []],
            'exception in context' => [Fixture::record('failed', Level::Error, ['exception' => $deep]), []],
            'exception as message' => [
                Fixture::record('RuntimeException: x', Level::Critical, ['bode_exception' => $deep]),
                [],
            ],
            'oversized, truncated' => [
                Fixture::record('big', Level::Error, [
                    'exception' => $deep,
                    'blob' => array_fill(0, 90, str_repeat('y', 500)),
                ]),
                ['MAGENTO_OTEL_LOGS_MAX_LINE_BYTES' => '3000'],
            ],
            'unicode and bad bytes' => [Fixture::record("naïve ✓ \xFF", Level::Warning, ['k' => "v\xC3"]), []],
        ];
    }

    /**
     * @param \Monolog\LogRecord $record
     * @param array<string,string> $env
     * @return void
     */
    #[DataProvider('records')]
    public function testEveryLineSatisfiesTheSchema(\Monolog\LogRecord $record, array $env): void
    {
        $line = Fixture::decode(Fixture::formatter($env)->format($record));

        self::assertSame([], MiniJsonSchema::errors($line, $this->schema()));
    }

    public function testTheValidatorCatchesRealViolations(): void
    {
        $line = Fixture::decode(Fixture::formatter()->format(Fixture::record()));
        $line['severity_number'] = 99;
        $line['trace_id'] = 'not-hex';
        unset($line['body']);

        $errors = MiniJsonSchema::errors($line, $this->schema());

        self::assertCount(3, $errors, implode("\n", $errors));
    }
}
