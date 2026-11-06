<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Closure;
use Throwable;

/**
 * Registers hooks with the OpenTelemetry PHP extension. A no-op when the extension is not loaded.
 *
 * Every callback is skipped, pre and post alike so the span stack stays balanced, when the running function is declared
 * by a class Magento generated (see GeneratedWrapper): the real method behind it runs the same hook once more.
 */
class OtelHookRegistrar implements HookRegistrarInterface
{
    /**
     * @var GeneratedWrapper
     */
    private GeneratedWrapper $wrappers;

    /**
     * @param GeneratedWrapper|null $wrappers
     */
    public function __construct(?GeneratedWrapper $wrappers = null)
    {
        $this->wrappers = $wrappers ?? new GeneratedWrapper();
    }

    /**
     * @inheritdoc
     */
    public function register(string $class, string $method, ?Closure $pre, ?Closure $post): void
    {
        if (!function_exists('OpenTelemetry\Instrumentation\hook')) {
            return;
        }

        try {
            \OpenTelemetry\Instrumentation\hook($class, $method, $this->guardPre($pre), $this->guardPost($post));
        } catch (Throwable) {
            // A class that does not exist in this Magento version must never break the application.
            return;
        }
    }

    /**
     * The pre callback as the extension calls it: (subject, params, declaring class, ...).
     *
     * @param Closure|null $pre
     * @return Closure|null
     */
    public function guardPre(?Closure $pre): ?Closure
    {
        if ($pre === null) {
            return null;
        }

        return function (?object $subject, array $params, ?string $declaringClass = null) use ($pre): ?array {
            if ($this->wrappers->isWrapper($declaringClass)) {
                return null;
            }

            return $pre($subject, $params);
        };
    }

    /**
     * The post callback as the extension calls it: (subject, params, return value, exception, declaring class, ...).
     *
     * @param Closure|null $post
     * @return Closure|null
     */
    public function guardPost(?Closure $post): ?Closure
    {
        if ($post === null) {
            return null;
        }

        return function (
            ?object $subject,
            array $params,
            mixed $returnValue,
            ?Throwable $exception,
            ?string $declaringClass = null
        ) use ($post): void {
            if ($this->wrappers->isWrapper($declaringClass)) {
                return;
            }
            $post($subject, $params, $returnValue, $exception);
        };
    }
}
