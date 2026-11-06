<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit\Hook;

use Bode\Tracing\Hook\HookRegistrarInterface;
use Bode\Tracing\Hook\OtelHookRegistrar;
use Closure;
use Throwable;

/**
 * Records registrations behind the same wrapper guard the real registrar installs, and replays what the extension does
 * for a call through a generated wrapper: the hook runs once per frame of the hierarchy, outermost first.
 */
class GuardedFakeRegistrar implements HookRegistrarInterface
{
    /**
     * @var array<string,array{pre:?Closure,post:?Closure}>
     */
    public array $hooks = [];

    /**
     * @var OtelHookRegistrar
     */
    private OtelHookRegistrar $guard;

    public function __construct()
    {
        $this->guard = new OtelHookRegistrar();
    }

    /**
     * @inheritdoc
     */
    public function register(string $class, string $method, ?Closure $pre, ?Closure $post): void
    {
        $this->hooks["$class::$method"] = [
            'pre' => $this->guard->guardPre($pre),
            'post' => $this->guard->guardPost($post),
        ];
    }

    /**
     * One logical call: every frame in $frames (declaring classes, outermost first) runs the hook around the next one.
     *
     * @param string $target hooked "Class::method"
     * @param string[] $frames declaring class of each frame, outermost first
     * @param object|null $subject
     * @param array<int,mixed> $params
     * @param callable|null $inside runs inside the innermost frame
     * @param Throwable|null $exception
     * @return void
     */
    public function callThrough(
        string $target,
        array $frames,
        ?object $subject,
        array $params,
        ?callable $inside = null,
        ?Throwable $exception = null
    ): void {
        $hook = $this->hooks[$target];
        foreach ($frames as $frame) {
            ($hook['pre'])($subject, $params, $frame);
        }
        if ($inside !== null) {
            $inside();
        }
        foreach (array_reverse($frames) as $frame) {
            ($hook['post'])($subject, $params, null, $exception, $frame);
        }
    }
}
