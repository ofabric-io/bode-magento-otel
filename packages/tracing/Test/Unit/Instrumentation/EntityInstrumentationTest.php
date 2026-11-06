<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Instrumentation\EntityInstrumentation;
use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Test\Unit\Hook\FakeRegistrar;
use Bode\Tracing\Test\Unit\Hook\FakeSpan;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\EntityManager\EntityManager;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Entity and collection spans, run against the real OpenTelemetry SDK with an in-memory exporter so parentage is real.
 */
class EntityInstrumentationTest extends TestCase
{
    private const FLAT = 'Magento\Framework\Model\ResourceModel\Db\AbstractDb';
    private const EAV = 'Magento\Eav\Model\Entity\AbstractEntity';
    private const DATA_COLLECTION = 'Magento\Framework\Data\Collection\AbstractDb';
    private const EAV_COLLECTION = 'Magento\Eav\Model\Entity\Collection\AbstractCollection';

    /**
     * @var FakeRegistrar
     */
    private FakeRegistrar $registrar;

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
        $this->registrar = new FakeRegistrar();
        $env = new Environment(['MAGENTO_OTEL_TRACING_GROUPS' => 'entities']);
        $this->runtime = new Runtime(new Config($env, true), $env, $this->registrar);
        (new EntityInstrumentation())->register($this->runtime);
        // Entity spans are child-only: they need the root of a unit of work (a request, job, message or command).
        $this->runtime->stack->pushRoot(new FakeSpan());
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    /**
     * Runs a pre/post pair like the hook extension would.
     *
     * @param string $target
     * @param object|null $subject
     * @param array<int,mixed> $params
     * @param Throwable|null $e
     * @param callable|null $inside runs between pre and post
     * @return void
     */
    private function invoke(
        string $target,
        ?object $subject,
        array $params,
        ?Throwable $e = null,
        ?callable $inside = null
    ): void {
        ($this->registrar->hooks[$target]['pre'])($subject, $params);
        if ($inside !== null) {
            $inside();
        }
        ($this->registrar->hooks[$target]['post'])($subject, $params, null, $e);
    }

    public function testEntitySpanWithoutAUnitRootIsDroppedAndCountedNotStartedAsATrace(): void
    {
        $env = new Environment(['MAGENTO_OTEL_TRACING_GROUPS' => 'entities']);
        $registrar = new FakeRegistrar();
        $runtime = new Runtime(new Config($env, true), $env, $registrar);
        (new EntityInstrumentation())->register($runtime);

        ($registrar->hooks[self::FLAT . '::load']['pre'])(new FakeResource('sales_order'), [new \stdClass(), 1]);
        ($registrar->hooks[self::FLAT . '::load']['post'])(new FakeResource('sales_order'), [], null, null);

        self::assertSame([], $this->exporter->getSpans(), 'no fragment trace');
        self::assertSame(1, $runtime->stack->orphanedTotal());
        self::assertSame(0, $runtime->stack->depth(), 'hooks stay balanced');
    }

    public function testRegistersTheDocumentedHookTargets(): void
    {
        foreach (['load', 'save', 'delete'] as $method) {
            self::assertArrayHasKey(self::FLAT . '::' . $method, $this->registrar->hooks);
            self::assertArrayHasKey(self::EAV . '::' . $method, $this->registrar->hooks);
        }
        self::assertArrayHasKey(self::DATA_COLLECTION . '::load', $this->registrar->hooks);
        self::assertArrayHasKey(self::EAV_COLLECTION . '::load', $this->registrar->hooks);
    }

    public function testHooksTheConcreteEavResourcesThatOverrideWithoutCallingParent(): void
    {
        foreach ([ProductResource::class, CategoryResource::class] as $class) {
            foreach (['load', 'save', 'delete'] as $method) {
                self::assertArrayHasKey($class . '::' . $method, $this->registrar->hooks);
            }
        }
    }

