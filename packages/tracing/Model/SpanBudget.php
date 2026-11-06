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
     * @var int
     */
    private int $collapsed = 0;
    /**
     * @var array<string,int>
     */
    private array $groupUsed = [];
    /**
     * @var array<string,int>
     */
    private array $groupDropped = [];
    /**
     * @var array<string,int>
     */
    private array $siblings = [];

    /**
     * @param int $cap global cap per unit of work
     * @param array<string,int> $groupCaps group => cap; groups without an entry are bound by the global cap only
     * @param int $collapseAfter 0 = off; N > 0 refuses sibling spans (same parent, same key) after the Nth
     */
    public function __construct(
        private int $cap = Config::DEFAULT_SPAN_CAP,
        private readonly array $groupCaps = [],
        private readonly int $collapseAfter = 0
    ) {
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
        $this->collapsed = 0;
        $this->groupUsed = [];
        $this->groupDropped = [];
        $this->siblings = [];
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
     * Order: global cap, then group cap, then repeat collapsing. A refused span consumes nothing, so a noisy group
     * cannot starve the others and a collapsed repeat does not eat global budget.
     *
     * @param string $group feature group of the hook ("" = no group accounting)
     * @param string $repeatKey identifies "same span under the same parent" ("" = never collapse)
     * @return bool
     */
    public function tryAcquire(string $group = '', string $repeatKey = ''): bool
    {
        if ($this->used >= $this->cap) {
            return $this->refuse($group);
        }
        if (isset($this->groupCaps[$group]) && ($this->groupUsed[$group] ?? 0) >= $this->groupCaps[$group]) {
            return $this->refuse($group);
        }
        if ($this->collapseAfter > 0 && $repeatKey !== '') {
            $seen = $this->siblings[$repeatKey] ?? 0;
            if ($seen >= $this->collapseAfter) {
                $this->collapsed++;

                return false;
            }
            $this->siblings[$repeatKey] = $seen + 1;
        }
        $this->used++;
        $this->groupUsed[$group] = ($this->groupUsed[$group] ?? 0) + 1;

        return true;
    }

    /**
     * Gives back the permission of a span whose hook then started nothing.
     *
     * A guard such as "already inside a span for this object" skips a duplicate; without this it would eat budget and
     * starve the real spans.
     *
     * @param string $group
     * @param string $repeatKey
     * @return void
     */
    public function release(string $group = '', string $repeatKey = ''): void
    {
        $this->used = max(0, $this->used - 1);
        $this->groupUsed[$group] = max(0, ($this->groupUsed[$group] ?? 0) - 1);
        if ($repeatKey !== '' && isset($this->siblings[$repeatKey])) {
            $this->siblings[$repeatKey] = max(0, $this->siblings[$repeatKey] - 1);
        }
    }

    /**
     * Counts a refused span.
     *
     * @param string $group
     * @return bool always false
     */
    private function refuse(string $group): bool
    {
        $this->dropped++;
        $this->droppedTotal++;
        if (isset($this->groupCaps[$group])) {
            $this->groupDropped[$group] = ($this->groupDropped[$group] ?? 0) + 1;
        }

        return false;
    }

    /**
     * Spans not started because of repeat collapsing in the current unit of work.
     *
     * @return int
     */
    public function collapsed(): int
    {
        return $this->collapsed;
    }

    /**
     * Root-span attributes describing what volume control did; only groups with a configured cap appear.
     *
     * @return array<string,int>
     */
    public function summary(): array
    {
        $out = [];
        if ($this->dropped > 0) {
            $out['bode.magento.spans.dropped'] = $this->dropped;
        }
        if ($this->collapsed > 0) {
            $out['bode.magento.spans.collapsed'] = $this->collapsed;
        }
        foreach ($this->groupDropped as $group => $count) {
            $out['bode.magento.spans.dropped.' . $group] = $count;
        }

        return $out;
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
