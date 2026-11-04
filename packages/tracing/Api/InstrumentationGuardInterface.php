<?php

declare(strict_types=1);

namespace Bode\Tracing\Api;

/**
 * Decides whether telemetry is collected at all, for which feature group, and for which request path.
 * Shared with Bode_Metrics so both modules obey the same switches.
 *
 * @api
 */
interface InstrumentationGuardInterface
{
    /**
     * True when the OpenTelemetry extension and SDK are present and telemetry is not disabled by env.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * True when a feature group is switched on in MAGENTO_OTEL_TRACING_GROUPS.
     *
     * Groups: http, cron, cli, indexer, cache, queue, outbound (default on); events, sql, eav, redis, business.
     *
     * @param string $group
     * @return bool
     */
    public function isGroupEnabled(string $group): bool;

    /**
     * True when a web request path is excluded from telemetry (health check, static, media, cron.php, ...).
     *
     * @param string $path
     * @return bool
     */
    public function isPathExcluded(string $path): bool;
}
