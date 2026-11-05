<?php

declare(strict_types=1);

/**
 * One-shot bootstrap process (same shape as e2e/assert/di.php) that seeds one `cron_schedule` row with a job_code
 * that exists in no module's crontab.xml, so the next `cron:run` deterministically fails it.
 *
 * This produces a "job code not found" failure (Magento\Cron\Model\ResourceModel\Schedule /
 * Observer\ProcessCronQueueObserver logs "Invalid callback: ... not callable" and marks the schedule 'error'), not an
 * in-job thrown exception. That is a reasonable approximation of "a cron job fails" given the constraint of not
 * adding a whole new test-only Magento module just to register one throwing job: it still exercises the real
 * cron-tracing failure path (a schedule ending in status=error) without inventing new production code whose only
 * purpose is to break on command. A thrown-exception-inside-a-real-job failure path, if ever needed, would need a
 * tiny dedicated module with its own crontab.xml entry - flagged here, not attempted.
 */

require '/magento/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$table = $resource->getTableName('cron_schedule');

$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$jobCode = 'bode_e2e_bogus_job_' . bin2hex(random_bytes(4));

$connection->insert($table, [
    'job_code' => $jobCode,
    'status' => 'pending',
    'created_at' => $now,
    'scheduled_at' => $now,
]);

echo "trigger-cron-fail-seed: inserted cron_schedule row for nonexistent job_code=$jobCode\n";
