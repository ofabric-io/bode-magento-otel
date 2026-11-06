<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

/**
 * Stand-in for a loaded collection.
 */
class FakeCollection
{
    /**
     * @var callable|null runs on every count(): Magento's count() calls load(), which is hooked
     */
    public mixed $onCount = null;

    /**
     * @param string $table
     * @param int $rows
     */
    public function __construct(private readonly string $table, private readonly int $rows)
    {
    }

    /**
     * Main table.
     *
     * @return string
     */
    public function getMainTable(): string
    {
        return $this->table;
    }

    /**
     * Rows loaded.
     *
     * @return int
     */
    public function count(): int
    {
        if ($this->onCount !== null) {
            ($this->onCount)();
        }

        return $this->rows;
    }
}
