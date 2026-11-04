<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Bode\Tracing\Api\InstrumentationGuardInterface;
use Bode\Tracing\Model\ErrorCounter;
use Bode\Tracing\Model\SpanBudget;
use Closure;
use Throwable;

/**
 * The one place that turns "start a span before, finish it after" into registered hooks. It enforces, for every
 * instrumentation alike: group switches, the per-unit span budget, balanced pre/post handling, and the rule that a
 * failure inside instrumentation is counted and swallowed, never propagated.
 */
class Instrumenter
{
    /**
     * @param HookRegistrarInterface $registrar
     * @param InstrumentationGuardInterface $guard
     * @param SpanBudget $budget
     * @param SpanStack $stack
     * @param ErrorCounter $errors
     */
    public function __construct(
        private readonly HookRegistrarInterface $registrar,
        private readonly InstrumentationGuardInterface $guard,
        private readonly SpanBudget $budget,
        private readonly SpanStack $stack,
        private readonly ErrorCounter $errors
    ) {
    }

    /**
     * Hooks class::method with a span.
     *
     * @param string $group feature group that switches this hook on
     * @param string $class
     * @param string $method
     * @param Closure $begin function (?object $subject, array $params): ?SpanHandleInterface; null = no span
     * @param Closure|null $finish function (SpanHandleInterface $span, ?object $subject, array $params,
     *                             mixed $returnValue, ?Throwable $e): void; adds attributes before the span ends
     * @param bool $rootOfUnit true when this span is the root of a new unit of work (request, job, message): the
     *                         span budget is reset first
     * @param Closure|null $mutate function (SpanHandleInterface $span, ?object $subject, array $params): ?array;
     *                             returns changed call parameters (position => value), used to propagate trace context
     * @return void
     */
    public function span(
        string $group,
        string $class,
        string $method,
        Closure $begin,
        ?Closure $finish = null,
        bool $rootOfUnit = false,
        ?Closure $mutate = null
    ): void {
        if (!$this->guard->isEnabled() || !$this->guard->isGroupEnabled($group)) {
            return;
        }

        $this->registrar->register(
            $class,
            $method,
            function (
                ?object $subject,
                array $params
            ) use (
                $group,
                $begin,
                $rootOfUnit,
                $mutate
            ): ?array {
                $handle = null;
                $changed = null;
                try {
                    if ($rootOfUnit) {
                        $this->budget->reset();
                    } elseif (!$this->budget->tryAcquire()) {
                        $this->stack->push(null);

                        return null;
                    }
                    $handle = $begin($subject, $params);
                    if ($handle !== null && $mutate !== null) {
                        $changed = $mutate($handle, $subject, $params);
                    }
                } catch (Throwable $e) {
                    $this->errors->record($group, $e);
                }
                $this->stack->push($handle);

                return $changed;
            },
            function (
                ?object $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception
            ) use (
                $group,
                $finish
            ): void {
                $handle = $this->stack->pop();
                if ($handle === null) {
                    return;
                }
                try {
                    if ($finish !== null) {
                        $finish($handle, $subject, $params, $returnValue, $exception);
                    }
                    if ($exception !== null) {
                        $handle->recordError($exception);
                    }
                } catch (Throwable $e) {
                    $this->errors->record($group, $e);
                } finally {
                    try {
                        $handle->end();
                    } catch (Throwable $e) {
                        $this->errors->record($group, $e);
                    }
                }
            }
        );
    }

    /**
     * Hooks class::method without a span of its own.
     *
     * After the call, $fn may add attributes to the span that is current (or to the root span of the unit of work).
     *
     * @param string $group
     * @param string $class
     * @param string $method
     * @param Closure $fn function (SpanHandleInterface $span, ?object $subject, array $params, mixed $returnValue,
     *                    ?Throwable $e): void
     * @param bool $onRoot true enriches the root span instead of the innermost one
     * @return void
     */
    public function after(string $group, string $class, string $method, Closure $fn, bool $onRoot = false): void
    {
        if (!$this->guard->isEnabled() || !$this->guard->isGroupEnabled($group)) {
            return;
        }

        $this->registrar->register(
            $class,
            $method,
            null,
            function (
                ?object $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception
            ) use (
                $group,
                $fn,
                $onRoot
            ): void {
                $span = $onRoot ? $this->stack->bottom() : $this->stack->top();
                if ($span === null) {
                    return;
                }
                try {
                    $fn($span, $subject, $params, $returnValue, $exception);
                } catch (Throwable $e) {
                    $this->errors->record($group, $e);
                }
            }
        );
    }

    /**
     * Innermost open span, for enrichment from plugins and observers.
     *
     * @return SpanHandleInterface|null
     */
    public function current(): ?SpanHandleInterface
    {
        return $this->stack->top();
    }
}
