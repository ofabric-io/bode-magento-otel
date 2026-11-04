<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Model;

use Bode\Metrics\Model\CardinalityGuard;
use Bode\Metrics\Model\InstrumentCatalog;
use Bode\Metrics\Model\LabelPolicy;
use Bode\Metrics\Model\Recorder;
use PHPUnit\Framework\TestCase;

class RecorderTest extends TestCase
{
    /**
     * @var InMemorySink
     */
    private InMemorySink $sink;

    /**
     * @var Recorder
     */
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->sink = new InMemorySink();
        $this->recorder = new Recorder($this->sink, new LabelPolicy(), new CardinalityGuard(2));
    }

    public function testPersonalAndUnboundedAttributesNeverReachTheSink(): void
    {
        $this->recorder->count('magento.orders.placed', [
            'store' => 'default',
            'payment_method' => 'banktransfer',
            'enduser.id' => '42',
            'order.id' => '000001234',
            'client.address' => '203.0.113.7',
        ]);

        self::assertSame(
            [['add', 'magento.orders.placed', 1, ['store' => 'default', 'payment_method' => 'banktransfer']]],
            $this->sink->points
        );
    }

    public function testOverflowCollapsesIntoOther(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $store) {
            $this->recorder->count('magento.orders.placed', ['store' => $store]);
        }

        self::assertSame(['a', 'b', 'other', 'other'], array_map(
            static fn (array $point): string => $point[3]['store'],
            $this->sink->points
        ));
    }

    public function testHistogramsAndGauges(): void
    {
        $this->recorder->observe('magento.cron.job.duration', 1.5, ['cron.job_code' => 'x', 'cron.group' => 'default']);
        $this->recorder->gauge('magento.mview.backlog', 7, ['view_id' => 'catalog_product_price']);

        self::assertSame('record', $this->sink->points[0][0]);
        self::assertSame(1.5, $this->sink->points[0][2]);
        self::assertSame(
            ['gauge', 'magento.mview.backlog', 7, ['view_id' => 'catalog_product_price']],
            $this->sink->points[1]
        );
    }

    public function testSinkFailureIsCountedAndNeverThrown(): void
    {
        $this->sink->failing = true;

        $this->recorder->count('magento.emails', ['outcome' => 'sent']);
        $this->recorder->flush();

        self::assertSame(1, $this->recorder->failures());
    }

    public function testEveryCatalogInstrumentHasALabelPolicyEntry(): void
    {
        $policy = new LabelPolicy();

        foreach (array_keys(InstrumentCatalog::INSTRUMENTS) as $name) {
            self::assertTrue($policy->knows($name), "$name has no allow-list");
        }
        foreach (array_keys(LabelPolicy::DEFAULT_ALLOWED) as $name) {
            self::assertArrayHasKey($name, InstrumentCatalog::INSTRUMENTS, "$name is not in the catalog");
        }
    }
}
