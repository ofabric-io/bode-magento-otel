<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\Instrumenter;
use Bode\Tracing\Hook\SpanHandleInterface;
use Bode\Tracing\Hook\SpanStack;
use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\ErrorCounter;
use Bode\Tracing\Model\SpanBudget;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class InstrumenterTest extends TestCase
{
    /**
     * @var FakeRegistrar
     */
    private FakeRegistrar $registrar;

    /**
     * @var SpanBudget
     */
    private SpanBudget $budget;

    /**
     * @var SpanStack
     */
    private SpanStack $stack;

    /**
     * @var ErrorCounter
     */
    private ErrorCounter $errors;

    protected function setUp(): void
    {
        $this->registrar = new FakeRegistrar();
        $this->budget = new SpanBudget(3);
        $this->stack = new SpanStack();
        $this->errors = new ErrorCounter();
    }

    /**
     * @param array<string,string> $env
     * @return Instrumenter
     */
    private function instrumenter(array $env = []): Instrumenter
    {
        return new Instrumenter(
            $this->registrar,
            new Config(new Environment($env), true),
            $this->budget,
            $this->stack,
            $this->errors
        );
    }

    public function testSwitchedOffGroupRegistersNothing(): void
    {
        $this->instrumenter()->span('sql', 'A', 'b', static fn (): ?SpanHandleInterface => null);

        self::assertSame([], $this->registrar->hooks);
    }

    public function testRuntimeMissingRegistersNothing(): void
    {
        $instrumenter = new Instrumenter(
            $this->registrar,
            new Config(new Environment([]), false),
            $this->budget,
            $this->stack,
            $this->errors
        );
        $instrumenter->span('http', 'A', 'b', static fn (): ?SpanHandleInterface => null);

        self::assertSame([], $this->registrar->hooks);
    }

    public function testSpanIsStartedFinishedAndEnded(): void
    {
        $span = new FakeSpan();
        $this->instrumenter()->span(
            'http',
            'A',
            'b',
            static fn (): SpanHandleInterface => $span,
            static function (SpanHandleInterface $s): void {
                $s->setAttribute('done', true);
            },
            true
        );

        $this->registrar->call('A', 'b');

        self::assertTrue($span->ended);
        self::assertTrue($span->attributes['done']);
        self::assertSame(0, $this->stack->depth());
    }

    public function testExceptionIsRecordedOnTheSpan(): void
    {
        $span = new FakeSpan();
        $this->instrumenter()->span('http', 'A', 'b', static fn (): SpanHandleInterface => $span, null, true);

        $this->registrar->call('A', 'b', new RuntimeException('secret message'));

        self::assertInstanceOf(RuntimeException::class, $span->error);
        self::assertTrue($span->ended);
    }

    public function testBudgetExhaustionStaysBalanced(): void
    {
        $started = [];
        $this->instrumenter()->span('http', 'A', 'b', static function () use (&$started): SpanHandleInterface {
            $started[] = $span = new FakeSpan();

            return $span;
        });

        // Four nested calls with a budget of three: the fourth starts no span but must still balance.
        $this->stack->pushRoot(new FakeSpan());
        $hook = $this->registrar->hooks['A::b'];
        for ($i = 0; $i < 4; $i++) {
            ($hook['pre'])(null, []);
        }
        self::assertCount(3, $started);
        self::assertSame(5, $this->stack->depth());
        for ($i = 0; $i < 4; $i++) {
            ($hook['post'])(null, [], null, null);
        }
        $this->stack->pop();

        self::assertSame(0, $this->stack->depth());
        foreach ($started as $span) {
            self::assertTrue($span->ended);
        }
        self::assertSame(1, $this->budget->droppedTotal());
    }

    public function testFailureInBeginIsCountedNotThrownAndStaysBalanced(): void
    {
        $this->instrumenter()->span('http', 'A', 'b', static function (): never {
            throw new RuntimeException('boom');
        }, null, true);

        $this->registrar->call('A', 'b');

        self::assertSame(['http' => 1], $this->errors->counts());
        self::assertSame(0, $this->stack->depth());
    }

    public function testFailureInFinishStillEndsTheSpan(): void
    {
        $span = new FakeSpan();
        $this->instrumenter()->span(
            'http',
            'A',
            'b',
            static fn (): SpanHandleInterface => $span,
            static function (): never {
                throw new RuntimeException('boom');
            },
            true
        );

        $this->registrar->call('A', 'b');

        self::assertTrue($span->ended);
        self::assertSame(1, $this->errors->total());
    }

    public function testRootOfUnitResetsTheBudgetSoLongRunningProcessesKeepTracing(): void
    {
        $steps = 0;
        $instrumenter = $this->instrumenter();
        $instrumenter->span('cron', 'Job', 'run', static fn (): SpanHandleInterface => new FakeSpan(), null, true);
        $instrumenter->span('cron', 'Step', 'run', static function () use (&$steps): SpanHandleInterface {
            $steps++;

            return new FakeSpan();
        });

        // Five jobs in one process, each running ten steps against a budget of three.
        for ($job = 0; $job < 5; $job++) {
            $this->registrar->callNested('Job::run', 'Step::run', 10);
        }

        // A process-wide counter would stop at 3 spans in total; a per-job budget gives 3 per job.
        self::assertSame(15, $steps);
        self::assertSame(35, $this->budget->droppedTotal());
        self::assertSame(0, $this->stack->depth());
    }
}
