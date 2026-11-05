<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Model;

use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\SpanBudget;
use PHPUnit\Framework\TestCase;

class ConfigAndBudgetTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return Config
     */
    private function config(array $env = []): Config
    {
        return new Config(new Environment($env), true);
    }

    public function testDefaultGroupsAreConservative(): void
    {
        $config = $this->config();

        foreach (['http', 'cron', 'cli', 'indexer', 'cache', 'queue', 'outbound'] as $group) {
            self::assertTrue($config->isGroupEnabled($group), $group);
        }
        foreach (['events', 'sql', 'entities', 'eav', 'redis', 'business', 'data'] as $group) {
            self::assertFalse($config->isGroupEnabled($group), $group);
        }
    }

    public function testGroupsCanBeListedAddedAndSubtracted(): void
    {
        $only = $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'http,sql']);
        self::assertTrue($only->isGroupEnabled('sql'));
        self::assertFalse($only->isGroupEnabled('cron'));

        $minus = $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => '-cache']);
        self::assertFalse($minus->isGroupEnabled('cache'));
        self::assertTrue($minus->isGroupEnabled('http'));

        $all = $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'all,-redis']);
        self::assertTrue($all->isGroupEnabled('sql'));
        self::assertFalse($all->isGroupEnabled('redis'));

        $junk = $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'http,nonsense']);
        self::assertSame(['http'], $junk->groups());
    }

    public function testDeprecatedAliasesResolveToEntitiesAndDataIsComposite(): void
    {
        self::assertSame(['entities'], $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'eav'])->groups());
        self::assertSame(['entities'], $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'business,eav'])->groups());
        self::assertSame(
            ['entities', 'sql', 'redis'],
            $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'data'])->groups()
        );
        $mixed = $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'http,data,-redis']);
        self::assertTrue($mixed->isGroupEnabled('entities'));
        self::assertTrue($mixed->isGroupEnabled('sql'));
        self::assertFalse($mixed->isGroupEnabled('redis'));
        $all = $this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'all,-data']);
        self::assertFalse($all->isGroupEnabled('entities'));
        self::assertFalse($all->isGroupEnabled('sql'));
        self::assertTrue($all->isGroupEnabled('events'));
        self::assertTrue($all->isGroupEnabled('http'));
        self::assertTrue($this->config(['MAGENTO_OTEL_TRACING_GROUPS' => 'all'])->isGroupEnabled('entities'));
        self::assertFalse($this->config(['MAGENTO_OTEL_TRACING_GROUPS' => '-eav'])->isGroupEnabled('entities'));
    }

    public function testDisabledByEnvOrMissingRuntime(): void
    {
        self::assertTrue($this->config()->isEnabled());
        self::assertFalse($this->config(['MAGENTO_OTEL_ENABLED' => 'false'])->isEnabled());
        self::assertFalse((new Config(new Environment([]), false))->isEnabled());
    }

    public function testExcludedPathsAreConfigurable(): void
    {
        $default = $this->config();
        self::assertTrue($default->isPathExcluded('/health_check.php'));
        self::assertTrue($default->isPathExcluded('/static/version1/x.js'));
        self::assertFalse($default->isPathExcluded('/catalog/product/view'));

        $custom = $this->config(['MAGENTO_OTEL_EXCLUDED_PATHS' => '/ping, /status']);
        self::assertTrue($custom->isPathExcluded('/ping'));
        self::assertFalse($custom->isPathExcluded('/static/x.js'));
    }

    public function testSpanBudgetCapsPerUnitAndResets(): void
    {
        $budget = new SpanBudget(3);

        self::assertTrue($budget->tryAcquire());
        self::assertTrue($budget->tryAcquire());
        self::assertTrue($budget->tryAcquire());
        self::assertFalse($budget->tryAcquire());
        self::assertFalse($budget->tryAcquire());
        self::assertSame(2, $budget->dropped());

        // A long-running worker starts the next job: the budget is fresh, the process total keeps counting.
        $budget->reset();
        self::assertTrue($budget->tryAcquire());
        self::assertSame(0, $budget->dropped());
        self::assertSame(2, $budget->droppedTotal());
    }

    public function testSpanCapFromEnv(): void
    {
        self::assertSame(500, $this->config()->spanCap());
        self::assertSame(20, $this->config(['MAGENTO_OTEL_SPAN_CAP' => '20'])->spanCap());
        self::assertSame(500, $this->config(['MAGENTO_OTEL_SPAN_CAP' => 'lots'])->spanCap());
    }
}
