<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\Otel\SpanStarter;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Context handling of started spans. The no-op provider keeps the parent's span context, which makes the parent
 * observable without an SDK: a span started under an open span reports the same trace id, a fresh root does not.
 */
class SpanStarterTest extends TestCase
{
    private const TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const SPAN = 'b7ad6b7169203331';

    /**
     * @var ScopeInterface
     */
    private ScopeInterface $outer;

    protected function setUp(): void
    {
        $this->outer = Span::wrap(SpanContext::create(self::TRACE, self::SPAN))->activate();
    }

    protected function tearDown(): void
    {
        $this->outer->detach();
    }

    public function testAChildSpanJoinsTheCurrentTrace(): void
    {
        $handle = (new SpanStarter(new NoopTracerProvider()))->start('event x');

        self::assertSame(self::TRACE, Span::getCurrent()->getContext()->getTraceId());
        $handle->end();
    }

    public function testAUnitRootStartedInsideAnOpenSpanStartsAFreshTrace(): void
    {
        $handle = (new SpanStarter(new NoopTracerProvider()))->startUnit('cron x');

        self::assertNotSame(
            self::TRACE,
            Span::getCurrent()->getContext()->getTraceId(),
            'a cron job, message or command must not nest under whatever span happens to be open'
        );
        $handle->end();
        self::assertSame(self::TRACE, Span::getCurrent()->getContext()->getTraceId(), 'outer context restored');
    }

    public function testAUnitRootKeepsAnExplicitRemoteParent(): void
    {
        $remote = Span::wrap(SpanContext::createFromRemoteParent('11111111111111111111111111111111', self::SPAN))
            ->storeInContext(Context::getRoot());

        $handle = (new SpanStarter(new NoopTracerProvider()))->startUnit('http x', 2, [], $remote);

        self::assertSame('11111111111111111111111111111111', Span::getCurrent()->getContext()->getTraceId());
        $handle->end();
    }

    public function testAScopeIsNotLeakedWhenActivatingTheSpanFails(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('storeInContext')->willThrowException(new RuntimeException('boom'));
        $span->expects(self::once())->method('end');
        $builder = $this->createStub(SpanBuilderInterface::class);
        foreach (['setSpanKind', 'setParent', 'setAttributes'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('startSpan')->willReturn($span);
        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $provider = $this->createStub(TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracer);

        try {
            (new SpanStarter($provider))->start('x');
            self::fail('the failure must reach the Instrumenter, which counts and swallows it');
        } catch (RuntimeException) {
            self::assertSame(self::TRACE, Span::getCurrent()->getContext()->getTraceId(), 'context untouched');
        }
    }
}
