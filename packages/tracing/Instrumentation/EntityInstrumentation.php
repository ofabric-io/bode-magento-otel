<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\AbstractEntity;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Framework\Data\Collection\AbstractDb as DataCollection;
use Magento\Framework\EntityManager\EntityManager;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;
use WeakMap;

/**
 * Opt-in group "entities": one span per entity load/save/delete and per collection load, so the SQL and Redis spans of
 * a request nest under the business operation that caused them (nesting follows the OpenTelemetry context).
 *
 * Names are `entity <op> <entity type code or main table>` and `collection load <table>`. Never recorded: ids, field
 * values, filters, arguments. A collection span also carries the number of rows loaded.
 *
 * Magento resource models call each other (EAV entity -> flat resource, collection subclass -> parent load), so the
 * same object would get two spans. Each object keeps at most one open entity span (the outermost wins).
 */
class EntityInstrumentation implements InstrumentationInterface
{
    private const GROUP = 'entities';
    private const FLAT_RESOURCE = AbstractDb::class;
    private const EAV_ENTITY = AbstractEntity::class;
    private const DATA_COLLECTION = DataCollection::class;
    private const EAV_COLLECTION = AbstractCollection::class;
    private const MAX_NAME = 100;

    /**
     * Concrete resource models that override load/save/delete without calling the parent, so a hook on the abstract
     * class never fires for them (verified in Magento source: Catalog Product::load and Category::load do not call
     * parent::load).
     * A hook on a missing class or method does nothing; the re-entrancy guard keeps parent and child from doubling.
     */
    private const EAV_OVERRIDERS = [
        ProductResource::class,
        CategoryResource::class,
        // Products, categories and most other modern entities load and save through the EntityManager, not through
        // their resource model: seen on a real 2.4.9 where a product page issued the queries but no entity span.
        EntityManager::class,
    ];

    /**
     * Objects that already have an open entity span.
     *
     * A WeakMap, not a list of object ids: PHP reuses the id of a freed object, so an id left behind by a span that was
     * ended without its post hook (a fatal, an unwound root) would silently suppress the span of an unrelated object.
     *
     * @var WeakMap<object,true>
     */
    private WeakMap $open;

    /**
     * Spans started per name, per unit of work (keyed by the root span, so a new unit starts from zero).
     *
     * @var WeakMap<object,array<string,int>>
     */
    private WeakMap $repeats;

