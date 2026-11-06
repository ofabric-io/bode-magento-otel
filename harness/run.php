<?php

declare(strict_types=1);

/**
 * Runs one scenario against the stub Magento classes with the real OpenTelemetry extension and SDK.
 * Usage: php run.php <scenario>
 * Telemetry goes wherever OTEL_EXPORTER_OTLP_* points (see run.sh).
 */

use Bode\Tracing\Hook\Runtime;

$scenario = $argv[1] ?? 'storefront';
$repo = dirname(__DIR__);

require getenv('HARNESS_VENDOR') . '/autoload.php';
// The module's own autoloading is PSR-0 through Magento; the harness maps the namespace by hand.
spl_autoload_register(static function (string $class) use ($repo): void {
    if (str_starts_with($class, 'Bode\\')) {
        [, $module, $rest] = explode('\\', $class, 3);
        $file = "$repo/packages/" . strtolower($module) . "/" . str_replace('\\', '/', $rest) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// What Magento's registration.php would do at autoload time: register the hooks before the classes load.
Runtime::boot();
Bode\Metrics\Hook\MetricsRuntime::boot();

require __DIR__ . '/stubs.php';

$request = new class {
    public string $route = 'catalog';
    public string $controller = 'product';
    public string $action = 'view';
    public string $content = '';

    public function getFullActionName(): string
    {
        return "{$this->route}_{$this->controller}_{$this->action}";
    }

    public function getRouteName(): string
    {
        return $this->route;
    }

    public function getControllerName(): string
    {
        return $this->controller;
    }

    public function getActionName(): string
    {
        return $this->action;
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getContent(): string
    {
        return $this->content;
    }
};

$web = static function (string $area, string $uri, callable $inside) use ($request): void {
    $_SERVER += ['REMOTE_ADDR' => '203.0.113.77', 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on'];
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 harness';
    $_SERVER['HTTP_CF_RAY'] = '8c1234567890abcd-AMS';
    if (getenv('HARNESS_NO_TRACEPARENT') === false) {
        $_SERVER['HTTP_TRACEPARENT'] = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
    }
    Magento\Framework\App\Bootstrap::$app = static function () use ($area, $request, $inside): void {
        (new Magento\Framework\App\State())->setAreaCode($area);
        $inside($request);
    };
    (new Magento\Framework\App\Bootstrap())->run();
};

$schedule = static fn (): Magento\Cron\Model\Schedule => new Magento\Cron\Model\Schedule();
$job = static fn (string $name): array => ['name' => $name];

switch ($scenario) {
    case 'storefront':
        $web('frontend', '/gold-coins/1oz-eagle-2026.html?utm_source=mail&email=jane@example.com&token=abc', static function ($r): void {
            (new Magento\Framework\App\FrontController())->dispatch($r);
        });
        break;
    case 'admin':
        $web('adminhtml', '/admin_secret/sales/order/view/order_id/1042/key/abcdef/', static function ($r): void {
            $r->route = 'sales';
            $r->controller = 'order';
            $r->action = 'view';
            (new Magento\Framework\App\FrontController())->dispatch($r);
        });
        break;
    case 'rest':
        $web('webapi_rest', '/rest/default/V1/products/ABC-123?searchCriteria[x]=1', static function ($r): void {
            (new Magento\Webapi\Controller\Rest\Router())->match($r);
        });
        break;
    case 'graphql':
        $web('graphql', '/graphql', static function ($r): void {
            $r->content = json_encode(['operationName' => 'GetCart', 'query' => 'query GetCart { cart(cart_id:"secret") { id } }']);
            (new Magento\GraphQl\Controller\GraphQl())->dispatch($r);
        });
        break;
    case 'cron-ok':
        (new Magento\Cron\Observer\ProcessCronQueueObserver())->tryRunJob(0, 0, $job('catalog_product_alert'), $schedule(), 'default');
        break;
    case 'cron-fail':
        try {
            (new Magento\Cron\Observer\ProcessCronQueueObserver())->tryRunJob(0, 0, $job('failing_job'), $schedule(), 'default');
        } catch (RuntimeException) {
            // cron:run catches and records it too
        }
        break;
    case 'cron-many':
        // 5 jobs in one process: spans of every job must be exported (the per-job budget resets).
        for ($i = 0; $i < 5; $i++) {
            (new Magento\Cron\Observer\ProcessCronQueueObserver())->tryRunJob(0, 0, $job("job_$i"), $schedule(), 'default');
        }
        break;
    case 'indexer':
        (new Magento\Indexer\Model\Indexer())->reindexAll();
        break;
    case 'cache':
        (new Magento\Framework\App\Cache\Manager())->flush(['config', 'full_page', 'block_html']);
        break;
    case 'queue':
        $envelope = new class {
            public function getProperties(): array
            {
                return ['topic_name' => 'async.operations.all'];
            }
        };
        (new Magento\Framework\MessageQueue\Consumer())->process($envelope);
        break;
    case 'outbound':
        $client = new GuzzleHttp\Client(['timeout' => 3]);
        $client->request('GET', 'http://bode-lgtm:3200/ready');
        try {
            $client->request('GET', 'http://unreachable.invalid/x?token=abc');
        } catch (Throwable) {
            // expected: the call fails, the metric records an error
        }
        break;
    case 'missed':
        $s = $schedule();
        $s->setStatus(Magento\Cron\Model\Schedule::STATUS_MISSED);
        break;
    case 'gauges':
        $samples = new Bode\Metrics\Model\CollectorSamples();
        $recorder = Bode\Metrics\Hook\MetricsRuntime::recorder();
        $all = array_merge(
            $samples->cronAges(['pending' => 95]),
            $samples->cronStuck(1),
            $samples->indexerStatus(['catalog_product_price' => 'invalid', 'catalogsearch_fulltext' => 'valid']),
            $samples->mviewBacklog(['catalog_product_price' => [130, 100]]),
            $samples->queueBacklog([['async.operations.all', 2, 14]])
        );
        foreach ($all as [$name, $value, $attributes]) {
            $recorder->gauge($name, $value, $attributes);
        }
        $recorder->flush();
        break;
    case 'log-file':
        // Exactly Magento's handler setup: StreamHandler + LineFormatter(null, null, true), plus our processor.
        $logger = new Monolog\Logger('main');
        $handler = new Monolog\Handler\StreamHandler('/logs/system.log');
        $handler->setFormatter(new Monolog\Formatter\LineFormatter(null, null, true));
        $logger->pushHandler($handler);
        $logger->pushProcessor(new Bode\Tracing\Model\Log\TraceContextProcessor());
        $web('frontend', '/checkout/', static function ($r) use ($logger): void {
            $logger->error('payment declined', ['order' => 'redacted']);
            $logger->critical('boom', ['exception' => new RuntimeException("first line\nsecond line of the message")]);
        });
        $logger->info('after the request, no span is current');
        break;
    case 'log-json':
    case 'log-json-web':
    case 'log-uncaught':
        // Bode_Logs: the real classes wired like di.xml does it, see logs.php.
        require __DIR__ . '/logs.php';
        break;
    case 'log':
        $logger = new Monolog\Logger('main');
        $handler = new Monolog\Handler\StreamHandler('php://stdout');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new Bode\Tracing\Model\Log\TraceContextProcessor());
        $web('frontend', '/checkout/', static function ($r) use ($logger): void {
            $logger->error('payment declined', ['order' => 'redacted']);
        });
        break;
    // ---- Trace-tree scenarios: run with the groups in run.sh (tree_groups). Expected trees: harness/expectations.php.
    case 'entity-product':
        // A product page: an event, an EAV entity load that queries the database, a Redis read, all inside one request.
        $event = new Magento\Framework\Event\Manager\Proxy();
        Harness\Nest::on('Magento\Eav\Model\Entity\AbstractEntity::load', static function (array $args): void {
            Harness\Nest::sql('SELECT `e`.* FROM `catalog_product_entity` AS `e` WHERE (e.entity_id = 1042)');
        });
        $web('frontend', '/gold-coins/1oz-eagle-2026.html', static function ($r) use ($event): void {
            $event->dispatch('catalog_controller_product_view', ['product' => 1042]);
            (new Magento\Catalog\Model\ResourceModel\Product())->load(new Magento\Framework\Model\AbstractModel('catalog_product', 1042), 1042);
            (new Cm_Cache_Backend_Redis())->load('CONFIG_cache_key_that_must_not_be_exported');
            (new Magento\Cms\Model\ResourceModel\Block())->load(new Magento\Framework\Model\AbstractModel('cms_block', 3), 3);
        });
        break;
    case 'events-bootstrap':
        // Events dispatched before Bootstrap::run and after it have no unit of work to belong to: they must not start a
        // trace of their own. The one during the request is a child of the request.
        $event = new Magento\Framework\Event\Manager\Proxy();
        $event->dispatch('before_bootstrap_event');
        $web('frontend', '/checkout/cart/', static function ($r) use ($event): void {
            $event->dispatch('during_bootstrap_event');
        });
        $event->dispatch('after_bootstrap_event');
        break;
    case 'event-excluded-path':
        // The request is on an excluded path (no root span), so the event inside it has no root either: dropped.
        $event = new Magento\Framework\Event\Manager\Proxy();
        $web('frontend', '/static/frontend/Vendor/theme/en_US/app.js', static function ($r) use ($event): void {
            $event->dispatch('static_asset_event');
        });
        break;
    case 'cron-in-event':
        // A cron job started from inside an event dispatched outside any request: the event is dropped (no root), the
        // job is a root (cron) and owns the trace; what the job does is its child.
        $event = new Magento\Framework\Event\Manager\Proxy();
        Harness\Nest::on('Magento\Framework\Event\Manager::dispatch', static function (array $args) use ($schedule, $job): void {
            if ($args[0] === 'cron_from_event') {
                (new Magento\Cron\Observer\ProcessCronQueueObserver())->tryRunJob(0, 0, $job('cron_from_event'), $schedule(), 'default');
            }
        });
        Harness\Nest::on('Magento\Cron\Observer\ProcessCronQueueObserver::tryRunJob', static function (array $args): void {
            Harness\Nest::sql('SELECT `main_table`.* FROM `cron_schedule` AS `main_table` WHERE (status = \'pending\')');
        });
        $event->dispatch('cron_from_event');
        break;
    case 'collection-volume':
        // A collection of many rows whose items each load an entity and query the database: thousands of candidate spans
        // in one request. Per-trace caps (and collapsing) must keep the trace bounded and the tree intact.
        $rows = (int) (getenv('HARNESS_ROWS') ?: 400);
        Harness\Nest::on('Magento\Eav\Model\Entity\Collection\AbstractCollection::load', static function () use ($rows): void {
            $entity = new Magento\Catalog\Model\ResourceModel\Product();
            for ($i = 1; $i <= $rows; $i++) {
                $entity->load(new Magento\Framework\Model\AbstractModel('catalog_product', $i), $i);
            }
        });
        Harness\Nest::on('Magento\Eav\Model\Entity\AbstractEntity::load', static function (array $args): void {
            Harness\Nest::sql('SELECT `e`.* FROM `catalog_product_entity` AS `e` WHERE (e.entity_id = ' . (int) $args[1] . ')');
        });
        $web('frontend', '/gold-coins.html', static function ($r): void {
            (new Magento\Catalog\Model\ResourceModel\Product\Collection\Interceptor())->load();
        });
        break;
    case 'wrapped-calls':
        // What compiled DI puts around real classes: a lazy Event\Manager\Proxy, a Mysql\Interceptor (core di.xml plugin on
        // AdapterInterface) and a collection Interceptor. Each logical call must be one span, never a nested same-name pair.
        $event = new Magento\Framework\Event\Manager\Proxy();
        $db = new Magento\Framework\DB\Adapter\Pdo\Mysql\Interceptor();
        Harness\Nest::on('Magento\Framework\Event\Manager::dispatch', static function (array $args) use ($db): void {
            if ($args[0] === 'wrapped_event') {
                $db->query('SELECT main_table.* FROM store AS main_table WHERE (code = \'secret\')');
            }
        });
        $web('frontend', '/wrapped.html', static function ($r) use ($event, $db): void {
            $event->dispatch('wrapped_event');
            $db->multiQuery('UPDATE sales_order SET status = \'processing\' WHERE (entity_id = 7)');
            $db->query('SELECT path FROM core_config_data');
            (new Magento\Catalog\Model\ResourceModel\Product\Collection\Interceptor())->load();
        });
        break;
    case 'bench':
        // Instrumentation cost per simulated request: Bootstrap::run + area + front controller, as a storefront page
        // triggers with the default groups. The stub application does nothing, so this is the overhead itself, not a
        // percentage of a real request. BENCH_N iterations, no incoming traceparent (the sampler decides).
        $n = (int) (getenv('BENCH_N') ?: 5000);
        $times = [];
        $dispatch = static function ($r): void {
            (new Magento\Framework\App\FrontController())->dispatch($r);
        };
        for ($i = 0; $i < 200; $i++) {
            $web('frontend', '/gold-coins/1oz-eagle.html?utm=x', $dispatch);
        }
        for ($i = 0; $i < $n; $i++) {
            $t = hrtime(true);
            $web('frontend', '/gold-coins/1oz-eagle.html?utm=x', $dispatch);
            $times[] = (hrtime(true) - $t) / 1000;
        }
        sort($times);
        printf(
            "n=%d  p50=%.1fus  p95=%.1fus  p99=%.1fus  mean=%.1fus  peak_memory=%.1fMB\n",
            $n,
            $times[(int) ($n * 0.50)],
            $times[(int) ($n * 0.95)],
            $times[(int) ($n * 0.99)],
            array_sum($times) / $n,
            memory_get_peak_usage(true) / 1048576
        );
        break;
    default:
        fwrite(STDERR, "unknown scenario $scenario\n");
        exit(2);
}
