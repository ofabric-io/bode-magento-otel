<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\OtelHookRegistrar;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OtelHookRegistrarGuardTest extends TestCase
{
    public function testWrapperFramesRunNeitherPreNorPostAndRealFramesRunBoth(): void
    {
        $calls = [];
        $registrar = new OtelHookRegistrar();
        $pre = $registrar->guardPre(static function (?object $s, array $p) use (&$calls): ?array {
            $calls[] = 'pre';

            return null;
        });
        $post = $registrar->guardPost(static function () use (&$calls): void {
            $calls[] = 'post';
        });

        $pre(null, [], 'Magento\Framework\DB\Adapter\Pdo\Mysql\Interceptor');
        $pre(null, [], 'Magento\Framework\DB\Adapter\Pdo\Mysql');
        $post(null, [], null, null, 'Magento\Framework\DB\Adapter\Pdo\Mysql');
        $post(null, [], null, null, 'Magento\Framework\DB\Adapter\Pdo\Mysql\Interceptor');

        self::assertSame(['pre', 'post'], $calls, 'one pair for the real frame, none for the wrapper');
    }

    public function testWithoutADeclaringClassTheCallbackRuns(): void
    {
        $ran = 0;
        $pre = (new OtelHookRegistrar())->guardPre(static function () use (&$ran): ?array {
            $ran++;

            return null;
        });

        $pre(null, []);

        self::assertSame(1, $ran);
    }

    public function testNullCallbacksStayNull(): void
    {
        $registrar = new OtelHookRegistrar();

        self::assertNull($registrar->guardPre(null));
        self::assertNull($registrar->guardPost(null));
    }

    public function testExceptionReachesThePostOfTheRealFrame(): void
    {
        $seen = null;
        $post = (new OtelHookRegistrar())->guardPost(static function ($s, $p, $r, $e) use (&$seen): void {
            $seen = $e;
        });
        $error = new RuntimeException('x');

        $post(null, [], null, $error, 'Magento\Framework\Event\Manager\Proxy');
        self::assertNull($seen);
        $post(null, [], null, $error, 'Magento\Framework\Event\Manager');
        self::assertSame($error, $seen);
    }
}
