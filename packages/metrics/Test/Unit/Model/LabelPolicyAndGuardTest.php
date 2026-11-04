<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Model;

use Bode\Metrics\Model\CardinalityGuard;
use Bode\Metrics\Model\LabelPolicy;
use PHPUnit\Framework\TestCase;

class LabelPolicyAndGuardTest extends TestCase
{
    public function testOnlyAllowListedKeysSurvive(): void
    {
        $policy = new LabelPolicy();

        $out = $policy->filter('http.server.request.duration', [
            'http.route' => 'catalog_product_view',
            'http.request.method' => 'GET',
            'http.response.status_class' => '2xx',
            'area' => 'frontend',
            'enduser.id' => '42',
            'client.address' => '203.0.113.7',
            'url.full' => 'https://shop.example/x?token=abc',
            'session.id' => 'abc',
        ]);

        self::assertSame(
            [
                'http.route' => 'catalog_product_view',
                'http.request.method' => 'GET',
                'http.response.status_class' => '2xx',
                'area' => 'frontend',
            ],
            $out
        );
    }

    public function testUnknownInstrumentGetsNoAttributes(): void
    {
        self::assertSame([], (new LabelPolicy())->filter('made.up', ['area' => 'x']));
    }

    public function testForbiddenKeysCanNeverBeAllowed(): void
    {
        $policy = new LabelPolicy(['x' => ['enduser.id', 'topic']]);

        self::assertSame(['topic' => 'a'], $policy->filter('x', ['enduser.id' => '1', 'topic' => 'a']));
    }

    public function testNoDefaultInstrumentAllowsAForbiddenKey(): void
    {
        $policy = new LabelPolicy();

        self::assertSame([], array_values(array_intersect($policy->allKeys(), LabelPolicy::FORBIDDEN_KEYS)));
    }

    public function testNullValuesAreDropped(): void
    {
        self::assertSame([], (new LabelPolicy())->filter('magento.orders.placed', ['store' => null]));
    }

    public function testGuardCollapsesNewValuesAfterTheLimit(): void
    {
        $guard = new CardinalityGuard(3);

        foreach (['a', 'b', 'c'] as $value) {
            self::assertSame(['topic' => $value], $guard->guard(['topic' => $value]));
        }
        self::assertSame(['topic' => 'other'], $guard->guard(['topic' => 'd']));
        self::assertSame(['topic' => 'other'], $guard->guard(['topic' => 'e']));
        // Values already seen keep their own series.
        self::assertSame(['topic' => 'a'], $guard->guard(['topic' => 'a']));
        self::assertSame(2, $guard->collapsed());
    }

    public function testGuardLimitsAreIndependentPerKey(): void
    {
        $guard = new CardinalityGuard(1);

        $guard->guard(['topic' => 'a']);

        self::assertSame(['consumer' => 'x'], $guard->guard(['consumer' => 'x']));
    }
}
