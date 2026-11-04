<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Bode\Tracing\Api\TraceContextProviderInterface;
use Monolog\LogRecord;
use Throwable;

/**
 * Collects everything a JSON line says about a record: trace ids, the emitting code, the unit of work, the exception
 * and the normalised context. Runs once per record, inside the logger call (the processor), so the backtrace and the
 * current span are the ones of the code that logged; the formatter then only serialises.
 */
class RecordAssembler
{
    /** Context key the Throwable plugin uses for an exception that was passed as the message. */
    public const EXCEPTION_KEY = 'bode_exception';
    /** Context keys that may hold the exception of a record, in order of preference. */
    private const EXCEPTION_KEYS = [self::EXCEPTION_KEY, 'exception'];

    /**
     * @param Config $config
     * @param TraceContextProviderInterface $trace
     * @param CallerResolver $caller
     * @param ExecutionContext $execution
     * @param ExceptionNormalizer $exceptions
     * @param ValueNormalizer $values
     * @param ResourceAttributes $resource
     */
    public function __construct(
        private readonly Config $config,
        private readonly TraceContextProviderInterface $trace,
        private readonly CallerResolver $caller,
        private readonly ExecutionContext $execution,
        private readonly ExceptionNormalizer $exceptions,
        private readonly ValueNormalizer $values,
        private readonly ResourceAttributes $resource
    ) {
    }

    /**
     * Everything for the JSON line of a record, apart from its time, level and message.
     *
     * @param LogRecord $record
     * @return array{trace: array<string,string>|null, resource: array<string,mixed>, attributes: array<string,mixed>}
     */
    public function assemble(LogRecord $record): array
    {
        $attributes = ['log.channel' => $record->channel];

        if ($this->config->isCallerEnabled()) {
            $caller = $this->caller->resolve();
            if ($caller !== null) {
                $attributes += array_filter([
                    'code.namespace' => $caller['namespace'],
                    'code.function' => $caller['function'],
                    'code.file.path' => $caller['file'],
                    'code.line.number' => $caller['line'],
                ], static fn ($value): bool => $value !== null);
            }
        }

        $attributes += $this->execution->attributes();

        [$throwable, $exceptionKey] = $this->findException($record->context);
        if ($throwable !== null) {
            $attributes += $this->exceptions->attributes($throwable);
        }

        $context = $record->context;
        if ($exceptionKey !== null) {
            unset($context[$exceptionKey]);
        }
        if ($context !== []) {
            $attributes['context'] = $this->values->normalize($context);
        }
        $extra = array_diff_key($record->extra, array_flip(['trace_id', 'span_id', 'trace_flags', 'bode_log']));
        if ($extra !== []) {
            $attributes['extra'] = $this->values->normalize($extra);
        }

        return [
            'trace' => $this->trace($record),
            'resource' => $this->resource->all(),
            'attributes' => $attributes,
        ];
    }

    /**
     * The exception of a record, and the context key it was found under.
     *
     * @param array<string,mixed> $context
     * @return array{0: Throwable|null, 1: string|null}
     */
    private function findException(array $context): array
    {
        foreach (self::EXCEPTION_KEYS as $key) {
            if (($context[$key] ?? null) instanceof Throwable) {
                return [$context[$key], $key];
            }
        }

        return [null, null];
    }

    /**
     * Trace ids: from the live span, else from an earlier processor (Bode_Tracing's own, for example).
     *
     * @param LogRecord $record
     * @return array<string,string>|null
     */
    private function trace(LogRecord $record): ?array
    {
        $current = $this->trace->current();
        if ($current !== null) {
            return $current;
        }
        $extra = $record->extra;
        if (isset($extra['trace_id'], $extra['span_id']) && is_string($extra['trace_id'])) {
            return [
                'trace_id' => $extra['trace_id'],
                'span_id' => (string) $extra['span_id'],
                'trace_flags' => (string) ($extra['trace_flags'] ?? '00'),
            ];
        }

        return null;
    }
}
