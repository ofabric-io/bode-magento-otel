<?php

declare(strict_types=1);

namespace Bode\Logs\Model\Formatter;

use Bode\Logs\Model\Config;
use Bode\Logs\Model\Processor\StructuredContextProcessor;
use Bode\Logs\Model\RecordAssembler;
use Bode\Logs\Model\Severity;
use Bode\Logs\Model\ValueNormalizer;
use DateTimeZone;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Throwable;

/**
 * One JSON object per line, shaped like an OpenTelemetry log record (see schema/log-record.schema.json):
 * timestamp, severity_text, severity_number, body, trace_id, span_id, trace_flags, resource and attributes.
 *
 * It never throws and never writes a line that is not valid JSON: whatever goes wrong while formatting ends in a
 * minimal record that says so, because a lost log line is the one nobody can debug.
 */
class JsonLineFormatter implements FormatterInterface
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    private const FALLBACK_BODY_BYTES = 1024;

    /**
     * @var DateTimeZone|null
     */
    private static ?DateTimeZone $utc = null;

    /**
     * @param Config $config
     * @param RecordAssembler $assembler
     * @param ValueNormalizer $values
     */
    public function __construct(
        private readonly Config $config,
        private readonly RecordAssembler $assembler,
        private readonly ValueNormalizer $values
    ) {
    }

    /**
     * Formats one record as a JSON line (with its newline).
     *
     * @param LogRecord $record
     * @return string
     */
    public function format(LogRecord $record): string
    {
        try {
            return $this->build($record);
        } catch (Throwable $e) {
            return $this->fallback($record, $e);
        }
    }

    /**
     * Formats several records, one line each.
     *
     * @param array<int,LogRecord> $records
     * @return string
     */
    public function formatBatch(array $records): string
    {
        $out = '';
        foreach ($records as $record) {
            $out .= $this->format($record);
        }

        return $out;
    }

    /**
     * The full line.
     *
     * @param LogRecord $record
     * @return string
     */
    private function build(LogRecord $record): string
    {
        $parts = $record->extra[StructuredContextProcessor::EXTRA_KEY] ?? $this->assembler->assemble($record);

        $line = [
            'timestamp' => $this->timestamp($record),
            'severity_text' => Severity::text($record->level),
            'severity_number' => Severity::number($record->level),
            'body' => $this->values->string($record->message),
        ];
        if ($parts['trace'] !== null) {
            $line += $parts['trace'];
        }
        $line['resource'] = $parts['resource'];
        $line['attributes'] = $parts['attributes'];

        return $this->fit($line);
    }

    /**
     * Encodes the line and, if it is over the limit, sheds the least valuable parts first: structured frames (the
     * stacktrace text stays), then the stacktrace itself, then context and extra, then the body, then everything but
     * the basics. Whatever was shed is named in log.truncated.
     *
     * @param array<string,mixed> $line
     * @return string
     */
    private function fit(array $line): string
    {
        $max = $this->config->maxLineBytes();
        $json = $this->encode($line);
        if (strlen($json) + 1 <= $max) {
            return $json . "\n";
        }

        $steps = [
            'exception.frames' => static function (array &$line): void {
                unset($line['attributes']['exception.frames']);
            },
            'exception.stacktrace' => function (array &$line) use ($max): void {
                if (isset($line['attributes']['exception.stacktrace'])) {
                    $line['attributes']['exception.stacktrace'] =
                        $this->values->string((string) $line['attributes']['exception.stacktrace'], intdiv($max, 4));
                }
            },
            'context' => static function (array &$line): void {
                foreach (['context', 'extra'] as $key) {
                    if (isset($line['attributes'][$key])) {
                        $line['attributes'][$key] = ['dropped_keys' => array_keys((array) $line['attributes'][$key])];
                    }
                }
            },
            'body' => function (array &$line) use ($max): void {
                $line['body'] = $this->values->string((string) $line['body'], intdiv($max, 4));
            },
            'attributes' => static function (array &$line): void {
                $keep = ['log.channel', 'code.namespace', 'code.function', 'exception.type', 'exception.message',
                    'error.fingerprint', 'correlation.id'];
                $line['attributes'] = array_intersect_key($line['attributes'], array_flip($keep));
                $line['resource'] = array_intersect_key($line['resource'], array_flip(['service.name', 'host.name']));
            },
        ];
        $shed = [];
        foreach ($steps as $name => $step) {
            $step($line);
            $shed[] = $name;
            $line['attributes']['log.truncated'] = $shed;
            $json = $this->encode($line);
            if (strlen($json) + 1 <= $max) {
                return $json . "\n";
            }
        }

        return $this->fallback(null, null, $line);
    }

    /**
     * JSON encoding that survives bad data.
     *
     * @param array<string,mixed> $line
     * @return string
     */
    private function encode(array $line): string
    {
        $json = json_encode($line, self::FLAGS);

        return $json === false ? '' : $json;
    }

    /**
     * ISO 8601 UTC with microseconds.
     *
     * @param LogRecord $record
     * @return string
     */
    private function timestamp(LogRecord $record): string
    {
        self::$utc ??= new DateTimeZone('UTC');

        return $record->datetime->setTimezone(self::$utc)->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Minimal valid line for a record that could not be formatted, or whose line stayed over the limit.
     *
     * @param LogRecord|null $record
     * @param Throwable|null $error
     * @param array<string,mixed>|null $line the oversized line, when that is the reason
     * @return string
     */
    private function fallback(?LogRecord $record, ?Throwable $error, ?array $line = null): string
    {
        $timestamp = $line['timestamp']
            ?? ($record !== null ? $this->timestamp($record) : gmdate('Y-m-d\TH:i:s') . '.000000Z');
        $text = $line['severity_text'] ?? ($record !== null ? Severity::text($record->level) : 'ERROR');
        $number = $line['severity_number'] ?? ($record !== null ? Severity::number($record->level) : 17);
        $body = (string) ($line['body'] ?? ($record !== null ? $record->message : ''));
        $minimal = [
            'timestamp' => $timestamp,
            'severity_text' => $text,
            'severity_number' => $number,
            'body' => $this->values->string($body, self::FALLBACK_BODY_BYTES),
            'resource' => ['service.name' => $line['resource']['service.name'] ?? 'magento'],
            'attributes' => [
                'log.channel' => $record !== null
                    ? $record->channel
                    : ($line['attributes']['log.channel'] ?? 'unknown'),
                'bode.logs.error' => $error !== null
                    ? $this->values->string($error::class . ': ' . $error->getMessage(), self::FALLBACK_BODY_BYTES)
                    : 'record exceeded the maximum line size',
            ],
        ];
        $json = json_encode($minimal, self::FLAGS);

        return ($json === false ? '{"severity_text":"ERROR","body":"log formatting failed"}' : $json) . "\n";
    }
}
