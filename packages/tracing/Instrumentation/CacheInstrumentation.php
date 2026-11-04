<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\PageCache\Model\Cache\Type;
use Magento\Framework\App\Cache\Manager;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Cache management: flushing and cleaning cache types (bin/magento cache:*, admin cache page, deploys) and the page
 * cache. Cache keys and tags are never recorded; cache types are a bounded set.
 */
class CacheInstrumentation implements InstrumentationInterface
{
    private const MANAGER = Manager::class;
    private const PAGE_CACHE = Type::class;

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        foreach (['flush', 'clean'] as $operation) {
            $runtime->instrumenter->span(
                'cache',
                self::MANAGER,
                $operation,
                function (?object $subject, array $params) use ($runtime, $operation): SpanHandleInterface {
                    $types = is_array($params[0] ?? null) ? $params[0] : [];
                    $types = array_slice(array_map(
                        static fn ($type): string => substr(
                            (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) $type)),
                            0,
                            50
                        ),
                        $types
                    ), 0, 20);

                    return $runtime->spans->start(
                        'cache ' . $operation,
                        SpanKind::KIND_INTERNAL,
                        [
                            'bode.magento.cache.operation' => $operation,
                            'bode.magento.cache.types' => implode(',', $types),
                        ]
                    );
                }
            );
        }

        $runtime->instrumenter->span(
            'cache',
            self::PAGE_CACHE,
            'clean',
            static fn (): SpanHandleInterface => $runtime->spans->start(
                'cache page clean',
                SpanKind::KIND_INTERNAL,
                ['bode.magento.cache.operation' => 'clean', 'bode.magento.cache.types' => 'full_page']
            )
        );
    }
}
