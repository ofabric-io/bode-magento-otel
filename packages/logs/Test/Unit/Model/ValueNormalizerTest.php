<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Model\ExceptionNormalizer;
use Bode\Logs\Model\ValueNormalizer;
use Bode\Logs\Test\Unit\Support\Fixture;
use Bode\Logs\Test\Unit\Support\PlainEnum;
use Bode\Logs\Test\Unit\Support\Suit;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;

class ValueNormalizerTest extends TestCase
{
    /**
     * @param array<string,string> $env
     * @return ValueNormalizer
     */
    private function normalizer(array $env = []): ValueNormalizer
    {
        $config = Fixture::config($env);

        return new ValueNormalizer($config, new ExceptionNormalizer($config));
    }

    public function testKeepsScalarsAsTheyAre(): void
    {
        $n = $this->normalizer();

        self::assertSame(['a' => 1, 'b' => 1.5, 'c' => true, 'd' => null, 'e' => 'x'], $n->normalize([
            'a' => 1, 'b' => 1.5, 'c' => true, 'd' => null, 'e' => 'x',
        ]));
    }

    public function testTurnsNonFiniteFloatsIntoStrings(): void
    {
        self::assertSame('INF', $this->normalizer()->normalize(INF));
        self::assertSame('NAN', $this->normalizer()->normalize(NAN));
    }

    public function testStopsAtTheConfiguredDepth(): void
    {
        $value = ['l1' => ['l2' => ['l3' => ['l4' => 'deep']]]];
        $out = $this->normalizer(['MAGENTO_OTEL_LOGS_CONTEXT_DEPTH' => '2'])->normalize($value);

        self::assertSame('[array(1), depth limit]', $out['l1']['l2']);
    }

    public function testCapsTheWidthOfArrays(): void
    {
        $out = $this->normalizer()->normalize(range(1, 500));

        self::assertCount(101, $out);
        self::assertSame('400 more items', $out['…']);
    }

    public function testCutsLongStringsAndSaysHowMuchWasDropped(): void
    {
        $out = $this->normalizer(['MAGENTO_OTEL_LOGS_MAX_VALUE_BYTES' => '100'])->normalize(str_repeat('a', 250));

        self::assertStringEndsWith('[150 bytes dropped]', $out);
        self::assertLessThan(150, strlen($out));
    }

    public function testNeverCutsInTheMiddleOfAMultiByteCharacter(): void
    {
        $out = $this->normalizer(['MAGENTO_OTEL_LOGS_MAX_VALUE_BYTES' => '101'])->normalize(str_repeat('é', 100));

        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testDescribesObjectsByTheirNaturalForm(): void
    {
        $n = $this->normalizer();
        $stringable = new class () implements Stringable {
            /**
             * @return string
             */
            public function __toString(): string
            {
                return 'as text';
            }
        };
        $serialisable = new class () implements JsonSerializable {
            /**
             * @return mixed
             */
            public function jsonSerialize(): mixed
            {
                return ['id' => 5];
            }
        };

        self::assertSame('H', $n->normalize(Suit::Hearts));
        self::assertSame('One', $n->normalize(PlainEnum::One));
        self::assertSame('as text', $n->normalize($stringable));
        self::assertSame(['id' => 5], $n->normalize($serialisable));
        self::assertSame(['class' => stdClass::class], $n->normalize(new stdClass()));
        self::assertSame(RuntimeException::class, $n->normalize(new RuntimeException('x'))['type']);
    }

    public function testSurvivesAnObjectThatContainsItself(): void
    {
        $loop = new class () implements JsonSerializable {
            /**
             * @var object|null
             */
            public ?object $self = null;

            /**
             * @return mixed
             */
            public function jsonSerialize(): mixed
            {
                return ['again' => $this->self];
            }
        };
        $loop->self = $loop;

        $out = $this->normalizer()->normalize($loop);

        self::assertStringStartsWith('[recursion: ', $out['again']);
    }

    public function testDescribesResourcesWithoutReadingThem(): void
    {
        self::assertSame('[resource (stream)]', $this->normalizer()->normalize(fopen('php://memory', 'r')));
    }
}
