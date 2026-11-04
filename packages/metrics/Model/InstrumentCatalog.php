<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

/**
 * Every instrument this module can create: type, unit, description. LabelPolicy holds the matching attribute
 * allow-lists. Adding an instrument means adding it to both places.
 */
class InstrumentCatalog
{
    public const COUNTER = 'counter';
    public const HISTOGRAM = 'histogram';
    public const GAUGE = 'gauge';
    public const SECONDS_BUCKETS = [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    public const INSTRUMENTS = [
        'http.server.request.duration' => [self::HISTOGRAM, 's', 'Duration of HTTP server requests'],
        'magento.http.server.errors' => [self::COUNTER, '{request}', 'HTTP requests answered with 4xx or 5xx'],
        'magento.cron.job.runs' => [self::COUNTER, '{run}', 'Cron job runs by outcome'],
        'magento.cron.job.duration' => [self::HISTOGRAM, 's', 'Cron job duration'],
        'magento.cron.oldest_pending_age' => [self::GAUGE, 's', 'Age of the oldest pending or missed cron schedule'],
        'magento.cron.jobs_stuck' => [self::GAUGE, '{job}', 'Cron jobs stuck in running'],
        'magento.indexer.status' => [self::GAUGE, '{indexer}', 'Indexer state: 1 for the current status of each'],
        'magento.indexer.runs' => [self::COUNTER, '{run}', 'Indexer runs by outcome'],
        'magento.indexer.duration' => [self::HISTOGRAM, 's', 'Indexer run duration'],
        'magento.mview.backlog' => [self::GAUGE, '{version}', 'Changelog versions not yet processed per view'],
        'magento.queue.backlog' => [self::GAUGE, '{message}', 'Database queue messages by status'],
        'magento.queue.messages' => [self::COUNTER, '{message}', 'Queue messages handled by outcome'],
        'magento.queue.message.duration' => [self::HISTOGRAM, 's', 'Queue message handling time'],
        'magento.cache.operations' => [self::COUNTER, '{operation}', 'Cache operations'],
        'magento.cache.flushes' => [self::COUNTER, '{flush}', 'Cache flushes and cleans'],
        'magento.db.operation.duration' => [self::HISTOGRAM, 's', 'Database operation duration by verb'],
        'magento.redis.command.duration' => [self::HISTOGRAM, 's', 'Redis cache command duration'],
        'magento.outbound.http.calls' => [self::COUNTER, '{call}', 'Outbound HTTP calls to allow-listed hosts'],
        'magento.outbound.http.duration' => [self::HISTOGRAM, 's', 'Outbound HTTP call duration'],
        'magento.orders.placed' => [self::COUNTER, '{order}', 'Orders placed'],
        'magento.payments.failed' => [self::COUNTER, '{payment}', 'Payment failures while placing an order'],
        'magento.emails' => [self::COUNTER, '{email}', 'Transactional emails by outcome'],
        'magento.admin.login.failures' => [self::COUNTER, '{login}', 'Failed admin logins'],
        'bode.telemetry.errors' => [self::COUNTER, '{error}', 'Instrumentation failures that were swallowed'],
        'bode.telemetry.spans.dropped' => [self::COUNTER, '{span}', 'Spans refused by the per-trace budget'],
    ];
}