    public function testEntityManagerLoadIsNamedAfterTheModelEventPrefixAndCarriesNoIds(): void
    {
        $model = new class {
            public function getEventPrefix(): string
            {
                return 'catalog_product';
            }
        };
        $this->invoke(EntityManager::class . '::load', new \stdClass(), [$model, 'sku-secret-4711']);

        $span = $this->exporter->getSpans()[0];
        self::assertSame('entity load catalog_product', $span->getName());
        self::assertSame('catalog_product', $span->getAttributes()->get('bode.magento.entity.type'));
        $dump = json_encode([$span->getName(), $span->getAttributes()->toArray()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('4711', $dump);
        foreach (['load', 'save', 'delete'] as $method) {
            self::assertArrayHasKey(EntityManager::class . '::' . $method, $this->registrar->hooks);
        }
    }

    public function testAMarkLeftByAnUnfinishedSpanNeverSuppressesALaterObject(): void
    {
        // Spans ended without their post hook (a fatal, an unwound root) leave their guard mark behind. PHP reuses the
        // id of a freed object, so a guard keyed by object id would swallow the span of an unrelated later model.
        for ($i = 0; $i < 30; $i++) {
            $model = new \stdClass();
            ($this->registrar->hooks[self::FLAT . '::load']['pre'])(new FakeResource('sales_order'), [$model, 1]);
            self::assertNotNull($this->runtime->stack->top(), "iteration $i: the span was started");
            unset($model);
        }
    }

    public function testRegistersNothingWhenTheGroupIsOff(): void
    {
        $registrar = new FakeRegistrar();
        $env = new Environment([]);
        (new EntityInstrumentation())->register(new Runtime(new Config($env, true), $env, $registrar));

        self::assertSame([], $registrar->hooks);
    }

    public function testFlatEntitySpanIsNamedAfterTheMainTableAndHasNoIds(): void
    {
        $model = new \stdClass();
        $this->invoke(self::FLAT . '::load', new FakeResource('catalog_product_entity'), [$model, 4711, 'sku-secret']);

        $span = $this->exporter->getSpans()[0];
        self::assertSame('entity load catalog_product_entity', $span->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        self::assertSame('load', $span->getAttributes()->get('bode.magento.entity.operation'));
        self::assertSame('catalog_product_entity', $span->getAttributes()->get('db.collection.name'));
        self::assertSame(2, count($span->getAttributes()->toArray()), 'operation and table only');
        $dump = json_encode([$span->getName(), $span->getAttributes()->toArray()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('4711', $dump);
        self::assertStringNotContainsString('sku-secret', $dump);
    }

    public function testEavEntitySpanUsesTheEntityTypeCode(): void
    {
        $this->invoke(self::EAV . '::save', new FakeResource('x', 'catalog_product'), [new \stdClass()]);

        $span = $this->exporter->getSpans()[0];
        self::assertSame('entity save catalog_product', $span->getName());
        self::assertSame('catalog_product', $span->getAttributes()->get('bode.magento.entity.type'));
        self::assertSame('save', $span->getAttributes()->get('bode.magento.entity.operation'));
    }

    public function testNamesAreSanitisedAndBounded(): void
    {
        $table = "bad table'; DROP--" . str_repeat('a', 300);
        $this->invoke(self::FLAT . '::delete', new FakeResource($table), [new \stdClass()]);

        $name = $this->exporter->getSpans()[0]->getName();
        self::assertMatchesRegularExpression('/^entity delete [A-Za-z0-9_.]{1,100}$/', $name);
    }

    public function testExceptionTypeIsRecordedWithoutItsMessage(): void
    {
        $this->invoke(
            self::FLAT . '::save',
            new FakeResource('sales_order'),
            [new \stdClass()],
            new RuntimeException('customer jane@example.com failed')
        );

        $span = $this->exporter->getSpans()[0];
        $dump = json_encode([
            $span->getStatus()->getCode(),
            $span->getStatus()->getDescription(),
            $span->getAttributes()->toArray(),
            array_map(
                static fn ($event) => [$event->getName(), $event->getAttributes()->toArray()],
                $span->getEvents()
            ),
        ], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Error', $dump);
        self::assertStringContainsString('RuntimeException', $dump);
        self::assertStringNotContainsString('jane@example.com', $dump);
    }

    public function testEavResourceCallingFlatResourceForTheSameModelGetsOneSpan(): void
    {
        $model = new \stdClass();
        $this->invoke(
            self::EAV . '::load',
            new FakeResource('t', 'catalog_product'),
            [$model],
            null,
            function () use ($model): void {
                $this->invoke(self::FLAT . '::load', new FakeResource('catalog_product_entity'), [$model]);
            }
        );

        self::assertCount(1, $this->exporter->getSpans());
        self::assertSame('entity load catalog_product', $this->exporter->getSpans()[0]->getName());
    }

    public function testDifferentModelsStillNestAndTheGuardIsReleased(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $this->invoke(
            self::FLAT . '::save',
            new FakeResource('sales_order'),
            [$first],
            null,
            function () use ($second): void {
                $this->invoke(self::FLAT . '::save', new FakeResource('sales_order_item'), [$second]);
            }
        );
        $this->invoke(self::FLAT . '::save', new FakeResource('sales_order'), [$first]);

        self::assertCount(3, $this->exporter->getSpans());
    }

    public function testCollectionSpanCarriesRowCountAndIsTheParentOfSqlSpans(): void
    {
        $collection = new FakeCollection('catalog_product_entity', 3);
        $this->invoke(self::DATA_COLLECTION . '::load', $collection, [], null, function (): void {
            $sql = $this->runtime->spans->start('SELECT catalog_product_entity', SpanKind::KIND_CLIENT);
            $sql->end();
        });

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        [$sql, $collectionSpan] = $spans;
        self::assertSame('collection load catalog_product_entity', $collectionSpan->getName());
        self::assertSame(3, $collectionSpan->getAttributes()->get('bode.magento.collection.size'));
        self::assertSame($collectionSpan->getSpanId(), $sql->getParentSpanId(), 'SQL nests under the collection');
    }

    public function testSqlSpanNestsUnderTheEntitySpan(): void
    {
        $this->invoke(
            self::FLAT . '::save',
            new FakeResource('sales_order'),
            [new \stdClass()],
            null,
            function (): void {
                $this->runtime->spans->start('INSERT sales_order', SpanKind::KIND_CLIENT)->end();
            }
        );

        [$sql, $entity] = $this->exporter->getSpans();
        self::assertSame($entity->getSpanId(), $sql->getParentSpanId());
    }

    public function testEavCollectionOverridingParentLoadGetsOneSpan(): void
    {
        $collection = new FakeCollection('catalog_product_entity', 1);
        $this->invoke(self::EAV_COLLECTION . '::load', $collection, [], null, function () use ($collection): void {
            $this->invoke(self::DATA_COLLECTION . '::load', $collection, []);
        });

        self::assertCount(1, $this->exporter->getSpans());
    }

    public function testFailedCollectionLoadHasNoSizeAndRecordsTheError(): void
    {
        $this->invoke(self::DATA_COLLECTION . '::load', new FakeCollection('t', 5), [], new RuntimeException('boom'));

        $span = $this->exporter->getSpans()[0];
        self::assertNull($span->getAttributes()->get('bode.magento.collection.size'));
        self::assertSame('Error', $span->getStatus()->getCode());
    }

    public function testSubjectWithoutMetadataStillGetsAnUnnamedSpanInsteadOfFailing(): void
    {
        $this->invoke(self::FLAT . '::load', new \stdClass(), [new \stdClass()]);

        self::assertSame('entity load unknown', $this->exporter->getSpans()[0]->getName());
    }

    public function testThrowingMetadataNeverBreaksTheHostCall(): void
    {
        $this->invoke(self::FLAT . '::load', new ThrowingResource(), [new \stdClass()]);

        self::assertSame('entity load unknown', $this->exporter->getSpans()[0]->getName());
    }
}
