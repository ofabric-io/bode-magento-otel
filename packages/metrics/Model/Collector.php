<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

use Bode\Tracing\Model\Environment;
use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * Reads the state of cron, indexers, Mview and the database queue and exports it as gauges. Run every minute by the
 * cron job and on demand by bin/magento bode:metrics:collect. Each reading is independent: a failing query is
 * skipped and counted, the others are still exported.
 */
class Collector
{
    private const DEFAULT_STUCK_MINUTES = 30;

    /**
     * @param ResourceConnection $resource
     * @param RecorderAccess $access
     * @param CollectorSamples $samples
     * @param Environment $env
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RecorderAccess $access,
        private readonly CollectorSamples $samples,
        private readonly Environment $env
    ) {
    }

    /**
     * Reads and exports everything.
     *
     * @return int number of gauge samples recorded
     */
    public function collect(): int
    {
        $recorder = $this->access->recorder();
        $count = 0;
        $readers = [
            'cron' => fn (): array => $this->cron(),
            'indexer' => fn (): array => $this->indexers(),
            'mview' => fn (): array => $this->mview(),
            'queue' => fn (): array => $this->queue(),
        ];
        foreach ($readers as $reader) {
            try {
                foreach ($reader() as [$instrument, $value, $attributes]) {
                    $recorder->gauge($instrument, $value, $attributes);
                    $count++;
                }
            } catch (Throwable) {
                // A table that does not exist (module disabled) or a transient error must not stop the others.
                continue;
            }
        }
        $recorder->flush();

        return $count;
    }

    /**
     * Cron schedule readings.
     *
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    private function cron(): array
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('cron_schedule');
        $ages = $db->fetchPairs(
            "SELECT status, TIMESTAMPDIFF(SECOND, MIN(scheduled_at), UTC_TIMESTAMP()) FROM $table "
            . "WHERE status IN ('pending', 'missed') AND scheduled_at < UTC_TIMESTAMP() GROUP BY status"
        );
        $minutes = max(1, $this->env->int('MAGENTO_OTEL_CRON_STUCK_MINUTES', self::DEFAULT_STUCK_MINUTES));
        $stuck = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM $table WHERE status = 'running' "
            . 'AND executed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $minutes . ' MINUTE)'
        );

        return array_merge($this->samples->cronAges($ages), $this->samples->cronStuck($stuck));
    }

    /**
     * Indexer state readings.
     *
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    private function indexers(): array
    {
        $db = $this->resource->getConnection();

        return $this->samples->indexerStatus(
            $db->fetchPairs('SELECT indexer_id, status FROM ' . $this->resource->getTableName('indexer_state'))
        );
    }

    /**
     * Mview backlog readings: the changelog of a view is the table "<view_id>_cl".
     *
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    private function mview(): array
    {
        $db = $this->resource->getConnection();
        $processed = $db->fetchPairs(
            'SELECT view_id, version_id FROM ' . $this->resource->getTableName('mview_state')
            . " WHERE mode = 'enabled'"
        );
        $versions = [];
        foreach ($processed as $view => $version) {
            $changelog = $this->resource->getTableName($view . '_cl');
            if (!$db->isTableExists($changelog)) {
                continue;
            }
            $versions[(string) $view] = [(int) $db->fetchOne("SELECT MAX(version_id) FROM $changelog"), (int) $version];
        }

        return $this->samples->mviewBacklog($versions);
    }

    /**
     * Database queue readings.
     *
     * @return array<int,array{string,int|float,array<string,string>}>
     */
    private function queue(): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll(
            'SELECT q.name AS name, m.status AS status, COUNT(*) AS total FROM '
            . $this->resource->getTableName('queue_message_status') . ' m JOIN '
            . $this->resource->getTableName('queue') . ' q ON q.id = m.queue_id '
            . 'WHERE m.status IN (2, 3, 5, 6) GROUP BY q.name, m.status'
        );

        return $this->samples->queueBacklog(array_map(
            static fn (array $row): array => [(string) $row['name'], (int) $row['status'], (int) $row['total']],
            $rows
        ));
    }
}
