<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Model\ResourceAttributes;
use Bode\Tracing\Model\Environment;
use PHPUnit\Framework\TestCase;

class ResourceAttributesTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    private function resource(array $env): array
    {
        return (new ResourceAttributes(new Environment($env)))->all();
    }

    public function testDefaultsToTheMagentoServiceWithProcessFacts(): void
    {
        $resource = $this->resource([]);

        self::assertSame('magento', $resource['service.name']);
        self::assertSame('php', $resource['process.runtime.name']);
        self::assertSame(PHP_VERSION, $resource['process.runtime.version']);
        self::assertIsInt($resource['process.pid']);
        self::assertNotSame('', $resource['host.name']);
    }

    public function testReadsTheStandardOpenTelemetryVariables(): void
    {
        $resource = $this->resource([
            'OTEL_SERVICE_NAME' => 'shop-php',
            'OTEL_RESOURCE_ATTRIBUTES' => 'service.version=1.4.2,deployment.environment.name=production,'
                . 'team=pay%20ments',
        ]);

        self::assertSame('shop-php', $resource['service.name']);
        self::assertSame('1.4.2', $resource['service.version']);
        self::assertSame('production', $resource['deployment.environment.name']);
        self::assertSame('pay ments', $resource['team']);
    }

    public function testTheServiceNameVariableBeatsTheAttributeList(): void
    {
        $resource = $this->resource([
            'OTEL_SERVICE_NAME' => 'from-name',
            'OTEL_RESOURCE_ATTRIBUTES' => 'service.name=from-list',
        ]);

        self::assertSame('from-name', $resource['service.name']);
    }

    public function testAcceptsTheAttributeListAloneForTheServiceName(): void
    {
        self::assertSame(
            'from-list',
            $this->resource(['OTEL_RESOURCE_ATTRIBUTES' => 'service.name=from-list'])['service.name']
        );
    }

    public function testNormalisesTheOlderEnvironmentAttributeName(): void
    {
        $resource = $this->resource(['OTEL_RESOURCE_ATTRIBUTES' => 'deployment.environment=staging']);

        self::assertSame('staging', $resource['deployment.environment.name']);
        self::assertArrayNotHasKey('deployment.environment', $resource);
    }

    public function testIgnoresMalformedPairs(): void
    {
        $resource = $this->resource(['OTEL_RESOURCE_ATTRIBUTES' => 'novalue,=empty,ok=1']);

        self::assertSame('1', $resource['ok']);
        self::assertArrayNotHasKey('novalue', $resource);
    }
}
