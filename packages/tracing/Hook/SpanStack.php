<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

/**
 * Keeps pre and post hooks in balance. Every pre hook pushes exactly one entry (a span handle, or null when no span was
 * started because the group is off, the budget is used up or starting failed); every post hook pops exactly one. A
 * post hook therefore never ends or detaches a span that belongs to someone else, which upstream's context-based
 * approach could do when a pre hook returned early or threw.
 */
class SpanStack
{
    /**
     * @var array<int,SpanHandleInterface|null>
     */
    private array $entries = [];
    /**
     * @var int[] serial of each entry, parallel to $entries
     */
    private array $serials = [];
    /**
     * @var int
     */
    private int $nextSerial = 0;

    /**
     * Index in $entries of every open unit root (request, job, message, command), outermost first.
     *
     * @var int[]
     */
    private array $roots = [];

    /**
     * @var int
     */
    private int $orphanedTotal = 0;

    /**
     * @var int
     */
    private int $orphanedPending = 0;

    /**
     * Pushes the entry of a pre hook.
     *
     * @param SpanHandleInterface|null $handle
     * @return void
     */
    public function push(?SpanHandleInterface $handle): void
    {
        $this->entries[] = $handle;
        $this->serials[] = ++$this->nextSerial;
    }

    /**
     * Pops the entry of the matching pre hook.
     *
     * @return SpanHandleInterface|null null when the matching pre hook started no span or the stack is empty
     */
    public function pop(): ?SpanHandleInterface
    {
        if ($this->entries === []) {
            return null;
        }
        if ($this->roots !== [] && end($this->roots) === count($this->entries) - 1) {
            array_pop($this->roots);
        }
        array_pop($this->serials);

        return array_pop($this->entries);
    }

    /**
     * Pushes the entry of the pre hook of a unit root.
     *
     * A null handle is a suppressed unit (excluded path, no trace): its descendants are orphans.
     *
     * @param SpanHandleInterface|null $handle
     * @return void
     */
    public function pushRoot(?SpanHandleInterface $handle): void
    {
        $this->roots[] = count($this->entries);
        $this->entries[] = $handle;
        $this->serials[] = ++$this->nextSerial;
    }

    /**
     * True when the innermost unit of work has a real root span, so a child span may join its trace.
     *
     * @return bool
     */
    public function hasUnitRoot(): bool
    {
        return $this->roots !== [] && ($this->entries[end($this->roots)] ?? null) !== null;
    }

    /**
     * Removes the entries above the innermost unit root.
     *
     * They are spans whose post hook never ran (e.g. after a fatal error); the root's own post hook then closes the
     * root and the stack is clean for the next unit.
     *
     * @return SpanHandleInterface[] the leaked spans, innermost first; the caller ends them
     */
    public function unwindToRoot(): array
    {
        $leaked = [];
        if ($this->roots === []) {
            return $leaked;
        }
        $rootIndex = (int) end($this->roots);
        while (count($this->entries) - 1 > $rootIndex) {
            $entry = array_pop($this->entries);
            array_pop($this->serials);
            if ($entry !== null) {
                $leaked[] = $entry;
            }
        }

        return $leaked;
    }

    /**
     * Counts a span that was not started because it had no unit root to belong to.
     *
     * @return void
     */
    public function recordOrphan(): void
    {
        $this->orphanedTotal++;
        $this->orphanedPending++;
    }

    /**
     * Orphans since the process started.
     *
     * @return int
     */
    public function orphanedTotal(): int
    {
        return $this->orphanedTotal;
    }

    /**
     * Orphans counted since the previous unit root started; reading resets it.
     *
     * @return int
     */
    public function takePendingOrphans(): int
    {
        $count = $this->orphanedPending;
        $this->orphanedPending = 0;

        return $count;
    }

    /**
     * Unique id of the innermost entry (parent of a span about to start), never reused in the process.
     *
     * @return int
     */
    public function currentSerial(): int
    {
        return $this->serials === [] ? 0 : $this->serials[count($this->serials) - 1];
    }

    /**
     * Innermost span that is really open, or null.
     *
     * @return SpanHandleInterface|null
     */
    public function top(): ?SpanHandleInterface
    {
        for ($i = count($this->entries) - 1; $i >= 0; $i--) {
            if ($this->entries[$i] !== null) {
                return $this->entries[$i];
            }
        }

        return null;
    }

    /**
     * Outermost span that is really open (the root of the unit of work), or null.
     *
     * @return SpanHandleInterface|null
     */
    public function bottom(): ?SpanHandleInterface
    {
        foreach ($this->entries as $entry) {
            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Number of entries, including placeholders.
     *
     * @return int number of entries, including null placeholders
     */
    public function depth(): int
    {
        return count($this->entries);
    }

    /**
     * Drops all entries (a new unit of work in a long-running process).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->roots = [];
        $this->serials = [];
    }
}
