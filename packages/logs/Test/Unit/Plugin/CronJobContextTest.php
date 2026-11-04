<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Plugin;

use Bode\Logs\Plugin\CronJobContext;
use Bode\Logs\Test\Unit\Support\Fixture;
use PHPUnit\Framework\TestCase;

class CronJobContextTest extends TestCase
{
    /**
     * A stand-in for Magento's Cron Schedule: a data object.
     *
     * @param array<string,mixed> $data
     * @return object
     */
    private function schedule(array $data): object
    {
        return new class ($data) {
            /**
             * @param array<string,mixed> $data
             */
            public function __construct(private readonly array $data)
            {
            }

            /**
             * @param string $key
             * @return mixed
             */
            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }
        };
    }

    public function testNotesTheJobOnceItsLockIsWon(): void
    {
        $context = Fixture::execution();
        $plugin = new CronJobContext($context);

        $schedule = $this->schedule(['job_code' => 'catalog_index_refresh_price', 'schedule_id' => 77]);
        $plugin->afterTryLockJob($schedule, true);

        $attributes = $context->attributes();
        self::assertSame('catalog_index_refresh_price', $attributes['cron.job_code']);
        self::assertSame('77', $attributes['cron.schedule_id']);
    }

    public function testIgnoresALockThatWasLost(): void
    {
        $context = Fixture::execution();
        (new CronJobContext($context))->afterTryLockJob($this->schedule(['job_code' => 'x']), false);

        self::assertArrayNotHasKey('cron.job_code', $context->attributes());
    }

    public function testNeverChangesWhatMagentoReturns(): void
    {
        $plugin = new CronJobContext(Fixture::execution());

        self::assertTrue($plugin->afterTryLockJob($this->schedule(['job_code' => 'x']), true));
        self::assertSame('result', $plugin->afterSave($this->schedule([]), 'result'));
        self::assertSame('done', $plugin->afterExecute($this->schedule([]), 'done'));
    }

    public function testAFinalStatusEndsTheJob(): void
    {
        foreach (['success', 'error', 'missed'] as $status) {
            $context = Fixture::execution();
            $plugin = new CronJobContext($context);
            $plugin->afterTryLockJob($this->schedule(['job_code' => 'job']), true);

            $plugin->afterSave($this->schedule(['status' => $status]), null);

            self::assertArrayNotHasKey('cron.job_code', $context->attributes(), $status);
        }
    }

    public function testASaveWithoutAFinalStatusKeepsTheJob(): void
    {
        $context = Fixture::execution();
        $plugin = new CronJobContext($context);
        $plugin->afterTryLockJob($this->schedule(['job_code' => 'job']), true);

        $plugin->afterSave($this->schedule(['status' => 'running']), null);

        self::assertSame('job', $context->attributes()['cron.job_code']);
    }

    public function testTheObserverFinishingEndsAnyJob(): void
    {
        $context = Fixture::execution();
        $plugin = new CronJobContext($context);
        $plugin->afterTryLockJob($this->schedule(['job_code' => 'job']), true);

        $plugin->afterExecute($this->schedule([]), null);

        self::assertArrayNotHasKey('cron.job_code', $context->attributes());
    }
}
