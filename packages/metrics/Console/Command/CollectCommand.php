<?php

declare(strict_types=1);

namespace Bode\Metrics\Console\Command;

use Bode\Metrics\Model\Collector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento bode:metrics:collect: reads cron, indexer, Mview and queue state once and exports it as gauges.
 */
class CollectCommand extends Command
{
    /**
     * @param Collector $collector
     */
    public function __construct(private readonly Collector $collector)
    {
        parent::__construct('bode:metrics:collect');
        $this->setDescription('Export cron, indexer, Mview and queue state as OpenTelemetry gauges');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Recorded %d gauge samples.', $this->collector->collect()));

        return Command::SUCCESS;
    }
}
