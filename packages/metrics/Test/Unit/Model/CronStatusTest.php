<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Model;

use Bode\Metrics\Model\CronStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CronStatusTest extends TestCase
{
    /**
     * @return array<string,array{string,bool,string}>
     */
    public static function cases(): array
    {
        return [
            'success' => ['success', false, 'success'],
            'error row' => ['error', false, 'error'],
            'exception wins' => ['running', true, 'error'],
            'missed' => ['missed', false, 'missed'],
            'still running after return' => ['running', false, 'success'],
            'empty' => ['', false, 'success'],
        ];
    }

    #[DataProvider('cases')]
    public function testMapping(string $status, bool $threw, string $expected): void
    {
        self::assertSame($expected, (new CronStatus())->fromRun($status, $threw));
    }
}
