<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the Magento classes the Bode modules hook, with the same names, methods and signatures that
 * matter to the hooks. They let the harness prove, with the real OpenTelemetry extension and SDK, that hooks fire and
 * what the spans look like, without installing Magento. They prove nothing about Magento itself.
 */

namespace Magento\Framework\App {
    class State
    {
        public function setAreaCode(string $code): void
        {
        }
    }

    class Bootstrap
    {
        /** @var callable */
        public static $app;

        public function run(): void
        {
            (self::$app)();
        }
    }

    class FrontController
    {
        public function dispatch(object $request): object
        {
            return new \stdClass();
        }
    }
}

namespace Magento\Webapi\Controller\Rest {
    class Route
    {
        public function __construct(private string $path)
        {
        }

        public function getRoutePath(): string
        {
            return $this->path;
        }
    }

    class Router
    {
        public function match(object $request): Route
        {
            return new Route('/V1/products/:sku');
        }
    }
}

namespace Magento\GraphQl\Controller {
    class GraphQl
    {
        public function dispatch(object $request): object
        {
            return new \stdClass();
        }
    }
}

namespace Magento\Cron\Model {
    class Schedule
    {
        public const STATUS_MISSED = 'missed';

        public function getJobCode(): string
        {
            return 'stale_job';
        }

        private string $status = 'pending';

        public function setStatus(string $status): self
        {
            $this->status = $status;

            return $this;
        }

        public function getStatus(): string
        {
            return $this->status;
        }
    }
}

namespace Magento\Cron\Observer {
    class ProcessCronQueueObserver
    {
        public function tryRunJob($scheduledTime, $currentTime, $jobConfig, $schedule, $groupId)
        {
            $schedule->setStatus('running');
            \Harness\Nest::fire(__METHOD__, [$jobConfig]);
            if (($jobConfig['name'] ?? '') === 'failing_job') {
                $schedule->setStatus('error');
                throw new \RuntimeException('secret detail that must not be exported');
            }
            $schedule->setStatus('success');
        }
    }
}

namespace Magento\Indexer\Model {
    class Indexer
    {
        public function getId(): string
        {
            return 'catalog_product_price';
        }

        public function isScheduled(): bool
        {
            return true;
        }

        public function reindexAll(): void
        {
        }

        public function reindexList($ids): void
        {
        }

        public function reindexRow($id): void
        {
        }
    }
}

namespace Magento\Framework\ObjectManager {
    interface ResetAfterRequestInterface
    {
        public function _resetState(): void;
    }
}

namespace Magento\Framework\App\Cache {
    class Manager
    {
        public function flush(array $types): void
        {
        }

        public function clean(array $types): void
        {
        }
    }
}

namespace Magento\Framework\MessageQueue {
    class Consumer
    {
        private object $configuration;

        public function __construct()
        {
            $this->configuration = new class {
                public function getConsumerName(): string
                {
                    return 'async.operations.all';
                }
            };
        }

        private function dispatchMessage($message, $isSync = false)
        {
            if (($message->getProperties()['topic_name'] ?? '') === 'fail') {
                throw new \RuntimeException('queue failure');
            }
        }

        public function process($message): void
        {
            $this->dispatchMessage($message);
        }
    }
}

namespace Magento\Framework\Interception {
    interface InterceptorInterface
    {
    }
}

namespace Magento\Framework\ObjectManager {
    interface NoninterceptableInterface
    {
    }
}

namespace Magento\Framework\DB\Adapter\Pdo {
    /** Same call chain as Magento 2.4.9: every public entry point ends in the protected _query(). */
    class Mysql
    {
        public function query($sql, $bind = [])
        {
            return $this->_query($sql, $bind);
        }

        public function multiQuery($sql, $bind = [])
        {
            return $this->_query($sql, $bind);
        }

        protected function _query($sql, $bind = [])
        {
            return null;
        }
    }
}

namespace Magento\Framework\DB\Adapter\Pdo\Mysql {
    /**
     * What compiled DI generates because core di.xml declares a plugin on AdapterInterface: it overrides the public
     * methods and calls parent::, so ext-opentelemetry runs a hook on Mysql::query for this frame and for the parent's.
     */
    class Interceptor extends \Magento\Framework\DB\Adapter\Pdo\Mysql implements \Magento\Framework\Interception\InterceptorInterface
    {
        public function query($sql, $bind = [])
        {
            return parent::query($sql, $bind);
        }

        public function multiQuery($sql, $bind = [])
        {
            return parent::multiQuery($sql, $bind);
        }
    }
}

/*
 * Stand-ins for the entity / event / Redis / collection hot paths (Rail B's "entities" group, and the opt-in groups).
 * Real Magento signatures where the hooks care. A scenario nests work inside a stub method through
 * Harness\Nest::on('Class::method', fn (array $args) => ...): the callback runs while the stub method is on the stack,
 * so spans started in it are children of the span the hook started for that method. Several callbacks per key run
 * in registration order; a callback decides itself (from $args) whether it applies.
 */

namespace Harness {
    class Nest
    {
        /** @var array<string,list<callable>> */
        private static array $callbacks = [];

        public static function on(string $method, callable $callback): void
        {
            self::$callbacks[$method][] = $callback;
        }

        public static function fire(string $method, array $args = []): void
        {
            foreach (self::$callbacks[$method] ?? [] as $callback) {
                $callback($args);
            }
        }

