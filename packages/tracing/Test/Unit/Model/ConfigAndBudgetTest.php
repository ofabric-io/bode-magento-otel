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
        foreach (['events', 'sql', 'eav', 'redis', 'business'] as $group) {
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

    public function testNoisyGroupCannotStarveOthers(): void
    {
        $budget = new SpanBudget(10, ['entities' => 2]);

        self::assertTrue($budget->tryAcquire('entities'));
        self::assertTrue($budget->tryAcquire('entities'));
        self::assertFalse($budget->tryAcquire('entities'));
        self::assertTrue($budget->tryAcquire('sql'));
        self::assertSame(2, $budget->used() - 1);
        self::assertSame(
            ['bode.magento.spans.dropped' => 1, 'bode.magento.spans.dropped.entities' => 1],
            $budget->summary()
        );
    }

    public function testGlobalCapStillWinsOverGroupCap(): void
    {
        $budget = new SpanBudget(2, ['sql' => 5]);

        self::assertTrue($budget->tryAcquire('sql'));
        self::assertTrue($budget->tryAcquire('events'));
        self::assertFalse($budget->tryAcquire('sql'));
        // Unconfigured group: no per-group attribute (bounded output), global drop only.
        self::assertSame(
            ['bode.magento.spans.dropped' => 1, 'bode.magento.spans.dropped.sql' => 1],
            $budget->summary()
        );
        self::assertFalse($budget->tryAcquire('events'));
        self::assertArrayNotHasKey('bode.magento.spans.dropped.events', $budget->summary());
    }

    public function testCollapseAfterThresholdPerParentAndKey(): void
    {
        $budget = new SpanBudget(100, [], 2);

        self::assertTrue($budget->tryAcquire('sql', '1|A::b'));
        self::assertTrue($budget->tryAcquire('sql', '1|A::b'));
        self::assertFalse($budget->tryAcquire('sql', '1|A::b'));
        self::assertTrue($budget->tryAcquire('sql', '2|A::b'));
        self::assertTrue($budget->tryAcquire('sql', '1|C::d'));
        self::assertSame(1, $budget->collapsed());
        self::assertSame(0, $budget->dropped());
        self::assertSame(4, $budget->used());
        self::assertSame(['bode.magento.spans.collapsed' => 1], $budget->summary());

        $budget->reset();
        self::assertSame(0, $budget->collapsed());
        self::assertTrue($budget->tryAcquire('sql', '1|A::b'));
    }

    public function testCollapseOffByDefault(): void
    {
        $budget = new SpanBudget(100);
        for ($i = 0; $i < 20; $i++) {
            self::assertTrue($budget->tryAcquire('sql', '1|A::b'));
        }
    }

    public function testGroupCapsAndCollapseParsing(): void
    {
        $config = $this->config([
            'MAGENTO_OTEL_SPAN_CAP_GROUPS' => 'entities:150, sql:200,bad,x:-1,y:abc,z:0,a:1:2,:5',
            'MAGENTO_OTEL_SPAN_COLLAPSE_AFTER' => '5',
        ]);
        self::assertSame(['entities' => 150, 'sql' => 200], $config->spanGroupCaps());
        self::assertSame(5, $config->spanCollapseAfter());
        self::assertSame([], $this->config()->spanGroupCaps());
        self::assertSame(0, $this->config()->spanCollapseAfter());
        self::assertSame(0, $this->config(['MAGENTO_OTEL_SPAN_COLLAPSE_AFTER' => '-3'])->spanCollapseAfter());
        self::assertSame(0, $this->config(['MAGENTO_OTEL_SPAN_COLLAPSE_AFTER' => 'x'])->spanCollapseAfter());
    }
}
