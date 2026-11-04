<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * Overflow guard: after N distinct values of one attribute key in this process, any further new value is replaced by
 * "other". It protects long-running processes (queue consumers, cron:run) and bounds a bug that feeds unexpected
 * values. It cannot see other processes: PHP-FPM workers are short-lived and each sees only its own requests, so the
 * real protection is the allow-list in LabelPolicy plus bounded sources, and the series limit in the collector.
 */
class CardinalityGuard
{
    public const OTHER = 'other';
    public const DEFAULT_LIMIT = 100;

    /**
     * @var array<string,array<string,true>>
     */
    private array $seen = [];

    /**
     * @var int
     */
    private int $collapsed = 0;

    /**
     * @param int $limit distinct values allowed per attribute key
     */
    public function __construct(private readonly int $limit = self::DEFAULT_LIMIT)
    {
    }

    /**
     * Collapses values beyond the limit into "other".
     *
     * @param array<string,string> $attributes
     * @return array<string,string>
     */
    public function guard(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (isset($this->seen[$key][$value])) {
                continue;
            }
            if (count($this->seen[$key] ?? []) >= $this->limit) {
                $attributes[$key] = self::OTHER;
                $this->collapsed++;
                continue;
            }
            $this->seen[$key][$value] = true;
        }

        return $attributes;
    }

    /**
     * Values collapsed into "other" since the process started.
     *
     * @return int
     */
    public function collapsed(): int
    {
        return $this->collapsed;
    }
}
