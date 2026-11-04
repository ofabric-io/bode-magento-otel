<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Magento\Framework\MessageQueue\Consumer;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Queue consumers: one root span per message ("queue <topic>"), with the topic (a bounded set), the consumer name and
 * the outcome. The message body is never recorded. The consumer process itself runs for hours and gets no span.
 */
class QueueInstrumentation implements InstrumentationInterface
{
    private const CONSUMER = Consumer::class;

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        $runtime->instrumenter->span(
            'queue',
            self::CONSUMER,
            'dispatchMessage',
            function (?object $subject, array $params) use ($runtime): SpanHandleInterface {
                $topic = $this->topic($params[0] ?? null);
                $consumer = $this->consumerName($subject);

                return $runtime->spans->start(
                    'queue ' . $topic,
                    SpanKind::KIND_CONSUMER,
                    [
                        'messaging.system' => 'magento_queue',
                        'messaging.operation.type' => 'process',
                        'messaging.destination.name' => $topic,
                        'bode.magento.queue.consumer' => $consumer,
                    ]
                );
            },
            static function (
                SpanHandleInterface $span,
                ?object $subject,
                array $params,
                mixed $ret,
                mixed $error
            ): void {
                $span->setAttribute('bode.magento.queue.outcome', $error === null ? 'success' : 'error');
            },
            true
        );
    }

    /**
     * Topic of a queue message, bounded.
     *
     * @param mixed $envelope
     * @return string
     */
    private function topic(mixed $envelope): string
    {
        $topic = '';
        if (is_object($envelope) && method_exists($envelope, 'getProperties')) {
            $props = $envelope->getProperties();
            $topic = is_array($props) ? (string) ($props['topic_name'] ?? '') : '';
        }

        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $topic), 0, 100) ?: 'unknown';
    }

    /**
     * Name of the consumer that handles the message.
     *
     * @param object|null $consumer
     * @return string
     */
    private function consumerName(?object $consumer): string
    {
        if ($consumer === null) {
            return 'unknown';
        }
        // The configuration is a private property of the consumer; read it through a closure bound to the instance.
        $name = (fn () => $this->configuration->getConsumerName())->call($consumer);

        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $name), 0, 100) ?: 'unknown';
    }
}
