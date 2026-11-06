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
     * @param bool $rootOfUnit true when this span is the root of a new unit of work (request, job, message,
     *                         command): the span budget is reset first. Every span that is not a root requires an open
     *                         unit root and is dropped (counted as orphaned) when there is none
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
                $class,
                $method,
                $begin,
                $rootOfUnit,
                $mutate
            ): ?array {
                $handle = null;
                $changed = null;
                $orphans = 0;
                try {
                    if ($rootOfUnit) {
                        $this->budget->reset();
                        $orphans = $this->stack->takePendingOrphans();
                    } elseif (!$this->stack->hasUnitRoot()) {
                        // One trace per unit of work: only a request, job, message or command may start a trace.
                        // Anything else without one would be a fragment, so it is dropped and counted.
                        $this->stack->recordOrphan();
                        $this->stack->push(null);

                        return null;
                    } elseif (!$this->budget->tryAcquire($group, $this->repeatKey($class, $method))) {
                        if ($group === 'entities') {
                            error_log('TMPDEBUG refused ' . $class . '::' . $method . ' used=' . $this->budget->used());
                        }
                        $this->stack->push(null);

                        return null;
                    }
                    $handle = $begin($subject, $params);
                    if ($group === 'entities') {
                        error_log('TMPDEBUG began ' . $class . '::' . $method . ' handle=' . ($handle === null ? 'null' : 'span') . ' used=' . $this->budget->used());
                    }
                    if ($handle === null && !$rootOfUnit) {
                        // The hook chose to start nothing (e.g. an entity span already open for this object): the
                        // permission it was given must not count against the budget.
                        $this->budget->release($group, $this->repeatKey($class, $method));
                    }
                    if ($handle !== null && $orphans > 0) {
                        $handle->setAttribute('bode.magento.spans.orphaned', $orphans);
                    }
                    if ($handle !== null && $mutate !== null) {
                        $changed = $mutate($handle, $subject, $params);
                    }
                } catch (Throwable $e) {
                    $this->errors->record($group, $e);
                }
                if ($rootOfUnit) {
                    // A root whose begin returned null (excluded path) is still a unit: it suppresses its children.
                    $this->stack->pushRoot($handle);
                } else {
                    $this->stack->push($handle);
                }

                return $changed;
            },
            function (
                ?object $subject,
                array $params,
                mixed $returnValue,
                ?Throwable $exception
            ) use (
                $group,
                $finish,
                $rootOfUnit
            ): void {
                if ($rootOfUnit) {
                    $this->endLeaked($group);
                }
                $handle = $this->stack->pop();
                if ($handle === null) {
                    return;
                }
                try {
                    if ($rootOfUnit) {
                        foreach ($this->budget->summary() as $name => $value) {
                            $handle->setAttribute($name, $value);
                        }
                    }
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
     * Ends spans left open inside a unit whose post hook never ran.
     *
     * A fatal error or a swallowed throw causes that; their scopes are detached and the stack is balanced when the
     * root ends.
     *
     * @param string $group
     * @return void
     */
    private function endLeaked(string $group): void
    {
        try {
            foreach ($this->stack->unwindToRoot() as $leaked) {
                try {
                    $leaked->end();
                } catch (Throwable $e) {
                    $this->errors->record($group, $e);
                }
            }
        } catch (Throwable $e) {
            $this->errors->record($group, $e);
        }
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

    /**
     * Identity of "same span under same parent" for repeat collapsing.
     *
     * @param string $class
     * @param string $method
     * @return string
     */
    private function repeatKey(string $class, string $method): string
    {
        return $this->stack->currentSerial() . '|' . $class . '::' . $method;
    }
}
