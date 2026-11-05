<?php

declare(strict_types=1);

/**
 * One-shot process that causes a real PHP fatal error (E_ERROR, uncatchable, ends the process immediately) after
 * Magento's bootstrap has run packages/logs/registration.php and installed
 * Bode\Logs\Model\FatalErrorReporter::onShutdown() as a shutdown function.
 *
 * Memory exhaustion, not an undefined-function call: since PHP 7, a call to an undefined function throws a
 * catchable \Error (Throwable), which FatalErrorReporter::onException() - installed via set_exception_handler() -
 * would actually catch, exercising the *same* path as the 'uncaught' scenario, not onShutdown()'s separate
 * error_get_last()-based path that this scenario exists to cover. A memory-limit fatal is one of the few things
 * left in plain PHP that bypasses the exception mechanism entirely and is only ever visible through
 * register_shutdown_function() + error_get_last() - exactly what onShutdown() is for.
 *
 * The shared dev image sets memory_limit=-1 (docker/php/Dockerfile - Magento's installer needs it), so this script
 * has to lower it back down itself first, to a limit PHP's own memory manager enforces regardless of how much RAM
 * the host actually has (so this is fast and deterministic, not an OS-level OOM-kill that would bypass shutdown
 * functions entirely and produce no record at all).
 *
 * PHP's ini_set('memory_limit', ...) silently fails (a warning, limit stays unchanged) if the target is below
 * *current* usage - and Magento's own bootstrap alone already uses ~60MB here (verified: a bare -d memory_limit=16M
 * on the CLI, or a naive ini_set('memory_limit', '16M') right after Bootstrap::create(), both failed exactly this
 * way, running unbounded until the container's own OOM killer intervened minutes later with no record written at
 * all, since SIGKILL gives no chance to run a shutdown function). The limit has to be computed relative to current
 * usage.
 *
 * A second, equally real problem (also verified, not assumed): once a memory_limit fatal actually fires, usage is
 * by definition right at the ceiling - zero bytes free for anything that runs next, including
 * FatalErrorReporter::onShutdown() itself, which still needs to autoload a few classes (confirmed: it failed deep
 * inside OpenTelemetry\Context's autoload, mid-shutdown, with only 8MB of slack) before it can format and write its
 * JSON record. So this deliberately keeps a reserved block of headroom the growth loop below is never allowed to
 * touch, and triggers the actual fatal with one single oversized allocation instead of letting the loop find the
 * ceiling itself - that reserved block is what onShutdown() actually runs in.
 */

require '/magento/app/bootstrap.php';

\Magento\Framework\App\Bootstrap::create(BP, $_SERVER);

$baseline = memory_get_usage(true);
$reserveForReporter = 32 * 1024 * 1024; // headroom onShutdown() runs in: autoloading a few classes, building a record
$bufferBudget = 16 * 1024 * 1024; // how far the loop below is allowed to grow before the one deliberate fatal below
ini_set('memory_limit', (string) ($baseline + $reserveForReporter + $bufferBudget));

$buffer = [];
while (memory_get_usage(true) - $baseline < $bufferBudget) {
    $buffer[] = str_repeat('e2e-fatal-trigger', 4096);
}
// Deliberately larger than $reserveForReporter, and never stored anywhere: this single allocation attempt is what
// actually exhausts the limit, at a point where $reserveForReporter bytes are still genuinely free for the
// shutdown handler, unlike letting an unbounded loop consume right up to the edge itself.
// phpcs:ignore Magento2.Performance.ForeachArrayMerge -- not a merge, a single deliberate oversized allocation
$trigger = str_repeat('x', $reserveForReporter * 2);
