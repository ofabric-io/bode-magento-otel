<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Model;

use Bode\Tracing\Model\RouteNormalizer;
use PHPUnit\Framework\TestCase;

class RouteNormalizerTest extends TestCase
{
    /**
     * @var RouteNormalizer
     */
    private RouteNormalizer $routes;

    protected function setUp(): void
    {
        $this->routes = new RouteNormalizer();
    }

    public function testQueryStringAndFragmentAreStripped(): void
    {
        $uri = '/catalogsearch/result/?q=secret&token=abc#frag';
        self::assertSame('/catalogsearch/result/', $this->routes->path($uri));
        self::assertSame('/checkout/cart/', $this->routes->path('https://shop.example/checkout/cart/?coupon=SAVE'));
        self::assertSame('/', $this->routes->path('?only=query'));
        self::assertSame('/', $this->routes->path(''));
    }

    public function testResourceNameIsDatadogStyleControllerHash(): void
    {
        self::assertSame(
            'CatalogProductController#view',
            $this->routes->resourceName('catalog', 'product', 'view')
        );
        self::assertSame(
            'AdminhtmlOrderController#view',
            $this->routes->resourceName('adminhtml', 'order', 'view')
        );
        self::assertSame('CmsIndexController#index', $this->routes->resourceName('CMS', 'Index', 'index'));
        self::assertSame('unknown', $this->routes->resourceName('', '', ''));
    }

    public function testResourceNameDropsUnsafeCharacters(): void
    {
        self::assertSame(
            'CataLogProDuctController#view',
            $this->routes->resourceName('cata log', 'pro/duct', 'vie.w')
        );
    }

    public function testRestTemplateReplacesIdsAndLowerCasesRouteWords(): void
    {
        self::assertSame('/rest/v1/products/:id', $this->routes->restTemplate('/rest/V1/products/ABC-123'));
        self::assertSame('/rest/v1/orders/:id/comments', $this->routes->restTemplate('/rest/V1/orders/1042/comments'));
        self::assertSame('/rest/v1/carts/mine/items', $this->routes->restTemplate('/rest/V1/carts/mine/items?x=1'));
        self::assertSame(
            '/rest/default/v1/customers/:id',
            $this->routes->restTemplate('/rest/default/V1/customers/jane%40example.com')
        );
    }

    public function testRestTemplateIsBoundedInDepthAndLength(): void
    {
        $deep = '/' . implode('/', array_fill(0, 20, 'segment'));
        $template = $this->routes->restTemplate($deep);

        self::assertStringEndsWith('/...', $template);
        self::assertLessThanOrEqual(200 + strlen('...'), strlen($template));

        $long = '/' . str_repeat('a', 500);
        self::assertLessThanOrEqual(200 + strlen('...'), strlen($this->routes->restTemplate($long)));
    }

    public function testGraphQlOperationIsBounded(): void
    {
        self::assertSame('GetCart', $this->routes->graphQlOperation('GetCart'));
        self::assertSame('anonymous', $this->routes->graphQlOperation(null));
        self::assertSame('anonymous', $this->routes->graphQlOperation('  '));
        self::assertSame('abc', $this->routes->graphQlOperation('a b!c'));
        self::assertSame(64, strlen($this->routes->graphQlOperation(str_repeat('x', 500))));
    }

    public function testStatusClass(): void
    {
        self::assertSame('2xx', $this->routes->statusClass(204));
        self::assertSame('4xx', $this->routes->statusClass(404));
        self::assertSame('5xx', $this->routes->statusClass(503));
        self::assertSame('other', $this->routes->statusClass(0));
        self::assertSame('other', $this->routes->statusClass(99));
    }
}
