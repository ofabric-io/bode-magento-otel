<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Instrumentation\HttpInstrumentation;
use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Test\Unit\Hook\FakeRegistrar;
use Bode\Tracing\Test\Unit\Hook\FakeSpan;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\PageCache\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * How the root span of a request gets its name from the hooks Magento's request flow triggers.
 */
class HttpInstrumentationTest extends TestCase
{
    /**
     * @var FakeRegistrar
     */
    private FakeRegistrar $registrar;

    /**
     * @var FakeSpan
     */
    private FakeSpan $root;

    protected function setUp(): void
    {
        $this->registrar = new FakeRegistrar();
        $env = new Environment(['MAGENTO_OTEL_ASSUME_WEB' => 'true']);
        $runtime = new Runtime(new Config($env, true), $env, $this->registrar);
        $this->root = new FakeSpan();
        $runtime->stack->push($this->root);
        (new HttpInstrumentation())->register($runtime);
    }

    public function testPageCacheHitIsNamedAfterTheCacheNotUnknown(): void
    {
        $this->pageCacheLoad(new \stdClass());
        $this->frontControllerDispatched($this->request('', '', ''));

        self::assertSame('GET page_cache_hit', $this->root->attributes['name']);
        self::assertSame('page_cache_hit', $this->root->attributes['http.route']);
        self::assertSame('hit', $this->root->attributes['bode.magento.page_cache']);
    }

    public function testRoutedRequestIsNamedAfterItsFullActionName(): void
    {
        $this->pageCacheLoad(false);
        $this->frontControllerDispatched($this->request('cms', 'index', 'index'));

        self::assertSame('GET cms_index_index', $this->root->attributes['name']);
        self::assertArrayNotHasKey('bode.magento.page_cache', $this->root->attributes);
    }

    public function testUnroutedRequestKeepsItsDefaultNameInsteadOfOverwritingIt(): void
    {
        $this->frontControllerDispatched($this->request('', '', ''));

        self::assertArrayNotHasKey('name', $this->root->attributes, 'a redirect has no action to name it after');
    }

    private function pageCacheLoad(mixed $response): void
    {
        $this->post(Kernel::class . '::load', [], $response);
    }

    private function frontControllerDispatched(object $request): void
    {
        $this->post(FrontController::class . '::dispatch', [$request], null);
    }

    /**
     * @param string $hook
     * @param array<int,mixed> $params
     * @param mixed $returnValue
     */
    private function post(string $hook, array $params, mixed $returnValue): void
    {
        $post = $this->registrar->hooks[$hook]['post'] ?? null;
        self::assertNotNull($post, "no hook registered for $hook");
        $post(null, $params, $returnValue, null);
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

            public function getFullActionName(): string
            {
                return "{$this->route}_{$this->controller}_{$this->action}";
            }

            public function getMethod(): string
            {
                return 'GET';
            }
        };
    }
}
