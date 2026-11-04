<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Test\Unit\Support\Fixture;
use Bode\Logs\Test\Unit\Support\FakeRequestProvider;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    public function testDescribesAWebRequestWithoutTheQueryString(): void
    {
        $context = Fixture::execution(
            [],
            [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/checkout/cart/add/id/5?token=secret&email=a@b.c',
                'REMOTE_ADDR' => '203.0.113.77',
            ],
            [],
            'fpm-fcgi',
            new FakeRequestProvider('frontend', 'checkout_cart_add')
        );
        $attributes = $context->attributes();

        self::assertSame('frontend', $attributes['magento.area']);
        self::assertSame('POST', $attributes['http.request.method']);
        self::assertSame('/checkout/cart/add/id/5', $attributes['url.path']);
        self::assertSame('checkout_cart_add', $attributes['http.route']);
        self::assertStringNotContainsString('secret', (string) json_encode($attributes));
        self::assertStringNotContainsString('a@b.c', (string) json_encode($attributes));
    }

    public function testTruncatesTheClientAddressByDefault(): void
    {
        $context = Fixture::execution(
            [],
            ['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.77'],
            [],
            'fpm-fcgi'
        );

        self::assertSame('203.0.113.0', $context->attributes()['client.address']);
    }

    public function testHonoursTheIpModeOfTheTracingPackage(): void
    {
        $full = Fixture::execution(
            ['MAGENTO_OTEL_IP_MODE' => 'full'],
            ['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.77'],
            [],
            'fpm-fcgi'
        );
        $off = Fixture::execution(
            ['MAGENTO_OTEL_IP_MODE' => 'off'],
            ['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.77'],
            [],
            'fpm-fcgi'
        );

        self::assertSame('203.0.113.77', $full->attributes()['client.address']);
        self::assertArrayNotHasKey('client.address', $off->attributes());
    }

    public function testUsesTheRestRouteTemplateForWebApiRequests(): void
    {
        $context = Fixture::execution(
            [],
            ['REQUEST_URI' => '/rest/V1/products/ABC-123', 'REQUEST_METHOD' => 'GET'],
            [],
            'fpm-fcgi',
            new FakeRequestProvider('webapi_rest', 'ignored')
        );

        self::assertSame('/rest/V1/products/:id', $context->attributes()['http.route']);
    }

    public function testUsesTheConfiguredCorrelationHeader(): void
    {
        $context = Fixture::execution(
            ['MAGENTO_OTEL_CORRELATION_HEADERS' => 'CF-Ray, X-Request-Id'],
            ['REQUEST_URI' => '/', 'HTTP_X_REQUEST_ID' => 'req-42'],
            [],
            'fpm-fcgi'
        );

        self::assertSame('req-42', $context->attributes()['correlation.id']);
    }

    public function testGeneratesOneCorrelationIdPerProcessWhenNoHeaderApplies(): void
    {
        $context = Fixture::execution();
        $first = $context->attributes()['correlation.id'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
        self::assertSame($first, $context->attributes()['correlation.id']);
    }

    public function testNamesTheCliCommand(): void
    {
        $context = Fixture::execution([], [], ['bin/magento', 'indexer:reindex', '--no-ansi', 'catalog_product']);

        $attributes = $context->attributes();
        self::assertSame('indexer:reindex', $attributes['cli.command']);
        self::assertArrayNotHasKey('messaging.consumer', $attributes);
        self::assertArrayNotHasKey('http.request.method', $attributes);
    }

    public function testNamesTheQueueConsumer(): void
    {
        $context = Fixture::execution(
            [],
            [],
            ['bin/magento', 'queue:consumers:start', 'async.operations.all', '--max-messages=100']
        );

        $attributes = $context->attributes();
        self::assertSame('queue:consumers:start', $attributes['cli.command']);
        self::assertSame('async.operations.all', $attributes['messaging.consumer']);
    }

    public function testCarriesTheCronJobOnlyWhileItRuns(): void
    {
        $context = Fixture::execution([], [], ['bin/magento', 'cron:run']);

        self::assertArrayNotHasKey('cron.job_code', $context->attributes());

        $context->setCronJob('catalog_product_alert', '1234');
        $running = $context->attributes();
        self::assertSame('catalog_product_alert', $running['cron.job_code']);
        self::assertSame('1234', $running['cron.schedule_id']);

        $context->setCronJob(null);
        self::assertArrayNotHasKey('cron.job_code', $context->attributes());
        self::assertArrayNotHasKey('cron.schedule_id', $context->attributes());
    }

    public function testResetForgetsTheFinishedRequest(): void
    {
        $context = Fixture::execution();
        $context->setCronJob('job', '1');
        $before = $context->attributes()['correlation.id'];

        $context->_resetState();

        self::assertArrayNotHasKey('cron.job_code', $context->attributes());
        self::assertNotSame($before, $context->attributes()['correlation.id']);
    }
}
