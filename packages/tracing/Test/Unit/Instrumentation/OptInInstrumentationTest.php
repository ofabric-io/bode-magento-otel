<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Instrumentation\OptInInstrumentation;
use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Test\Unit\Hook\FakeSpan;
use Bode\Tracing\Test\Unit\Hook\GuardedFakeRegistrar;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real SDK, real parent ids: what Magento's generated Interceptor and Proxy do to the sql and events hooks.
 */
class OptInInstrumentationTest extends TestCase
{
    private const MYSQL = 'Magento\Framework\DB\Adapter\Pdo\Mysql';
    private const MANAGER = 'Magento\Framework\Event\Manager';

    /**
     * @var GuardedFakeRegistrar
     */
    private GuardedFakeRegistrar $registrar;

    /**
     * @var Runtime
     */
    private Runtime $runtime;

    /**
     * @var InMemoryExporter
     */
    private InMemoryExporter $exporter;

    /**
     * @var ScopeInterface
     */
    private ScopeInterface $scope;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->scope = Sdk::builder()->setTracerProvider($provider)->buildAndRegisterGlobal();
        $this->registrar = new GuardedFakeRegistrar();
        $env = new Environment(['MAGENTO_OTEL_TRACING_GROUPS' => 'sql,events']);
        $this->runtime = new Runtime(new Config($env, true), $env, $this->registrar);
        (new OptInInstrumentation())->register($this->runtime);
        $this->runtime->stack->pushRoot(new FakeSpan());
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * One statement through the hooked _query.
     *
     * @param string $sql
     * @return void
     */
    private function query(string $sql): void
    {
        $this->registrar->callThrough(self::MYSQL . '::_query', [self::MYSQL], new \stdClass(), [$sql, []]);
    }

    /**
     * @return string[] span names, in end order
     */
    private function names(): array
    {
        return array_map(static fn ($s): string => $s->getName(), $this->exporter->getSpans());
    }

    public function testSqlHooksTheProtectedQueryEveryPublicEntryPointEndsIn(): void
    {
        self::assertArrayHasKey(self::MYSQL . '::_query', $this->registrar->hooks);
        self::assertArrayNotHasKey(self::MYSQL . '::query', $this->registrar->hooks);
    }

    public function testOneStatementIsOneSpanWhetherOrNotAnInterceptorIsInTheChain(): void
    {
        $sql = 'SELECT * FROM catalog_product_entity WHERE sku = \'secret\'';
        $plain = new \stdClass();
        // _query is not overridden by the generated Interceptor: one frame either way.
        $this->registrar->callThrough(self::MYSQL . '::_query', [self::MYSQL], $plain, [$sql, []]);
        $this->registrar->callThrough(
            self::MYSQL . '::_query',
            [self::MYSQL],
            new \stdClass(),
            [$sql, []]
        );

        self::assertCount(2, $this->exporter->getSpans(), 'two statements, two spans');
        foreach ($this->exporter->getSpans() as $span) {
            self::assertStringNotContainsString('secret', (string) $span->getAttributes()->get('db.query.text'));
        }
    }

    public function testMultiQueryEndsInTheSameHookedMethodAndGetsASpan(): void
    {
        $this->query('UPDATE sales_order SET x = 1');

        self::assertSame(['UPDATE sales_order'], $this->names());
    }

    public function testAnEventThroughTheProxyIsOneSpan(): void
    {
        $this->registrar->callThrough(
            self::MANAGER . '::dispatch',
            [self::MANAGER . '\Proxy', self::MANAGER],
            new \stdClass(),
            ['catalog_product_save_after']
        );

        self::assertSame(['event catalog_product_save_after'], $this->names());
    }

    public function testNoSpanHasAParentWithTheSameNameWhenAWrapperIsInTheChain(): void
    {
        $this->registrar->callThrough(
            self::MANAGER . '::dispatch',
            [self::MANAGER . '\Proxy', self::MANAGER],
            new \stdClass(),
            ['a_event'],
            function (): void {
                $this->query('SELECT 1 FROM store');
            }
        );

        $byId = [];
        foreach ($this->exporter->getSpans() as $span) {
            $byId[$span->getSpanId()] = $span;
        }
        foreach ($byId as $span) {
            $parent = $byId[$span->getParentSpanId()] ?? null;
            $nested = $parent !== null && $parent->getName() === $span->getName();
            self::assertFalse($nested, 'nested pair: ' . $span->getName());
        }
        self::assertCount(2, $byId);
    }

    public function testARealNestedDispatchOfTheSameEventIsStillTwoSpans(): void
    {
        $dispatch = fn (?callable $inside = null) => $this->registrar->callThrough(
            self::MANAGER . '::dispatch',
            [self::MANAGER],
            new \stdClass(),
            ['same_event'],
            $inside
        );
        $dispatch($dispatch);

        self::assertSame(['event same_event', 'event same_event'], $this->names());
    }

    public function testHooksStayBalancedAfterWrapperFrames(): void
    {
        $this->registrar->callThrough(
            self::MANAGER . '::dispatch',
            [self::MANAGER . '\Proxy', self::MANAGER],
            new \stdClass(),
            ['x']
        );

        self::assertSame(1, $this->runtime->stack->depth(), 'only the root is left');
    }
}
