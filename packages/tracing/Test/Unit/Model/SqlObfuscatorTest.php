<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Model;

use Bode\Tracing\Model\SqlObfuscator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlObfuscatorTest extends TestCase
{
    /**
     * @var SqlObfuscator
     */
    private SqlObfuscator $sql;

    protected function setUp(): void
    {
        $this->sql = new SqlObfuscator();
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function literals(): array
    {
        return [
            'string literal' => [
                "SELECT * FROM customer_entity WHERE email = 'jane@example.com'",
                'SELECT * FROM customer_entity WHERE email = ?',
            ],
            'escaped quote' => [
                "UPDATE t SET note = 'it\\'s secret' WHERE id = 5",
                'UPDATE t SET note = ? WHERE id = ?',
            ],
            'doubled quote' => [
                "INSERT INTO t (a) VALUES ('o''brien')",
                'INSERT INTO t (a) VALUES (?)',
            ],
            'double quoted' => ['SELECT "secret"', 'SELECT ?'],
            'numbers' => [
                'SELECT * FROM t WHERE id = 42 AND price > 9.99',
                'SELECT * FROM t WHERE id = ? AND price > ?',
            ],
            'digits in identifiers kept' => [
                'SELECT col1 FROM `table_2` WHERE x = 1',
                'SELECT col1 FROM `table_2` WHERE x = ?',
            ],
            'hex' => ['SELECT 0xDEADBEEF', 'SELECT ?'],
            'named binds' => ['SELECT * FROM t WHERE a = :a AND b = :b0', 'SELECT * FROM t WHERE a = ? AND b = ?'],
            'IN list collapses' => ['SELECT * FROM t WHERE id IN (1, 2, 3, 4)', 'SELECT * FROM t WHERE id IN (?)'],
            'comments dropped' => [
                "SELECT /* token=abc */ 1 -- password 'x'\nFROM t",
                'SELECT ? FROM t',
            ],
        ];
    }

    #[DataProvider('literals')]
    public function testLiteralsAreRemoved(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sql->obfuscate($input));
    }

    public function testOutputIsCapped(): void
    {
        $out = $this->sql->obfuscate('SELECT ' . str_repeat('a_column, ', 200) . 'z FROM t');

        self::assertLessThanOrEqual(SqlObfuscator::MAX_LENGTH + 3, strlen($out));
    }

    public function testOperation(): void
    {
        self::assertSame('SELECT', $this->sql->operation("  /* x */ select 1"));
        self::assertSame('INSERT', $this->sql->operation('INSERT INTO t VALUES (1)'));
        self::assertSame('SELECT', $this->sql->operation('(SELECT 1) UNION (SELECT 2)'));
        self::assertSame('OTHER', $this->sql->operation('frobnicate everything'));
        self::assertSame('OTHER', $this->sql->operation(''));
    }

    public function testTableIsBoundedAndSchemaPrefixRemoved(): void
    {
        self::assertSame('sales_order', $this->sql->table('SELECT * FROM `sales_order` WHERE 1'));
        self::assertSame('catalog_product_entity', $this->sql->table('UPDATE shop.catalog_product_entity SET a=1'));
        self::assertSame('', $this->sql->table('SELECT 1'));
        self::assertLessThanOrEqual(
            SqlObfuscator::MAX_TABLE,
            strlen($this->sql->table('SELECT * FROM ' . str_repeat('x', 300)))
        );
    }
}
