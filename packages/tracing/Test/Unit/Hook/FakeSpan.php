<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\SpanHandleInterface;
use Throwable;

/**
 * Test double for a started span.
 */
class FakeSpan implements SpanHandleInterface
{
    /**
     * @var array<string,mixed>
     */
    public array $attributes = [];

    /**
     * @var Throwable|null
     */
    public ?Throwable $error = null;

    /**
     * @var bool
     */
    public bool $ended = false;

    /**
     * @inheritdoc
     */
    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * @inheritdoc
     */
    public function setName(string $name): void
    {
        $this->attributes['name'] = $name;
    }

    /**
     * @inheritdoc
     */
    public function addEvent(string $name, array $attributes = []): void
    {
        $this->attributes['event.' . $name] = $attributes;
    }

    /**
     * @inheritdoc
     */
    public function recordError(Throwable $exception): void
    {
        $this->error = $exception;
    }

    /**
     * @inheritdoc
     */
    public function markError(string $type): void
    {
        $this->attributes['error.type'] = $type;
    }

    /**
     * @inheritdoc
     */
    public function end(): void
    {
        $this->ended = true;
    }
}
