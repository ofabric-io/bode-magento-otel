<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * The cardinality contract of this module. Every instrument has an allow-list of attribute keys; anything else is
 * dropped before it reaches the SDK, so a careless call site cannot create a per-user or per-URL series.
 *
 * Allowed keys are bounded sets only: route (Magento full action name, REST template, GraphQL operation), method,
 * status class, area, store code, cron job code and group, indexer id, cache type, queue topic and consumer, payment
 * method code, allow-listed outbound host. Never user or customer ids, IPs, sessions, SKUs, order ids, raw paths or
 * URLs, query strings, error messages, schedule ids or event names.
 */
class LabelPolicy
{
    /**
     * Keys no instrument may ever use, whatever its allow-list says. A second line of defence for people who edit
     * the allow-lists.
     */
    public const FORBIDDEN_KEYS = [
        'enduser.id', 'user.id', 'customer.id', 'client.address', 'session.id', 'url.full', 'url.path', 'url.query',
        'sku', 'order.id', 'order_id', 'error.message', 'exception.message', 'schedule_id', 'event.name',
        'db.query.text',
        'user_agent.original',
    ];

    /**
     * @var array<string,string[]>
     */
    private array $allowed;

    /**
     * @param array<string,string[]> $allowed instrument name => allowed attribute keys
     */
    public function __construct(array $allowed = self::DEFAULT_ALLOWED)
    {
        $this->allowed = $allowed;
    }

    public const DEFAULT_ALLOWED = [
        'http.server.request.duration' => ['http.route', 'http.request.method', 'http.response.status_class', 'area'],
        'magento.http.server.errors' => ['http.route', 'http.response.status_class', 'area'],
        'magento.cron.job.runs' => ['cron.job_code', 'cron.group', 'status'],
        'magento.cron.job.duration' => ['cron.job_code', 'cron.group'],
        'magento.cron.oldest_pending_age' => ['status'],
        'magento.cron.jobs_stuck' => [],
        'magento.indexer.status' => ['indexer_id', 'status'],
        'magento.indexer.runs' => ['indexer_id', 'mode', 'outcome'],
        'magento.indexer.duration' => ['indexer_id', 'mode'],
        'magento.mview.backlog' => ['view_id'],
        'magento.queue.backlog' => ['queue', 'status'],
        'magento.queue.messages' => ['topic', 'consumer', 'outcome'],
        'magento.queue.message.duration' => ['topic', 'consumer'],
        'magento.cache.operations' => ['cache_type', 'operation', 'result'],
        'magento.cache.flushes' => ['cache_type'],
        'magento.db.operation.duration' => ['db.operation'],
        'magento.redis.command.duration' => ['db.operation'],
        'magento.outbound.http.calls' => ['server.address', 'http.response.status_class'],
        'magento.outbound.http.duration' => ['server.address'],
        'magento.orders.placed' => ['store', 'payment_method'],
        'magento.payments.failed' => ['payment_method'],
        'magento.emails' => ['outcome'],
        'magento.admin.login.failures' => [],
        'bode.telemetry.errors' => ['group'],
        'bode.telemetry.spans.dropped' => [],
    ];

    /**
     * Keeps only the attributes the instrument may carry.
     *
     * @param string $instrument
     * @param array<string,scalar|null> $attributes
     * @return array<string,string>
     */
    public function filter(string $instrument, array $attributes): array
    {
        $allowed = $this->allowed[$instrument] ?? [];
        $out = [];
        foreach ($attributes as $key => $value) {
            if (!in_array($key, $allowed, true) || in_array($key, self::FORBIDDEN_KEYS, true) || $value === null) {
                continue;
            }
            $out[$key] = (string) $value;
        }

        return $out;
    }

    /**
     * Whether the instrument is known.
     *
     * @param string $instrument
     * @return bool
     */
    public function knows(string $instrument): bool
    {
        return isset($this->allowed[$instrument]);
    }

    /**
     * Every distinct allowed key across instruments.
     *
     * @return string[]
     */
    public function allKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->allowed))));
    }
}