    /**
     * Creates the guard map.
     */
    public function __construct()
    {
        $this->open = new WeakMap();
        $this->repeats = new WeakMap();
    }

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        foreach (array_merge([self::FLAT_RESOURCE, self::EAV_ENTITY], self::EAV_OVERRIDERS) as $class) {
            foreach (['load', 'save', 'delete'] as $operation) {
                $this->hookEntity($runtime, $class, $operation);
            }
        }
        foreach ([self::DATA_COLLECTION, self::EAV_COLLECTION] as $class) {
            $this->hookCollection($runtime, $class);
        }
    }

    /**
     * Hooks one entity operation.
     *
     * @param Runtime $runtime
     * @param string $class
     * @param string $operation
     * @return void
     */
    private function hookEntity(Runtime $runtime, string $class, string $operation): void
    {
        $runtime->instrumenter->span(
            self::GROUP,
            $class,
            $operation,
            function (?object $subject, array $params) use ($runtime, $operation): ?SpanHandleInterface {
                // The model being handled is the first argument; the resource is shared by every model of its type.
                $key = $this->key($subject, $params);
                if ($key === null || isset($this->open[$key])) {
                    return null;
                }
                $type = $this->entityType($subject, is_object($params[0] ?? null) ? $params[0] : null);
                $name = 'entity ' . $operation . ' ' . $type['name'];
                if (!$this->repeatAllowed($runtime, $name)) {
                    return null;
                }
                $span = $runtime->spans->start(
                    $name,
                    SpanKind::KIND_INTERNAL,
                    ['bode.magento.entity.operation' => $operation, $type['attribute'] => $type['name']]
                );
                $this->open[$key] = true;

                return $span;
            },
            function (SpanHandleInterface $span, ?object $subject, array $params): void {
                $this->release($this->key($subject, $params));
            }
        );
    }

    /**
     * Hooks a collection load.
     *
     * @param Runtime $runtime
     * @param string $class
     * @return void
     */
    private function hookCollection(Runtime $runtime, string $class): void
    {
        $runtime->instrumenter->span(
            self::GROUP,
            $class,
            'load',
            function (?object $subject) use ($runtime, $class): ?SpanHandleInterface {
                error_log('TMPDEBUG coll raw hook=' . $class . ' subject=' . ($subject === null ? 'null' : get_class($subject)) . ' id=' . ($subject === null ? 0 : spl_object_id($subject)) . ' guard=' . ($subject !== null && isset($this->open[$subject]) ? 'open' : 'free'));
                if ($subject === null || isset($this->open[$subject])) {
                    return null;
                }
                $table = $this->collectionTable($subject);
                if (!$this->repeatAllowed($runtime, 'collection load ' . $table)) {
                    return null;
                }
                $span = $runtime->spans->start(
                    'collection load ' . $table,
                    SpanKind::KIND_INTERNAL,
                    ['bode.magento.entity.operation' => 'load', 'db.collection.name' => $table]
                );
                $this->open[$subject] = true;

                return $span;
            },
            function (SpanHandleInterface $span, ?object $subject, array $params, mixed $ret, ?Throwable $e): void {
                $this->release($subject);
                if ($e === null && $subject !== null && is_callable([$subject, 'count'])) {
                    $span->setAttribute('bode.magento.collection.size', (int) $subject->count());
                }
            }
        );
    }

    /**
     * Whether one more span with this name may start in the current unit of work.
     *
     * Beyond the limit the span is skipped and counted as collapsed (root attribute bode.magento.spans.collapsed).
     *
     * @param Runtime $runtime
     * @param string $name
     * @return bool
     */
    private function repeatAllowed(Runtime $runtime, string $name): bool
    {
        $limit = $runtime->config->entityRepeatLimit();
        $root = $runtime->stack->bottom();
        if ($limit <= 0 || $root === null) {
            return true;
        }
        $counts = $this->repeats[$root] ?? [];
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $this->repeats[$root] = $counts;
        if ($counts[$name] > $limit) {
            $runtime->budget->noteCollapsed();

            return false;
        }

        return true;
    }

    /**
     * The object that identifies one logical operation: the model argument, else the subject.
     *
     * @param object|null $subject
     * @param array<int,mixed> $params
     * @return object|null
     */
    private function key(?object $subject, array $params): ?object
    {
        return is_object($params[0] ?? null) ? $params[0] : $subject;
    }

    /**
     * Releases the re-entrancy mark.
     *
     * @param object|null $key
     * @return void
     */
    private function release(?object $key): void
    {
        if ($key !== null) {
            unset($this->open[$key]);
        }
    }

    /**
     * Entity type code (EAV) or main table (flat resource), sanitised and bounded.
     *
     * @param object|null $subject
     * @param object|null $model the model being handled, used when the subject carries no metadata (EntityManager)
     * @return array{attribute:string,name:string}
     */
    private function entityType(?object $subject, ?object $model = null): array
    {
        try {
            if ($subject !== null && is_callable([$subject, 'getEntityType'])) {
                $type = $subject->getEntityType();
                if (is_object($type) && is_callable([$type, 'getEntityTypeCode'])) {
                    return [
                        'attribute' => 'bode.magento.entity.type',
                        'name' => $this->clean($type->getEntityTypeCode()),
                    ];
                }
            }
            if ($subject !== null && is_callable([$subject, 'getMainTable'])) {
                return ['attribute' => 'db.collection.name', 'name' => $this->clean($subject->getMainTable())];
            }
            // The event prefix of a model is its entity name (catalog_product, sales_order): bounded, never an id.
            if ($model !== null && is_callable([$model, 'getEventPrefix'])) {
                return ['attribute' => 'bode.magento.entity.type', 'name' => $this->clean($model->getEventPrefix())];
            }
        } catch (Throwable) {
            // Falls through: an unnamed entity is still a useful span.
            return ['attribute' => 'bode.magento.entity.type', 'name' => 'unknown'];
        }

        return ['attribute' => 'bode.magento.entity.type', 'name' => 'unknown'];
    }

    /**
     * Main table of a collection, sanitised and bounded.
     *
     * @param object $collection
     * @return string
     */
    private function collectionTable(object $collection): string
    {
        try {
            if (is_callable([$collection, 'getMainTable'])) {
                return $this->clean($collection->getMainTable());
            }
            if (is_callable([$collection, 'getEntity'])) {
                $entity = $collection->getEntity();
                $type = is_object($entity) && is_callable([$entity, 'getEntityType']) ? $entity->getEntityType() : null;
                if (is_object($type) && is_callable([$type, 'getEntityTypeCode'])) {
                    return $this->clean($type->getEntityTypeCode());
                }
            }
        } catch (Throwable) {
            // Falls through to the placeholder.
            return 'unknown';
        }

        return 'unknown';
    }

    /**
     * Keeps identifier characters only and bounds the length.
     *
     * @param mixed $value
     * @return string
     */
    private function clean(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $clean = substr((string) preg_replace('/[^A-Za-z0-9_.]/', '', $text), 0, self::MAX_NAME);

        return $clean === '' ? 'unknown' : $clean;
    }
}
