<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Bode\Tracing\Model\Environment;

/**
 * The OpenTelemetry resource of this process: who is logging. Same sources as the SDK, so logs, traces and metrics of a
 * service carry the same identity: OTEL_SERVICE_NAME and OTEL_RESOURCE_ATTRIBUTES (service.version,
 * deployment.environment.name, any other key=value pairs).
 */
class ResourceAttributes
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $resolved = null;

    /**
     * @param Environment $env
     */
    public function __construct(private readonly Environment $env)
    {
    }

    /**
     * Resource attributes, computed once per process.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $fromEnv = $this->parse($this->env->get('OTEL_RESOURCE_ATTRIBUTES'));
        // The older attribute name is still common in deployments; both end up as deployment.environment.name.
        if (isset($fromEnv['deployment.environment']) && !isset($fromEnv['deployment.environment.name'])) {
            $fromEnv['deployment.environment.name'] = $fromEnv['deployment.environment'];
        }
        unset($fromEnv['deployment.environment']);

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $host = gethostname();
        $resolved = $fromEnv + [
            'service.name' => $this->env->get('OTEL_SERVICE_NAME', $fromEnv['service.name'] ?? 'magento'),
            'host.name' => $host === false ? 'unknown' : $host,
            'process.pid' => getmypid() ?: 0,
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
        ];
        // OTEL_SERVICE_NAME wins over the same key in OTEL_RESOURCE_ATTRIBUTES, as in the SDK.
        $resolved['service.name'] = $this->env->get('OTEL_SERVICE_NAME', (string) $resolved['service.name']);
        ksort($resolved);

        return $this->resolved = $resolved;
    }

    /**
     * Parses "k1=v1,k2=v2" (W3C baggage style, percent-encoded values).
     *
     * @param string $value
     * @return array<string,string>
     */
    private function parse(string $value): array
    {
        $result = [];
        foreach (explode(',', $value) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '') {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction -- values are percent-encoded by the spec
                $result[trim($parts[0])] = rawurldecode(trim($parts[1]));
            }
        }

        return $result;
    }
}
