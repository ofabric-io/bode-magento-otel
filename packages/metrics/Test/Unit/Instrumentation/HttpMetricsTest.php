<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Instrumentation;

use Bode\Metrics\Hook\MetricsContext;
use Bode\Metrics\Instrumentation\HttpMetrics;
use Bode\Metrics\Model\CardinalityGuard;
use Bode\Metrics\Model\LabelPolicy;
use Bode\Metrics\Model\Recorder;
use Bode\Metrics\Test\Unit\Hook\FakeRegistrar;
use Bode\Metrics\Test\Unit\Model\InMemorySink;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\RouteNormalizer;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\PageCache\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The http.route label follows the same resource-naming rules as the trace's span name.
 */
class HttpMetricsTest extends TestCase
{
    /**
     * @var FakeRegistrar
     */
    private FakeRegistrar $registrar;

    /**
     * @var InMemorySink
     */
    private InMemorySink $sink;

    protected function setUp(): void
    {
        $this->registrar = new FakeRegistrar();
        $this->sink = new InMemorySink();
        $context = new MetricsContext(
            new Recorder($this->sink, new LabelPolicy(), new CardinalityGuard()),
            $this->registrar,
            new Environment(['MAGENTO_OTEL_ASSUME_WEB' => 'true']),
            new RouteNormalizer()
        );
        (new HttpMetrics())->register($context);
    }

    public function testRoutedRequestIsLabelledAfterItsControllerAndAction(): void
    {
        $this->registrar->post(FrontController::class . '::dispatch', [$this->request('cms', 'index', 'index')]);
        $this->runBootstrap();

        self::assertSame('CmsIndexController#index', $this->sink->points[0][3]['http.route']);
    }

    public function testNorouteRequestNeverCarriesTheRawControllerSegment(): void
    {
        $this->registrar->post(
            FrontController::class . '::dispatch',
            [$this->request('cms', 'whatever-a-bot-probed', 'noroute')]
        );
        $this->runBootstrap();

        $route = $this->sink->points[0][3]['http.route'];
        self::assertSame('CmsNorouteController#noroute', $route);
        self::assertStringNotContainsString('whatever-a-bot-probed', $route);
    }

    public function testPageCacheHitFallsBackToTheBestEffortPathTemplate(): void
    {
        $_SERVER['REQUEST_URI'] = '/catalog/category/view/id/42';
        $this->registrar->post(Kernel::class . '::load', [], new \stdClass());
        $this->runBootstrap();

        self::assertSame('/catalog/category/view/id/:id', $this->sink->points[0][3]['http.route']);
        unset($_SERVER['REQUEST_URI']);
    }

    private function runBootstrap(): void
    {
        $this->registrar->pre(Bootstrap::class . '::run');
        $this->registrar->post(Bootstrap::class . '::run');
    }

    private function request(string $route, string $controller, string $action): object
    {
        return new class ($route, $controller, $action) {
            public function __construct(
                private readonly string $route,
                private readonly string $controller,
                private readonly string $action
            ) {
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
        };
    }
}
