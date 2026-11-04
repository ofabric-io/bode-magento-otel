<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Model;

use Bode\Tracing\Model\ClientAddressResolver;
use Bode\Tracing\Model\Environment;
use PHPUnit\Framework\TestCase;

class ClientAddressResolverTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return ClientAddressResolver
     */
    private function resolver(array $env = []): ClientAddressResolver
    {
        return new ClientAddressResolver(new Environment($env));
    }

    public function testUsesTheAddressTheWebServerResolved(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];

        self::assertSame('198.51.100.7', $this->resolver()->resolve($server));
    }

    public function testForwardedHeaderIsIgnoredWithoutAnExplicitTrustedProxyList(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];

        self::assertSame(
            '198.51.100.7',
            $this->resolver(['MAGENTO_OTEL_FORWARDED_HEADER' => 'X-Forwarded-For'])->resolve($server)
        );
    }

    public function testForwardedHeaderFromAnUntrustedPeerIsIgnored(): void
    {
        $env = ['MAGENTO_OTEL_FORWARDED_HEADER' => 'X-Forwarded-For', 'MAGENTO_OTEL_TRUSTED_PROXIES' => '10.0.0.0/8'];
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];

        self::assertSame('198.51.100.7', $this->resolver($env)->resolve($server));
    }

    public function testForwardedHeaderFromATrustedProxyIsUsed(): void
    {
        $env = ['MAGENTO_OTEL_FORWARDED_HEADER' => 'X-Forwarded-For', 'MAGENTO_OTEL_TRUSTED_PROXIES' => '10.0.0.0/8'];
        $server = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];

        self::assertSame('203.0.113.9', $this->resolver($env)->resolve($server));
    }

    public function testSpoofedLeftmostEntriesAreSkipped(): void
    {
        $env = ['MAGENTO_OTEL_FORWARDED_HEADER' => 'X-Forwarded-For', 'MAGENTO_OTEL_TRUSTED_PROXIES' => '10.0.0.0/8'];
        $server = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.9, 10.9.9.9'];

        self::assertSame('203.0.113.9', $this->resolver($env)->resolve($server));
    }

    public function testGarbageInTheHeaderFallsBackToThePeer(): void
    {
        $env = ['MAGENTO_OTEL_FORWARDED_HEADER' => 'X-Forwarded-For', 'MAGENTO_OTEL_TRUSTED_PROXIES' => '10.1.2.3'];
        $server = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'];

        self::assertSame('10.1.2.3', $this->resolver($env)->resolve($server));
    }

    public function testCidrBoundaries(): void
    {
        $env = ['MAGENTO_OTEL_FORWARDED_HEADER' => 'X-Real-IP', 'MAGENTO_OTEL_TRUSTED_PROXIES' => '172.16.0.0/12'];
        $inside = ['REMOTE_ADDR' => '172.31.255.255', 'HTTP_X_REAL_IP' => '203.0.113.9'];
        $outside = ['REMOTE_ADDR' => '172.32.0.1', 'HTTP_X_REAL_IP' => '203.0.113.9'];

        self::assertSame('203.0.113.9', $this->resolver($env)->resolve($inside));
        self::assertSame('172.32.0.1', $this->resolver($env)->resolve($outside));
    }
}
