<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Instrumentation;

/**
 * Stand-in for a resource model: exposes only what the instrumentation may read.
 */
class FakeResource
{
    /**
     * @param string $table
     * @param string|null $typeCode EAV entity type code, null for a flat resource
     */
    public function __construct(private readonly string $table, private readonly ?string $typeCode = null)
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
     * EAV entity type, when there is one.
     *
     * @return object|null
     */
    public function getEntityType(): ?object
    {
        return $this->typeCode === null ? null : new FakeEntityType($this->typeCode);
    }
}
