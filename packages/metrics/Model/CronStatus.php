<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * Maps a cron schedule status to the outcome label of magento.cron.job.runs: success, error or missed.
 */
class CronStatus
{
    public const SUCCESS = 'success';
    public const ERROR = 'error';
    public const MISSED = 'missed';

    /**
     * Outcome of one tryRunJob call.
     *
     * @param string $scheduleStatus status of the Schedule row after the run (success, error, missed, running...)
     * @param bool $threw whether the job raised an exception
     * @return string
     */
    public function fromRun(string $scheduleStatus, bool $threw): string
    {
        if ($threw || $scheduleStatus === 'error') {
            return self::ERROR;
        }
        if ($scheduleStatus === 'missed') {
            return self::MISSED;
        }

        // 'running' after the call returns means the job finished without Magento recording an error.
        return self::SUCCESS;
    }
}
