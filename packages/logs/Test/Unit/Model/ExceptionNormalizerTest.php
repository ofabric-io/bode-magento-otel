<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Model\ExceptionNormalizer;
use Bode\Logs\Test\Unit\Support\Fixture;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ExceptionNormalizerTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return ExceptionNormalizer
     */
    private function normalizer(array $env = []): ExceptionNormalizer
    {
        return new ExceptionNormalizer(Fixture::config($env));
    }

    /**
     * Throws from a few frames deep with arguments that must never be logged.
     *
     * @param string $secret
     * @param int $depth
     * @return Throwable
     */
    private function failing(string $secret, int $depth = 3): Throwable
    {
        try {
            $this->descend($secret, $depth);
        } catch (Throwable $e) {
            return $e;
        }
        throw new LogicException('unreachable');
    }

    /**
     * @param string $secret
     * @param int $depth
     * @return void
     */
    private function descend(string $secret, int $depth): void
    {
        if ($depth === 0) {
            throw new RuntimeException('deep failure');
        }
        $this->descend($secret, $depth - 1);
    }

    public function testDescribesTheExceptionAndItsLocation(): void
    {
        $e = new RuntimeException('Nope', 7);
        $attributes = $this->normalizer()->attributes($e);

        self::assertSame(RuntimeException::class, $attributes['exception.type']);
        self::assertSame('Nope', $attributes['exception.message']);
        self::assertSame(7, $attributes['exception.code']);
        self::assertSame(__FILE__, $attributes['exception.file']);
        self::assertSame($e->getLine(), $attributes['exception.line']);
    }

    public function testLeavesTheCodeOutWhenItIsZero(): void
    {
        self::assertArrayNotHasKey('exception.code', $this->normalizer()->attributes(new RuntimeException('x')));
    }

    public function testFramesCarryNoArguments(): void
    {
        $attributes = $this->normalizer()->attributes($this->failing('card 4111111111111111'));
        $encoded = json_encode($attributes);

        self::assertStringNotContainsString('4111111111111111', (string) $encoded);
        self::assertNotEmpty($attributes['exception.frames']);
        foreach ($attributes['exception.frames'] as $frame) {
            self::assertArrayNotHasKey('args', $frame);
            self::assertArrayHasKey('function', $frame);
        }
    }

    public function testCapsTheFramesAndSaysHowManyWereDropped(): void
    {
        $e = $this->failing('x', 10);
        $attributes = $this->normalizer(['MAGENTO_OTEL_LOGS_STACK_FRAMES' => '3'])->attributes($e);

        self::assertCount(3, $attributes['exception.frames']);
        self::assertSame(count($e->getTrace()) - 3, $attributes['exception.frames_dropped']);
        self::assertStringContainsString('more frames', $attributes['exception.stacktrace']);
    }

    public function testStacktraceTextHasTheFamiliarShape(): void
    {
        $text = $this->normalizer()->attributes($this->failing('x'))['exception.stacktrace'];

        self::assertStringStartsWith('RuntimeException: deep failure in ' . __FILE__, $text);
        self::assertMatchesRegularExpression('/^#0 .+descend\(\)$/m', $text);
        self::assertStringEndsWith('{main}', $text);
    }

    public function testFollowsThePreviousChainNearestFirstAndCapsIt(): void
    {
        $e = new RuntimeException('outer', 0, new LogicException('middle', 0, new RuntimeException('root')));

        $chain = $this->normalizer()->attributes($e)['exception.cause'];
        self::assertSame(['middle', 'root'], array_column($chain, 'message'));

        $capped = $this->normalizer(['MAGENTO_OTEL_LOGS_EXCEPTION_DEPTH' => '1'])->attributes($e)['exception.cause'];
        self::assertCount(1, $capped);
    }

    public function testFingerprintGroupsTheSameFailureWhateverWrapsIt(): void
    {
        $normalizer = $this->normalizer();
        $root = static fn (): RuntimeException => new RuntimeException('root');
        $one = $root();
        $wrappedA = new LogicException('first wrapper', 0, $one);
        $wrappedB = new RuntimeException('different wrapper', 5, $one);

        self::assertSame($normalizer->fingerprint($wrappedA), $normalizer->fingerprint($wrappedB));
        self::assertNotSame(
            $normalizer->fingerprint($one),
            $normalizer->fingerprint(new RuntimeException('thrown elsewhere'))
        );
    }

    public function testCutsHugeMessagesAndRepairsInvalidUtf8(): void
    {
        $attributes = $this->normalizer()->attributes(new RuntimeException(str_repeat('é', 5000) . "\xC3\x28"));

        self::assertLessThan(5000, strlen($attributes['exception.message']));
        self::assertTrue(mb_check_encoding($attributes['exception.message'], 'UTF-8'));
    }

    public function testSummaryIsShortAndMentionsThePrevious(): void
    {
        $summary = $this->normalizer()->summary(new RuntimeException('a', 0, new LogicException('b')));

        self::assertSame(RuntimeException::class, $summary['type']);
        self::assertSame(LogicException::class . ': b', $summary['previous']);
        self::assertArrayNotHasKey('frames', $summary);
    }
}
