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
        array_pop($this->serials);

        return array_pop($this->entries);
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
        $this->serials = [];
    }
}
