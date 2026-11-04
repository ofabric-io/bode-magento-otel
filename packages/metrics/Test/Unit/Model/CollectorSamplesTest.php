<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Model;

use Bode\Metrics\Model\CollectorSamples;
use PHPUnit\Framework\TestCase;

class CollectorSamplesTest extends TestCase
{
    /**
     * @var CollectorSamples
     */
    private CollectorSamples $samples;

    protected function setUp(): void
    {
        $this->samples = new CollectorSamples();
    }

    public function testCronAgesAlwaysReportBothStatusesAndZeroWhenNothingIsOverdue(): void
    {
        self::assertSame(
            [
                ['magento.cron.oldest_pending_age', 420, ['status' => 'pending']],
                ['magento.cron.oldest_pending_age', 0, ['status' => 'missed']],
            ],
            $this->samples->cronAges(['pending' => '420'])
        );
    }

    public function testNegativeAgesAreClampedToZero(): void
    {
        self::assertSame(0, $this->samples->cronAges(['pending' => -5])[0][1]);
    }

    public function testIndexerStatusEmitsOneHotSeriesPerStatus(): void
    {
        $out = $this->samples->indexerStatus(['catalog_product_price' => 'invalid']);

        self::assertSame(
            [
                ['magento.indexer.status', 0, ['indexer_id' => 'catalog_product_price', 'status' => 'valid']],
                ['magento.indexer.status', 1, ['indexer_id' => 'catalog_product_price', 'status' => 'invalid']],
                ['magento.indexer.status', 0, ['indexer_id' => 'catalog_product_price', 'status' => 'working']],
            ],
            $out
        );
    }

    public function testMviewBacklogIsChangelogMinusProcessedAndNeverNegative(): void
    {
        $out = $this->samples->mviewBacklog(['a' => [120, 100], 'b' => [5, 9]]);

        self::assertSame(20, $out[0][1]);
        self::assertSame(0, $out[1][1]);
        self::assertSame(['view_id' => 'a'], $out[0][2]);
    }

    public function testQueueBacklogSkipsCompletedAndUnknownStatuses(): void
    {
        $out = $this->samples->queueBacklog([
            ['async.operations.all', 2, 14],
            ['async.operations.all', 4, 9000],
            ['async.operations.all', 99, 1],
            ['inventory', 6, 3],
        ]);

        self::assertSame(
            [
                ['magento.queue.backlog', 14, ['queue' => 'async.operations.all', 'status' => 'new']],
                ['magento.queue.backlog', 3, ['queue' => 'inventory', 'status' => 'error']],
            ],
            $out
        );
    }

    public function testStuckJobs(): void
    {
        self::assertSame([['magento.cron.jobs_stuck', 2, []]], $this->samples->cronStuck(2));
    }
}
