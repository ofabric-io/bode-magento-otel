<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * Turns the rows the Collector reads from the database into gauge samples. Pure functions: the SQL lives in
 * Collector, so these rules can be unit-tested without a database.
 *
 * A sample is [instrument, value, attributes].
 */
class CollectorSamples
{
    public const INDEXER_STATUSES = ['valid', 'invalid', 'working'];
    public const QUEUE_STATUSES = [
        2 => 'new',
        3 => 'in_progress',
        4 => 'complete',
        5 => 'retry_required',
        6 => 'error',
    ];

    /**
     * Oldest overdue schedule per status (pending, missed), in seconds.
     *
     * Zero when there is none, so an alert on "age above N" resolves by itself.
     *
     * @param array<string,int|string|null> $ageByStatus status => age in seconds
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    public function cronAges(array $ageByStatus): array
    {
        $samples = [];
        foreach (['pending', 'missed'] as $status) {
            $age = $ageByStatus[$status] ?? 0;
            $samples[] = ['magento.cron.oldest_pending_age', max(0, (int) $age), ['status' => $status]];
        }

        return $samples;
    }

    /**
     * Jobs stuck in running.
     *
     * @param int $stuck
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    public function cronStuck(int $stuck): array
    {
        return [['magento.cron.jobs_stuck', max(0, $stuck), []]];
    }

    /**
     * One series per indexer and status.
     *
     * 1 for the status it is in and 0 for the others, so a state change never leaves a stale series reading 1.
     *
     * @param array<string,string> $statusByIndexer indexer_id => status
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    public function indexerStatus(array $statusByIndexer): array
    {
        $samples = [];
        foreach ($statusByIndexer as $id => $status) {
            foreach (self::INDEXER_STATUSES as $known) {
                $samples[] = [
                    'magento.indexer.status',
                    $status === $known ? 1 : 0,
                    ['indexer_id' => (string) $id, 'status' => $known],
                ];
            }
        }

        return $samples;
    }

    /**
     * Versions written to a changelog and not yet processed.
     *
     * @param array<string,array{int,int}> $versionsByView view_id => [changelog version, processed version]
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    public function mviewBacklog(array $versionsByView): array
    {
        $samples = [];
        foreach ($versionsByView as $view => [$changelog, $processed]) {
            $samples[] = ['magento.mview.backlog', max(0, $changelog - $processed), ['view_id' => (string) $view]];
        }

        return $samples;
    }

    /**
     * Database queue messages that still need attention, by queue and status.
     *
     * @param array<int,array{string,int,int}> $rows [queue name, status code, count]
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    public function queueBacklog(array $rows): array
    {
        $samples = [];
        foreach ($rows as [$queue, $code, $count]) {
            $status = self::QUEUE_STATUSES[$code] ?? null;
            if ($status === null || $status === 'complete') {
                continue;
            }
            $samples[] = ['magento.queue.backlog', $count, ['queue' => $queue, 'status' => $status]];
        }

        return $samples;
    }
}
