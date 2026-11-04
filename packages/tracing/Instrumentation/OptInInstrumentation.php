<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\Framework\Event\Manager;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Noisy, opt-in groups (off by default, switch on with MAGENTO_OTEL_TRACING_GROUPS): "sql" database queries, "events"
 * dispatched Magento events, "redis" cache backend commands. Each is capped by the per-trace span budget.
 *
 * SQL is recorded obfuscated (literals and binds removed) and bounded; event spans carry the event name only; Redis
 * spans carry the command only, never keys or values.
 */
class OptInInstrumentation implements InstrumentationInterface
{
    private const DB = Mysql::class;
    private const EVENT_MANAGER = Manager::class;
    private const REDIS = 'Cm_Cache_Backend_Redis';

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        $runtime->instrumenter->span(
            'sql',
            self::DB,
            'query',
            function (?object $subject, array $params) use ($runtime): SpanHandleInterface {
                $sql = is_object($params[0] ?? null) ? (string) $params[0] : (string) ($params[0] ?? '');
                $operation = $runtime->sql->operation($sql);
                $table = $runtime->sql->table($sql);

                return $runtime->spans->start(
                    trim($operation . ' ' . $table),
                    SpanKind::KIND_CLIENT,
                    [
                        'db.system.name' => 'mysql',
                        'db.operation.name' => $operation,
                        'db.collection.name' => $table,
                        'db.query.text' => $runtime->sql->obfuscate($sql),
                    ]
                );
            }
        );

        $runtime->instrumenter->span(
            'events',
            self::EVENT_MANAGER,
            'dispatch',
            static function (?object $subject, array $params) use ($runtime): SpanHandleInterface {
                $name = substr((string) preg_replace('/[^A-Za-z0-9_.]/', '', (string) ($params[0] ?? '')), 0, 100);

                return $runtime->spans->start(
                    'event ' . $name,
                    SpanKind::KIND_INTERNAL,
                    ['bode.magento.event' => $name]
                );
            }
        );

        foreach (['load' => 'GET', 'save' => 'SET', 'remove' => 'DEL', 'clean' => 'CLEAN'] as $method => $command) {
            $runtime->instrumenter->span(
                'redis',
                self::REDIS,
                $method,
                static fn (): SpanHandleInterface => $runtime->spans->start(
                    'redis ' . $command,
                    SpanKind::KIND_CLIENT,
                    ['db.system.name' => 'redis', 'db.operation.name' => $command]
                )
            );
        }
    }
}
