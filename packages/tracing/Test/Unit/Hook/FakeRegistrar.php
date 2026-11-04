<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\HookRegistrarInterface;
use Closure;
use Throwable;

/**
 * Records registrations and lets tests invoke the callbacks.
 */
class FakeRegistrar implements HookRegistrarInterface
{
    /**
     * @var array<string,array{pre:?Closure,post:?Closure}>
     */
    public array $hooks = [];

    /**
     * @inheritdoc
     */
    public function register(string $class, string $method, ?Closure $pre, ?Closure $post): void
    {
        $this->hooks["$class::$method"] = ['pre' => $pre, 'post' => $post];
    }

    /**
     * Runs one pre/post pair.
     *
     * @param string $class
     * @param string $method
     * @param Throwable|null $exception
     * @return void
     */
    public function call(string $class, string $method, ?Throwable $exception = null): void
    {
        $hook = $this->hooks["$class::$method"];
        ($hook['pre'])(null, []);
        ($hook['post'])(null, [], null, $exception);
    }

    /**
     * Runs an outer hook, then $times inner hooks inside it, then closes the outer one.
     *
     * @param string $outer
     * @param string $inner
     * @param int $times
     * @return void
     */
    public function callNested(string $outer, string $inner, int $times): void
    {
        ($this->hooks[$outer]['pre'])(null, []);
        for ($i = 0; $i < $times; $i++) {
            ($this->hooks[$inner]['pre'])(null, []);
            ($this->hooks[$inner]['post'])(null, [], null, null);
        }
        ($this->hooks[$outer]['post'])(null, [], null, null);
    }
}
