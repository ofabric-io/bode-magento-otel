<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Observer\ProcessCronQueueObserver;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * One root span per cron job: ProcessCronQueueObserver::tryRunJob runs every job of every group. The trace is named
 * after the job code (bounded by the installed jobs), carries the group, the final schedule status and, for failures,
 * the exception class. Status changes of a Schedule row (running, success, error, missed) are span events.
 */
class CronInstrumentation implements InstrumentationInterface
{
    private const OBSERVER = ProcessCronQueueObserver::class;
    private const SCHEDULE = Schedule::class;
    private const STATUSES = ['pending', 'running', 'success', 'missed', 'error'];

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        $runtime->instrumenter->span(
            'cron',
            self::OBSERVER,
            'tryRunJob',
            function (?object $subject, array $params) use ($runtime): SpanHandleInterface {
                // tryRunJob($scheduledTime, $currentTime, $jobConfig, $schedule, $groupId)
                $config = is_array($params[2] ?? null) ? $params[2] : [];
                $code = $this->safeLabel((string) ($config['name'] ?? 'unknown'));

                return $runtime->spans->start(
                    'cron ' . $code,
                    SpanKind::KIND_INTERNAL,
                    [
                        'bode.magento.cron.job_code' => $code,
                        'bode.magento.cron.group' => $this->safeLabel((string) ($params[4] ?? 'default')),
                    ]
                );
            },
            function (SpanHandleInterface $span, ?object $subject, array $params): void {
                $schedule = $params[3] ?? null;
                if (is_object($schedule) && method_exists($schedule, 'getStatus')) {
                    $status = (string) $schedule->getStatus();
                    $span->setAttribute(
                        'bode.magento.cron.status',
                        in_array($status, self::STATUSES, true) ? $status : 'other'
                    );
                }
            },
            true
        );

        $runtime->instrumenter->after(
            'cron',
            self::SCHEDULE,
            'setStatus',
            function (SpanHandleInterface $span, ?object $subject, array $params): void {
                $status = (string) ($params[0] ?? '');
                $span->addEvent(
                    'cron.schedule.status',
                    ['status' => in_array($status, self::STATUSES, true) ? $status : 'other']
                );
            }
        );
    }

    /**
     * Reduces a value to a short, label-safe string.
     *
     * @param string $value
     * @return string
     */
    private function safeLabel(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $value), 0, 100);
    }
}
