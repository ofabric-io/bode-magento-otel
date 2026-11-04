<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

/**
 * Caps the number of spans per unit of work (one web request, one cron job, one queue message) so a hot loop cannot
 * create thousands of spans. Unlike a process-wide counter it is reset at the start of every unit, so long-running
 * processes (cron:run, queue consumers) keep tracing. Spans refused are counted, never silent.
 */
class SpanBudget
{
    /**
     * @var int
     */
    private int $used = 0;
    /**
     * @var int
     */
    private int $dropped = 0;
    /**
     * @var int
     */
    private int $droppedTotal = 0;

    /**
     * @param int $cap
     */
    public function __construct(private int $cap = Config::DEFAULT_SPAN_CAP)
    {
    }

    /**
     * Start a new unit of work.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->used = 0;
        $this->dropped = 0;
    }

    /**
     * Changes the span cap.
     *
     * @param int $cap
     * @return void
     */
    public function setCap(int $cap): void
    {
        $this->cap = max(1, $cap);
    }

    /**
     * Ask for permission to start one more span.
     *
     * @return bool
     */
    public function tryAcquire(): bool
    {
        if ($this->used >= $this->cap) {
            $this->dropped++;
            $this->droppedTotal++;

            return false;
        }
        $this->used++;

        return true;
    }

    /**
     * Spans dropped in the current unit of work.
     *
     * @return int spans dropped in the current unit of work
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * Spans dropped since the process started.
     *
     * @return int spans dropped since the process started
     */
    public function droppedTotal(): int
    {
        return $this->droppedTotal;
    }

    /**
     * Spans started in the current unit of work.
     *
     * @return int
     */
    public function used(): int
    {
        return $this->used;
    }
}
