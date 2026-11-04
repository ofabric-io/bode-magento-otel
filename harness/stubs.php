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

namespace Magento\Framework\DB\Adapter\Pdo {
    class Mysql
    {
        public function query($sql, $bind = [])
        {
            return null;
        }
    }
}
