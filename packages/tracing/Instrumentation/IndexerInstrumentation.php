<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\Framework\Mview\View;
use Magento\Indexer\Model\Indexer;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Indexers and Mview: a full reindex, a row/list reindex, and the Mview update that processes a changelog.
 * Attributes are the indexer or view id (a bounded set) and the mode; never entity ids.
 */
class IndexerInstrumentation implements InstrumentationInterface
{
    private const INDEXER = Indexer::class;
    private const VIEW = View::class;

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        foreach (['reindexAll', 'reindexList', 'reindexRow'] as $method) {
            $runtime->instrumenter->span(
                'indexer',
                self::INDEXER,
                $method,
                function (?object $subject) use ($runtime, $method): SpanHandleInterface {
                    $id = $this->idOf($subject);
                    $mode = $subject !== null && method_exists($subject, 'isScheduled')
                        ? ($subject->isScheduled() ? 'schedule' : 'save')
                        : 'unknown';

                    return $runtime->spans->start(
                        'indexer ' . $id . ' ' . $method,
                        SpanKind::KIND_INTERNAL,
                        [
                            'bode.magento.indexer.id' => $id,
                            'bode.magento.indexer.mode' => $mode,
                            'bode.magento.indexer.operation' => $method,
                        ]
                    );
                },
                null,
                $method === 'reindexAll' && PHP_SAPI === 'cli'
            );
        }

        $runtime->instrumenter->span(
            'indexer',
            self::VIEW,
            'update',
            function (?object $subject) use ($runtime): SpanHandleInterface {
                $id = $this->idOf($subject);

                return $runtime->spans->start(
                    'mview ' . $id . ' update',
                    SpanKind::KIND_INTERNAL,
                    ['bode.magento.mview.id' => $id]
                );
            }
        );
    }

    /**
     * Bounded identifier of an indexer or view.
     *
     * @param object|null $subject
     * @return string
     */
    private function idOf(?object $subject): string
    {
        $id = $subject !== null && method_exists($subject, 'getId') ? (string) $subject->getId() : 'unknown';

        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $id), 0, 100) ?: 'unknown';
    }
}