        /** One SQL statement through the (hooked) adapter, as a model/resource would issue it. */
        public static function sql(string $sql): void
        {
            (new \Magento\Framework\DB\Adapter\Pdo\Mysql\Interceptor())->query($sql);
        }
    }
}

namespace Magento\Framework\Event {
    class Manager
    {
        public function dispatch($eventName, array $data = [])
        {
            \Harness\Nest::fire(__METHOD__, [$eventName, $data]);

            return $this;
        }
    }
}

namespace Magento\Framework\Event\Manager {
    /**
     * Generated lazy proxy (preference for ManagerInterface): extends the real class and forwards to a second instance, so
     * the hook runs for the Proxy frame (one subject) and for the real Manager (another subject).
     */
    class Proxy extends \Magento\Framework\Event\Manager implements \Magento\Framework\ObjectManager\NoninterceptableInterface
    {
        private \Magento\Framework\Event\Manager $subject;

        public function __construct()
        {
            $this->subject = new \Magento\Framework\Event\Manager();
        }

        public function dispatch($eventName, array $data = [])
        {
            return $this->subject->dispatch($eventName, $data);
        }
    }
}

namespace Magento\Framework\Model {
    /** The model object entity loads receive: only what the hooks may read (an event prefix and an id). */
    class AbstractModel
    {
        public function __construct(private string $eventPrefix = 'catalog_product', private int|string|null $id = null)
        {
        }

        public function getEventPrefix(): string
        {
            return $this->eventPrefix;
        }

        public function getId(): int|string|null
        {
            return $this->id;
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db {
    abstract class AbstractDb
    {
        abstract public function getMainTable(): string;

        public function load(\Magento\Framework\Model\AbstractModel $object, $value, $field = null)
        {
            \Harness\Nest::fire(__METHOD__, [$object, $value, $field]);

            return $this;
        }

        public function save(\Magento\Framework\Model\AbstractModel $object)
        {
            \Harness\Nest::fire(__METHOD__, [$object]);

            return $this;
        }

        public function delete(\Magento\Framework\Model\AbstractModel $object)
        {
            \Harness\Nest::fire(__METHOD__, [$object]);

            return $this;
        }
    }
}

namespace Magento\Cms\Model\ResourceModel {
    /** A flat-table entity (the Framework AbstractDb path). */
    class Block extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
    {
        public function getMainTable(): string
        {
            return 'cms_block';
        }
    }
}

namespace Magento\Eav\Model\Entity {
    abstract class AbstractEntity
    {
        public function __construct(private string $code = 'catalog_product', private string $table = 'catalog_product_entity')
        {
        }

        public function getEntityType(): object
        {
            $code = $this->code;

            return new class ($code) {
                public function __construct(private string $code)
                {
                }

                public function getEntityTypeCode(): string
                {
                    return $this->code;
                }
            };
        }

        public function getEntityTable(): string
        {
            return $this->table;
        }

        public function load($object, $entityId, $attributes = [])
        {
            \Harness\Nest::fire(__METHOD__, [$object, $entityId, $attributes]);

            return $this;
        }

        public function save(\Magento\Framework\Model\AbstractModel $object)
        {
            \Harness\Nest::fire(__METHOD__, [$object]);

            return $this;
        }

        public function delete($object)
        {
            \Harness\Nest::fire(__METHOD__, [$object]);

            return $this;
        }
    }
}

namespace Magento\Catalog\Model\ResourceModel {
    /** An EAV entity (the Eav AbstractEntity path). */
    class Product extends \Magento\Eav\Model\Entity\AbstractEntity
    {
    }
}

namespace Magento\Framework\Data\Collection {
    abstract class AbstractDb
    {
        public function load($printQuery = false, $logQuery = false)
        {
            \Harness\Nest::fire(__METHOD__, [static::class]);

            return $this;
        }
    }
}

namespace Magento\Eav\Model\Entity\Collection {
    /** Overrides load() like the real EAV collection does (it calls parent::load()), so both hook targets exist. */
    abstract class AbstractCollection extends \Magento\Framework\Data\Collection\AbstractDb
    {
        public function load($printQuery = false, $logQuery = false)
        {
            \Harness\Nest::fire(__METHOD__, [static::class]);

            return parent::load($printQuery, $logQuery);
        }
    }
}

namespace Magento\Catalog\Model\ResourceModel\Product {
    class Collection extends \Magento\Eav\Model\Entity\Collection\AbstractCollection
    {
    }
}

namespace Magento\Catalog\Model\ResourceModel\Product\Collection {
    /** Generated Interceptor of the product collection: overrides load() and calls parent::load(). */
    class Interceptor extends \Magento\Catalog\Model\ResourceModel\Product\Collection implements \Magento\Framework\Interception\InterceptorInterface
    {
        public function load($printQuery = false, $logQuery = false)
        {
            return parent::load($printQuery, $logQuery);
        }
    }
}

namespace {
    class Cm_Cache_Backend_Redis
    {
        public function load($id, $doNotTestCacheValidity = false)
        {
            \Harness\Nest::fire(__METHOD__, [$id]);

            return false;
        }

        public function save($data, $id, $tags = [], $specificLifetime = false)
        {
            return true;
        }

        public function remove($id)
        {
            return true;
        }

        public function clean($mode = 'all', $tags = [])
        {
            return true;
        }
    }
}
