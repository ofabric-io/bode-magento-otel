<?php

declare(strict_types=1);

namespace Bode\Metrics\Instrumentation;

use Bode\Metrics\Hook\MetricsContext;
use Bode\Tracing\Model\SqlObfuscator;
use GuzzleHttp\Client;
use Magento\Framework\DB\Adapter\Pdo\Mysql;

/**
 * Calls to other systems: outbound HTTP by allow-listed host, database operations by verb only (never the table),
 * Redis commands by command only. Database and Redis are opt-in groups (MAGENTO_OTEL_METRICS_GROUPS=db,redis)
 * because they sit on the hottest paths.
 *
 * Outbound hosts: only hosts listed in MAGENTO_OTEL_OUTBOUND_HOSTS keep their name; every other host is "other", so
 * a payment gateway with a hostname per customer cannot create series.
 */
class ServiceMetrics implements InstrumentationInterface
{
    private const REDIS = 'Cm_Cache_Backend_Redis';

    /**
     * @inheritdoc
     */
    public function register(MetricsContext $context): void
    {
        $allowed = array_map('strtolower', $context->env->list('MAGENTO_OTEL_OUTBOUND_HOSTS'));
        $outbound = function (
            float $seconds,
            ?object $s,
            array $params,
            mixed $response,
            mixed $error
        ) use (
            $context,
            $allowed
        ): void {
            $host = $this->hostOf($params);
            $label = in_array($host, $allowed, true) ? $host : 'other';
            $status = is_object($response) && method_exists($response, 'getStatusCode')
                ? $context->routes->statusClass((int) $response->getStatusCode())
                : 'error';
            $context->recorder->count(
                'magento.outbound.http.calls',
                ['server.address' => $label, 'http.response.status_class' => $status]
            );
            $context->recorder->observe('magento.outbound.http.duration', $seconds, ['server.address' => $label]);
        };
        $context->timed('outbound', Client::class, 'send', $outbound);
        $context->timed('outbound', Client::class, 'request', $outbound);

        $sql = new SqlObfuscator();
        $context->timed(
            'db',
            Mysql::class,
            'query',
            static function (float $seconds, ?object $s, array $params) use ($context, $sql): void {
                $statement = is_object($params[0] ?? null) ? (string) $params[0] : (string) ($params[0] ?? '');
                $context->recorder->observe(
                    'magento.db.operation.duration',
                    $seconds,
                    ['db.operation' => $sql->operation($statement)]
                );
            }
        );

        foreach (['load' => 'GET', 'save' => 'SET', 'remove' => 'DEL', 'clean' => 'CLEAN'] as $method => $command) {
            $context->timed(
                'redis',
                self::REDIS,
                $method,
                static function (float $seconds) use ($context, $command): void {
                    $context->recorder->observe(
                        'magento.redis.command.duration',
                        $seconds,
                        ['db.operation' => $command]
                    );
                }
            );
        }
    }

    /**
     * Host of a Guzzle call: send(RequestInterface) or request(method, uri).
     *
     * @param array<int,mixed> $params
     * @return string lower-case host, empty when unknown
     */
    private function hostOf(array $params): string
    {
        $first = $params[0] ?? null;
        if (is_object($first) && method_exists($first, 'getUri')) {
            return strtolower((string) $first->getUri()->getHost());
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return strtolower((string) parse_url((string) ($params[1] ?? ''), PHP_URL_HOST));
    }
}
