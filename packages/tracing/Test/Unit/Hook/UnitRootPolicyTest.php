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

/**
 * One trace per unit of work: only a request, cron job, queue message or CLI command may start a trace; every other
 * span exists only inside one.
 */
class UnitRootPolicyTest extends TestCase
{
    /**
     * @var FakeRegistrar
     */
    private FakeRegistrar $registrar;

    /**
     * @var SpanStack
     */
    private SpanStack $stack;

    /**
     * @var Instrumenter
     */
    private Instrumenter $instrumenter;

    /**
     * @var FakeSpan[]
     */
    private array $started = [];

    protected function setUp(): void
    {
        $this->registrar = new FakeRegistrar();
        $this->stack = new SpanStack();
        $this->instrumenter = new Instrumenter(
            $this->registrar,
            new Config(new Environment([]), true),
            new SpanBudget(50),
            $this->stack,
            new ErrorCounter()
        );
    }

    /**
     * @param string $name
     * @return \Closure
     */
    private function starter(string $name): \Closure
    {
        return function () use ($name): SpanHandleInterface {
            $span = new FakeSpan();
            $span->attributes['name'] = $name;
            $this->started[] = $span;

            return $span;
        };
    }

    public function testASpanWithNoUnitRootIsNotStartedAndIsCountedAsOrphaned(): void
    {
        $this->instrumenter->span('http', 'Event', 'dispatch', $this->starter('event'));

        $this->registrar->call('Event', 'dispatch');
        $this->registrar->call('Event', 'dispatch');

        self::assertSame([], $this->started, 'a fragment trace must never start');
        self::assertSame(2, $this->stack->orphanedTotal());
        self::assertSame(0, $this->stack->depth());
    }

    public function testASpanInsideAUnitRootIsStartedAndNotOrphaned(): void
    {
        $this->instrumenter->span('http', 'Root', 'run', $this->starter('root'), null, true);
        $this->instrumenter->span('http', 'Event', 'dispatch', $this->starter('event'));

        $this->registrar->callNested('Root::run', 'Event::dispatch', 2);

        self::assertCount(3, $this->started);
        self::assertSame(0, $this->stack->orphanedTotal());
        self::assertSame(0, $this->stack->depth());
    }

    public function testASuppressedRootSuppressesEveryDescendant(): void
    {
        // An excluded path: the root's begin returns null, so there is no trace at all.
        $this->instrumenter->span('http', 'Root', 'run', static fn (): ?SpanHandleInterface => null, null, true);
        $this->instrumenter->span('http', 'Sql', 'query', $this->starter('sql'));

        $this->registrar->callNested('Root::run', 'Sql::query', 3);

        self::assertSame([], $this->started);
        self::assertSame(3, $this->stack->orphanedTotal());
        self::assertSame(0, $this->stack->depth());
    }

    public function testRootsAreNotOrphansEvenInsideAnOpenSpan(): void
    {
        $this->instrumenter->span('http', 'Root', 'run', $this->starter('http'), null, true);
        $this->instrumenter->span('cron', 'Job', 'run', $this->starter('cron'), null, true);

        $this->registrar->callNested('Root::run', 'Job::run', 1);

        self::assertCount(2, $this->started);
        self::assertSame(0, $this->stack->orphanedTotal());
    }

    public function testOrphansBetweenUnitsAreReportedOnTheNextRoot(): void
    {
        $this->instrumenter->span('cron', 'Job', 'run', $this->starter('cron'), null, true);
        $this->instrumenter->span('cache', 'Sql', 'query', $this->starter('sql'));
        $this->registrar->call('Sql', 'query');
        $this->registrar->call('Sql', 'query');

        $this->registrar->call('Job', 'run');
        $this->registrar->call('Job', 'run');

        self::assertSame(2, $this->started[0]->attributes['bode.magento.spans.orphaned']);
        self::assertArrayNotHasKey('bode.magento.spans.orphaned', $this->started[1]->attributes);
    }

    public function testBudgetDropsAreReportedOnEveryKindOfRoot(): void
    {
        $instrumenter = new Instrumenter(
            $this->registrar,
            new Config(new Environment([]), true),
            new SpanBudget(1),
            $this->stack,
            new ErrorCounter()
        );
        $instrumenter->span('queue', 'Consumer', 'dispatch', $this->starter('queue'), null, true);
        $instrumenter->span('cache', 'Sql', 'query', $this->starter('sql'));

        $this->registrar->callNested('Consumer::dispatch', 'Sql::query', 3);

        self::assertSame(2, $this->started[0]->attributes['bode.magento.spans.dropped']);
    }

    public function testRootEndRecoversSpansLeakedByMissingPostHooks(): void
    {
        $this->instrumenter->span('http', 'Root', 'run', $this->starter('root'), null, true);
        $this->instrumenter->span('http', 'Inner', 'call', $this->starter('inner'));

        // A fatal inside Inner::call: its post hook never runs.
        ($this->registrar->hooks['Root::run']['pre'])(null, []);
        ($this->registrar->hooks['Inner::call']['pre'])(null, []);
        ($this->registrar->hooks['Root::run']['post'])(null, [], null, null);

        self::assertTrue($this->started[1]->ended, 'the leaked inner span and its scope are closed');
        self::assertTrue($this->started[0]->ended);
        self::assertSame(0, $this->stack->depth(), 'the stack is clean for the next unit');

        // The next unit works normally.
        $this->registrar->callNested('Root::run', 'Inner::call', 1);
        self::assertCount(4, $this->started);
        self::assertSame(0, $this->stack->orphanedTotal());
    }
}
