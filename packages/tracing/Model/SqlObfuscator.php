<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

/**
 * Makes SQL safe to record: literals and bind values are replaced by "?", comments are dropped, the text is capped.
 * Also extracts the operation (SELECT, INSERT, ...) and one bounded table name for span names and metrics.
 */
class SqlObfuscator
{
    public const MAX_LENGTH = 512;
    public const MAX_TABLE = 64;
    private const OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'SHOW', 'SET', 'CREATE', 'ALTER',
        'DROP', 'TRUNCATE', 'BEGIN', 'COMMIT', 'ROLLBACK', 'START', 'CALL', 'DESCRIBE', 'EXPLAIN'];

    /**
     * Removes literals, binds and comments from SQL and caps its length.
     *
     * @param string $sql
     * @return string
     */
    public function obfuscate(string $sql): string
    {
        $sql = (string) preg_replace('~/\*.*?\*/~s', ' ', $sql);
        $sql = (string) preg_replace('/(--|#)[^\n]*/', ' ', $sql);
        // Quoted strings, honouring backslash and doubled-quote escapes. Backtick identifiers are kept.
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $sql = (string) preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '?', $sql);
        // Hex and numeric literals, but not digits inside identifiers (col1, `table_2`).
        $sql = (string) preg_replace('/\b0x[0-9a-fA-F]+\b/', '?', $sql);
        $sql = (string) preg_replace('/(?<![\w`.])-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?![\w`])/', '?', $sql);
        // Named and positional binds are values too.
        $sql = (string) preg_replace('/:\w+/', '?', $sql);
        // IN (?, ?, ?) collapses so the text does not vary with list length.
        $sql = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql);
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($sql) > self::MAX_LENGTH ? substr($sql, 0, self::MAX_LENGTH) . '...' : $sql;
    }

    /**
     * Upper-case SQL verb, or OTHER.
     *
     * @param string $sql
     * @return string upper-case verb, or OTHER
     */
    public function operation(string $sql): string
    {
        $stripped = ltrim((string) preg_replace('~^(\s|/\*.*?\*/)+~s', '', $sql), " \t\n\r(");
        if (preg_match('/^([A-Za-z]+)/', $stripped, $m) !== 1) {
            return 'OTHER';
        }
        $verb = strtoupper($m[1]);

        return in_array($verb, self::OPERATIONS, true) ? $verb : 'OTHER';
    }

    /**
     * First table after FROM / INTO / UPDATE / JOIN, bounded, or empty when none is found.
     *
     * @param string $sql
     * @return string
     */
    public function table(string $sql): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+`?([A-Za-z0-9_$.]+)`?/i', $sql, $m) !== 1) {
            return '';
        }

        return substr(ltrim(strrchr('.' . $m[1], '.') ?: $m[1], '.'), 0, self::MAX_TABLE);
    }
}
