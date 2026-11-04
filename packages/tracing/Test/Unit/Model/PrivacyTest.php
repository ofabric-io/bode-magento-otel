<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Model;

use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\Privacy;
use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return Privacy
     */
    private function privacy(array $env = []): Privacy
    {
        return new Privacy(new Environment($env));
    }

    public function testIpDefaultsToTruncate(): void
    {
        $p = $this->privacy();

        self::assertSame('203.0.113.0', $p->clientAddress('203.0.113.77'));
        self::assertSame('2001:db8:1234::', $p->clientAddress('2001:db8:1234:5678:9abc:def0:1234:5678'));
    }

    public function testIpModes(): void
    {
        $full = $this->privacy(['MAGENTO_OTEL_IP_MODE' => 'full']);
        self::assertSame('203.0.113.77', $full->clientAddress('203.0.113.77'));
        self::assertNull($this->privacy(['MAGENTO_OTEL_IP_MODE' => 'off'])->clientAddress('203.0.113.77'));
    }

    public function testIpHashNeedsASecret(): void
    {
        self::assertNull($this->privacy(['MAGENTO_OTEL_IP_MODE' => 'hash'])->clientAddress('203.0.113.77'));

        $env = ['MAGENTO_OTEL_IP_MODE' => 'hash', 'MAGENTO_OTEL_HASH_SECRET' => 's3cret'];
        $a = $this->privacy($env)->clientAddress('203.0.113.77');
        self::assertNotNull($a);
        self::assertNotSame('203.0.113.77', $a);
        self::assertSame($a, $this->privacy($env)->clientAddress('203.0.113.77'));
        self::assertNotSame(
            $a,
            $this->privacy(['MAGENTO_OTEL_HASH_SECRET' => 'other'] + $env)->clientAddress('203.0.113.77')
        );
    }

    public function testInvalidAddressIsNotRecorded(): void
    {
        self::assertNull($this->privacy(['MAGENTO_OTEL_IP_MODE' => 'full'])->clientAddress('not-an-ip'));
    }

    public function testUnknownModeFallsBackToTheConservativeDefault(): void
    {
        $yolo = $this->privacy(['MAGENTO_OTEL_IP_MODE' => 'yolo']);
        self::assertSame('203.0.113.0', $yolo->clientAddress('203.0.113.9'));
    }

    public function testUserModes(): void
    {
        self::assertSame('42', $this->privacy()->userId(42));
        self::assertNull($this->privacy()->userId(0));
        self::assertNull($this->privacy(['MAGENTO_OTEL_USER_MODE' => 'off'])->userId(42));

        $hashed = $this->privacy(['MAGENTO_OTEL_USER_MODE' => 'hash', 'MAGENTO_OTEL_HASH_SECRET' => 'k'])->userId(42);
        self::assertNotNull($hashed);
        self::assertNotSame('42', $hashed);
    }

    public function testSessionIsNeverReturnedRaw(): void
    {
        self::assertNull($this->privacy()->sessionHash('abc123'));

        $h = $this->privacy(['MAGENTO_OTEL_HASH_SECRET' => 'k'])->sessionHash('abc123');
        self::assertNotNull($h);
        self::assertStringNotContainsString('abc123', $h);
        self::assertNull($this->privacy(['MAGENTO_OTEL_HASH_SECRET' => 'k'])->sessionHash(''));
    }

    public function testUserAgentIsTruncated(): void
    {
        self::assertSame(120, strlen($this->privacy()->userAgent(str_repeat('a', 500))));
        self::assertSame('curl/8', $this->privacy()->userAgent('curl/8'));
    }
}
