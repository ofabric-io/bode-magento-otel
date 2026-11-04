<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Plugin;

use Bode\Logs\Model\RecordAssembler;
use Bode\Logs\Plugin\ThrowableAsMessage;
use Bode\Logs\Test\Unit\Support\Fixture;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ThrowableAsMessageTest extends TestCase
{
    public function testMovesAThrowableMessageIntoTheContextWithAOneLineMessage(): void
    {
        $e = new RuntimeException("Order failed\nsecond line");
        [$message, $context] = (new ThrowableAsMessage(Fixture::config()))->beforeCritical(new \stdClass(), $e);

        self::assertSame("RuntimeException: Order failed\nsecond line", $message);
        self::assertSame($e, $context[RecordAssembler::EXCEPTION_KEY]);
        self::assertStringNotContainsString('Stack trace', $message, 'the trace is no longer part of the message');
    }

    public function testLeavesTheExceptionKeyAloneSoMagentosRoutingStaysTheSame(): void
    {
        [, $context] = (new ThrowableAsMessage(Fixture::config()))
            ->beforeError(new \stdClass(), new RuntimeException('x'), ['order_id' => 5]);

        self::assertArrayNotHasKey('exception', $context, 'System would send it to exception.log otherwise');
        self::assertSame(5, $context['order_id']);
    }

    public function testLeavesStringMessagesAndTheirContextUntouched(): void
    {
        $plugin = new ThrowableAsMessage(Fixture::config());

        self::assertSame(['plain', ['a' => 1]], $plugin->beforeInfo(new \stdClass(), 'plain', ['a' => 1]));
    }

    public function testKeepsAnExceptionThatIsAlreadyInTheContext(): void
    {
        $already = new LogicException('first');
        [, $context] = (new ThrowableAsMessage(Fixture::config()))->beforeWarning(
            new \stdClass(),
            new RuntimeException('second'),
            [RecordAssembler::EXCEPTION_KEY => $already]
        );

        self::assertSame($already, $context[RecordAssembler::EXCEPTION_KEY]);
    }

    public function testLogKeepsTheLevelArgument(): void
    {
        $e = new RuntimeException('x');
        $plugin = new ThrowableAsMessage(Fixture::config());
        [$level, $message, $context] = $plugin->beforeLog(new \stdClass(), 'error', $e);

        self::assertSame('error', $level);
        self::assertSame('RuntimeException: x', $message);
        self::assertSame($e, $context[RecordAssembler::EXCEPTION_KEY]);
    }

    public function testEveryPsr3LevelIsCovered(): void
    {
        $plugin = new ThrowableAsMessage(Fixture::config());
        $e = new RuntimeException('x');

        foreach (['Emergency', 'Alert', 'Critical', 'Error', 'Warning', 'Notice', 'Info', 'Debug'] as $level) {
            [$message] = $plugin->{'before' . $level}(new \stdClass(), $e);
            self::assertSame('RuntimeException: x', $message, $level);
        }
    }

    public function testDoesNothingWhenTheModuleIsDisabled(): void
    {
        $e = new RuntimeException('x');
        $plugin = new ThrowableAsMessage(Fixture::config(['MAGENTO_OTEL_LOGS_ENABLED' => 'false']));

        self::assertSame([$e, []], $plugin->beforeCritical(new \stdClass(), $e));
    }
}
