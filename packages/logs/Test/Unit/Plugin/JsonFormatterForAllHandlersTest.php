<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Plugin;

use Bode\Logs\Plugin\JsonFormatterForAllHandlers;
use Bode\Logs\Test\Unit\Support\Fixture;
use Monolog\Formatter\LineFormatter;
use PHPUnit\Framework\TestCase;

class JsonFormatterForAllHandlersTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return JsonFormatterForAllHandlers
     */
    private function plugin(array $env = []): JsonFormatterForAllHandlers
    {
        return new JsonFormatterForAllHandlers(Fixture::config($env), Fixture::formatter($env));
    }

    public function testHandsOutTheJsonFormatterInsteadOfMagentosLineFormatter(): void
    {
        $plugin = $this->plugin();
        $result = $plugin->afterGetFormatter(new \stdClass(), new LineFormatter());

        self::assertSame(Fixture::formatter()::class, $result::class);
    }

    public function testKeepsMagentosFormatterWhenTheModuleIsDisabled(): void
    {
        $own = new LineFormatter();

        self::assertSame(
            $own,
            $this->plugin(['MAGENTO_OTEL_LOGS_ENABLED' => 'false'])->afterGetFormatter(new \stdClass(), $own)
        );
    }

    public function testFileHandlersKeepHandlingByDefault(): void
    {
        $called = 0;
        $proceed = static function ($record) use (&$called): bool {
            $called++;

            return true;
        };

        self::assertTrue($this->plugin()->aroundIsHandling(new \stdClass(), $proceed, ['level' => 200]));
        self::assertSame(1, $called);
    }

    public function testFileHandlersDeclineWhenOnlyStreamsAreWanted(): void
    {
        $called = 0;
        $proceed = static function ($record) use (&$called): bool {
            $called++;

            return true;
        };

        $plugin = $this->plugin(['MAGENTO_OTEL_LOGS_OUTPUT' => 'stdout']);

        self::assertFalse($plugin->aroundIsHandling(new \stdClass(), $proceed, ['level' => 200]));
        self::assertSame(0, $called, 'the file handler is not even asked');
    }

    public function testFileHandlersKeepHandlingWhenFilesAndStreamsAreBothWanted(): void
    {
        $plugin = $this->plugin(['MAGENTO_OTEL_LOGS_OUTPUT' => 'file,stdout']);

        self::assertTrue($plugin->aroundIsHandling(new \stdClass(), static fn ($r): bool => true, []));
    }

    public function testADisabledModuleNeverSilencesTheFiles(): void
    {
        $plugin = $this->plugin(['MAGENTO_OTEL_LOGS_ENABLED' => 'false', 'MAGENTO_OTEL_LOGS_OUTPUT' => 'stdout']);

        self::assertTrue($plugin->aroundIsHandling(new \stdClass(), static fn ($r): bool => true, []));
    }
}
