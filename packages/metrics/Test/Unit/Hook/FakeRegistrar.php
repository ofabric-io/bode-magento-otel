<?php

declare(strict_types=1);

namespace Bode\Metrics\Test\Unit\Hook;

use Bode\Tracing\Hook\HookRegistrarInterface;
use Closure;
use Throwable;

/**
 * Records registrations and lets tests invoke the callbacks, the same shape as Bode_Tracing's own fake.
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
     * Runs the post callback of one registered hook.
     *
     * @param string $hook
     * @param array<int,mixed> $params
     * @param mixed $returnValue
     * @param Throwable|null $exception
     * @return void
     */
    public function post(
        string $hook,
        array $params = [],
        mixed $returnValue = null,
        ?Throwable $exception = null
    ): void {
        $post = $this->hooks[$hook]['post'] ?? null;
        if ($post !== null) {
            $post(null, $params, $returnValue, $exception);
        }
    }

    /**
     * Runs the pre callback of one registered hook.
     *
     * @param string $hook
     * @param array<int,mixed> $params
     * @return void
     */
    public function pre(string $hook, array $params = []): void
    {
        $pre = $this->hooks[$hook]['pre'] ?? null;
        if ($pre !== null) {
            $pre(null, $params);
        }
    }
}
