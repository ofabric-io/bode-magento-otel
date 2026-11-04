<?php

declare(strict_types=1);

namespace Bode\Metrics\Instrumentation;

use Bode\Metrics\Hook\MetricsContext;
use Bode\Metrics\Model\CronStatus;
use Magento\Cron\Model\Schedule;
use Magento\Cron\Observer\ProcessCronQueueObserver;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\MessageQueue\Consumer;
use Magento\Indexer\Model\Indexer;

/**
 * Background work: cron jobs (runs by outcome, duration, missed), indexer runs, cache flushes and cleans, queue
 * messages. Everything is labelled by bounded codes: job code and group, indexer id, cache type, topic and consumer.
 */
class JobMetrics implements InstrumentationInterface
{
    /**
     * @inheritdoc
     */
    public function register(MetricsContext $context): void
    {
        $context->timed(
            'cron',
            ProcessCronQueueObserver::class,
            'tryRunJob',
            function (float $seconds, ?object $s, array $params, mixed $ret, mixed $error) use ($context): void {
                $config = is_array($params[2] ?? null) ? $params[2] : [];
                $schedule = $params[3] ?? null;
                $attributes = [
                    'cron.job_code' => $context->label($config['name'] ?? 'unknown'),
                    'cron.group' => $context->label($params[4] ?? 'default'),
                ];
                $status = (new CronStatus())->fromRun(
                    is_object($schedule) && method_exists($schedule, 'getStatus')
                        ? (string) $schedule->getStatus()
                        : '',
                    $error !== null
                );
                $context->recorder->count('magento.cron.job.runs', $attributes + ['status' => $status]);
                $context->recorder->observe('magento.cron.job.duration', $seconds, $attributes);
                $context->reportSelf();
            }
        );

        // Missed jobs never run, so tryRunJob does not see them: Magento marks the schedule row missed instead.
        $context->after(
            'cron',
            Schedule::class,
            'setStatus',
            function (?object $schedule, array $params) use ($context): void {
                if (($params[0] ?? '') !== Schedule::STATUS_MISSED || $schedule === null) {
                    return;
                }
                $context->recorder->count('magento.cron.job.runs', [
                    'cron.job_code' => $context->label($schedule->getJobCode()),
                    'cron.group' => 'unknown',
                    'status' => 'missed',
                ]);
            }
        );

        foreach (['reindexAll', 'reindexList', 'reindexRow'] as $method) {
            $context->timed(
                'indexer',
                Indexer::class,
                $method,
                function (float $seconds, ?object $indexer, array $p, mixed $r, mixed $error) use ($context): void {
                    $attributes = [
                        'indexer_id' => $context->label($indexer?->getId()),
                        'mode' => $indexer !== null && $indexer->isScheduled() ? 'schedule' : 'save',
                    ];
                    $context->recorder->count('magento.indexer.runs', $attributes + [
                        'outcome' => $error === null ? 'success' : 'error',
                    ]);
                    $context->recorder->observe('magento.indexer.duration', $seconds, $attributes);
                }
            );
        }

        foreach (['flush', 'clean'] as $operation) {
            $context->after(
                'cache',
                Manager::class,
                $operation,
                function (?object $s, array $params) use ($context, $operation): void {
                    foreach (array_slice(is_array($params[0] ?? null) ? $params[0] : [], 0, 20) as $type) {
                        $label = $context->label(strtolower((string) $type));
                        $context->recorder->count('magento.cache.flushes', ['cache_type' => $label]);
                        $context->recorder->count(
                            'magento.cache.operations',
                            ['cache_type' => $label, 'operation' => $operation, 'result' => 'ok']
                        );
                    }
                }
            );
        }

        $context->timed(
            'queue',
            Consumer::class,
            'dispatchMessage',
            function (float $seconds, ?object $consumer, array $params, mixed $r, mixed $error) use ($context): void {
                $props = is_object($params[0] ?? null) && method_exists($params[0], 'getProperties')
                    ? (array) $params[0]->getProperties()
                    : [];
                $attributes = [
                    'topic' => $context->label($props['topic_name'] ?? 'unknown'),
                    // The configuration is private; read it through a closure bound to the consumer.
                    'consumer' => $context->label(
                        $consumer === null
                            ? 'unknown'
                            : (fn () => $this->configuration->getConsumerName())->call($consumer)
                    ),
                ];
                $context->recorder->count(
                    'magento.queue.messages',
                    $attributes + ['outcome' => $error === null ? 'success' : 'error']
                );
                $context->recorder->observe('magento.queue.message.duration', $seconds, $attributes);
                $context->reportSelf();
            }
        );
    }
}
