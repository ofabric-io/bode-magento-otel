<?php

declare(strict_types=1);

/**
 * Deterministic W3C traceparent generation, so scenarios send a known sampled trace id the assertions can look for
 * by exact match instead of having to discover ids after the fact.
 */

function traceIdFor(string $scenario): string
{
    return substr(hash('sha256', 'bode-e2e-trace:' . $scenario), 0, 32);
}

function spanIdFor(string $scenario): string
{
    return substr(hash('sha256', 'bode-e2e-span:' . $scenario), 0, 16);
}

function traceparentFor(string $scenario): string
{
    return sprintf('00-%s-%s-01', traceIdFor($scenario), spanIdFor($scenario));
}
