<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\GeneratedWrapper;
use PHPUnit\Framework\TestCase;

class GeneratedWrapperTest extends TestCase
{
    public function testInterceptorAndProxyClassesAreWrappers(): void
    {
        $wrappers = new GeneratedWrapper();

        self::assertTrue($wrappers->isWrapper('Magento\Framework\DB\Adapter\Pdo\Mysql\Interceptor'));
        self::assertTrue($wrappers->isWrapper('Magento\Framework\Event\Manager\Proxy'));
    }

    public function testRealClassesAreNot(): void
    {
        $wrappers = new GeneratedWrapper();

        self::assertFalse($wrappers->isWrapper('Magento\Framework\DB\Adapter\Pdo\Mysql'));
        self::assertFalse($wrappers->isWrapper('Magento\Framework\Event\Manager'));
        self::assertFalse($wrappers->isWrapper('Vendor\Module\Proxyish'));
        self::assertFalse($wrappers->isWrapper('InterceptorFactory'));
    }

    public function testMissingDeclaringClassIsNotAWrapper(): void
    {
        $wrappers = new GeneratedWrapper();

        self::assertFalse($wrappers->isWrapper(null));
        self::assertFalse($wrappers->isWrapper(''));
    }
}
