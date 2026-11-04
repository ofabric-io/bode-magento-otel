<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Throwable;

/**
 * Turns a Throwable into the exception attributes of a log record, following the OpenTelemetry exception semantic
 * conventions (exception.type, exception.message, exception.stacktrace) plus what a search needs: structured frames,
 * the chain of previous exceptions and a fingerprint that groups the same failure across occurrences.
 *
 * Stack frames never carry arguments: they hold customer data, card data and whole objects.
 */
class ExceptionNormalizer
{
    private const MESSAGE_BYTES = 4096;

    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Attributes of a Throwable for a log record.
     *
     * @param Throwable $e
     * @return array<string,mixed>
     */
    public function attributes(Throwable $e): array
    {
        $frames = $this->frames($e);
        $kept = array_slice($frames, 0, $this->config->stackFrames());
        $chain = $this->chain($e);

        $attributes = [
            'exception.type' => $e::class,
            'exception.message' => $this->cut($e->getMessage()),
            'exception.stacktrace' => $this->stacktrace($e, $kept, count($frames)),
            'exception.file' => $e->getFile(),
            'exception.line' => $e->getLine(),
        ];
        if ($e->getCode() !== 0) {
            $attributes['exception.code'] = $e->getCode();
        }
        $attributes['exception.frames'] = $kept;
        if (count($frames) > count($kept)) {
            $attributes['exception.frames_dropped'] = count($frames) - count($kept);
        }
        if ($chain !== []) {
            $attributes['exception.cause'] = $chain;
        }
        $attributes['error.fingerprint'] = $this->fingerprint($e);

        return $attributes;
    }

    /**
     * Short form for exceptions found inside a log context: type, message, location, no stack.
     *
     * @param Throwable $e
     * @return array<string,mixed>
     */
    public function summary(Throwable $e): array
    {
        $summary = [
            'type' => $e::class,
            'message' => $this->cut($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        if ($e->getPrevious() !== null) {
            $summary['previous'] = $e->getPrevious()::class . ': ' . $this->cut($e->getPrevious()->getMessage());
        }

        return $summary;
    }

    /**
     * Fingerprint that groups the same failure: the root cause's type and where it was thrown.
     *
     * Wrapping it differently, or hitting it from another request, does not split the group.
     *
     * @param Throwable $e
     * @return string 16 hex characters
     */
    public function fingerprint(Throwable $e): string
    {
        $root = $e;
        $depth = $this->config->exceptionDepth();
        while ($depth-- > 0 && $root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }

        return substr(sha1($root::class . '|' . $root->getFile() . '|' . $root->getLine()), 0, 16);
    }

    /**
     * Frames of an exception, without arguments.
     *
     * @param Throwable $e
     * @return array<int,array<string,mixed>>
     */
    private function frames(Throwable $e): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            $item = [];
            if (isset($frame['class'])) {
                $item['class'] = $frame['class'];
            }
            $item['function'] = $frame['function'] ?? '{unknown}';
            if (isset($frame['file'])) {
                $item['file'] = $frame['file'];
                $item['line'] = $frame['line'] ?? 0;
            }
            $frames[] = $item;
        }

        return $frames;
    }

    /**
     * Previous exceptions, nearest first, cycle-safe and depth-capped.
     *
     * @param Throwable $e
     * @return array<int,array<string,mixed>>
     */
    private function chain(Throwable $e): array
    {
        $chain = [];
        $seen = [spl_object_id($e) => true];
        $previous = $e->getPrevious();
        while ($previous !== null && count($chain) < $this->config->exceptionDepth()) {
            if (isset($seen[spl_object_id($previous)])) {
                break;
            }
            $seen[spl_object_id($previous)] = true;
            $chain[] = [
                'type' => $previous::class,
                'message' => $this->cut($previous->getMessage()),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
            ];
            $previous = $previous->getPrevious();
        }

        return $chain;
    }

    /**
     * Text rendering in the familiar PHP shape, without arguments and with the cut made visible.
     *
     * @param Throwable $e
     * @param array<int,array<string,mixed>> $frames
     * @param int $total frames before the cut
     * @return string
     */
    private function stacktrace(Throwable $e, array $frames, int $total): string
    {
        $lines = [sprintf('%s: %s in %s:%d', $e::class, $this->cut($e->getMessage()), $e->getFile(), $e->getLine())];
        foreach ($frames as $index => $frame) {
            $call = ($frame['class'] ?? '') === '' ? $frame['function'] : $frame['class'] . '::' . $frame['function'];
            $where = isset($frame['file']) ? sprintf('%s(%d)', $frame['file'], $frame['line']) : '[internal function]';
            $lines[] = sprintf('#%d %s: %s()', $index, $where, $call);
        }
        if ($total > count($frames)) {
            $lines[] = sprintf('... %d more frames', $total - count($frames));
        }
        $lines[] = sprintf('#%d {main}', count($frames));

        return implode("\n", $lines);
    }

    /**
     * Message cut to a sane size, valid UTF-8.
     *
     * @param string $message
     * @return string
     */
    private function cut(string $message): string
    {
        if (!mb_check_encoding($message, 'UTF-8')) {
            $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        }

        return strlen($message) <= self::MESSAGE_BYTES
            ? $message
            : mb_strcut($message, 0, self::MESSAGE_BYTES, 'UTF-8') . '…';
    }
}
