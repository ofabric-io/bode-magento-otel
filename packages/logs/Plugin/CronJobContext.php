<?php

declare(strict_types=1);

namespace Bode\Logs\Plugin;

use Bode\Logs\Model\ExecutionContext;

/**
 * Tells the logs which cron job is running, without ext-opentelemetry (Magento's cron observer keeps the job
 * private, so the only public moments are the schedule row changing). The job is noted when its schedule row is
 * locked for running and forgotten when the row reaches a final status or the observer finishes, so lines logged
 * between jobs carry no job at all.
 */
class CronJobContext
{
    private const FINAL_STATUSES = ['success', 'error', 'missed'];

    /**
     * @param ExecutionContext $context
     */
    public function __construct(private readonly ExecutionContext $context)
    {
    }

    /**
     * After Schedule::tryLockJob(): the job is about to run when the lock was won.
     *
     * @param object $subject the Cron Schedule
     * @param mixed $result
     * @return mixed
     */
    public function afterTryLockJob(object $subject, mixed $result): mixed
    {
        if ($result) {
            $this->context->setCronJob(
                (string) $subject->getData('job_code'),
                $subject->getData('schedule_id') === null ? null : (string) $subject->getData('schedule_id')
            );
        }

        return $result;
    }

    /**
     * After Schedule::save(): a final status ends the job.
     *
     * @param object $subject the Cron Schedule
     * @param mixed $result
     * @return mixed
     */
    public function afterSave(object $subject, mixed $result): mixed
    {
        if (in_array((string) $subject->getData('status'), self::FINAL_STATUSES, true)) {
            $this->context->setCronJob(null);
        }

        return $result;
    }

    /**
     * After the cron observer ran: nothing is running any more.
     *
     * @param object $subject the observer
     * @param mixed $result
     * @return mixed
     */
    public function afterExecute(object $subject, mixed $result): mixed
    {
        $this->context->setCronJob(null);

        return $result;
    }
}
