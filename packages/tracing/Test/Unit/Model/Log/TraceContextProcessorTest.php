<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Model\Log;

use Bode\Tracing\Model\Log\TraceContextProcessor;
use PHPUnit\Framework\TestCase;

class TraceContextProcessorTest extends TestCase
{
    private const CONTEXT = [
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
        'trace_flags' => '01',
    ];

    /**
     * @param array<string,string>|null $context
     * @return TraceContextProcessor
     */
    private function processor(?array $context): TraceContextProcessor
    {
        return new class ($context) extends TraceContextProcessor {
            /**
             * @param array<string,string>|null $context
             */
            public function __construct(private readonly ?array $context)
            {
            }

            /**
             * @inheritdoc
             */
            protected function traceContext(): ?array
            {
                return $this->context;
            }
        };
    }

    public function testAddsTraceFieldsToAMonolog2Record(): void
    {
        $record = ($this->processor(self::CONTEXT))(['message' => 'x', 'extra' => ['a' => 1]]);

        self::assertSame(['a' => 1] + self::CONTEXT, $record['extra']);
    }

    public function testAddsTraceFieldsToAMonolog3Record(): void
    {
        $record = new class {
            /**
             * @var array<string,mixed>
             */
            public array $extra = [];

            /**
             * @param array<string,mixed> $extra
             * @return self
             */
            public function with(array $extra): self
            {
                $clone = clone $this;
                $clone->extra = $extra;

                return $clone;
            }
        };

        $out = ($this->processor(self::CONTEXT))($record);

        self::assertSame(self::CONTEXT, $out->extra);
        self::assertSame([], $record->extra, 'the original record is immutable');
    }

    public function testLeavesRecordsAloneWithoutAnActiveSpan(): void
    {
        $record = ['message' => 'x', 'extra' => []];

        self::assertSame($record, ($this->processor(null))($record));
    }

    public function testRealProcessorIsANoOpWithoutASpan(): void
    {
        $record = ['message' => 'x', 'extra' => []];

        self::assertSame($record, (new TraceContextProcessor())($record));
    }
}
