<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Bode\Tracing\Api\TraceContextProviderInterface;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\TraceContextProvider;
use Throwable;

/**
 * The failures Magento's logger never sees: an uncaught exception or a fatal error (out of memory, parse error, a
 * TypeError nobody caught) ends the process before any handler runs, and PHP writes them as plain text to its own log.
 * This writes the same JSON record, straight to stderr, with no container, no Monolog and nothing that could fail the
 * same way: it only needs what it builds itself.
 *
 * It is registered from registration.php, chains whatever exception handler was there before, and is a no-op unless
 * Bode_Logs is enabled.
 */
class FatalErrorReporter
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR;
    private const RESERVE_BYTES = 32768;

    /**
     * @var callable|null
     */
    private $previousHandler = null;

    /**
     * @var string|null
     */
    // @phpstan-ignore property.onlyWritten (held only to be freed at shutdown)
    private ?string $reserve;

    /**
     * @var bool
     */
    private bool $reported = false;

    /**
     * @var resource|null
     */
    private $stream = null;

    /**
     * @param Config $config
     * @param ResourceAttributes $resource
     * @param ExceptionNormalizer $exceptions
     * @param TraceContextProviderInterface $trace
     * @param string $target where the line goes
     */
    public function __construct(
        private readonly Config $config,
        private readonly ResourceAttributes $resource,
        private readonly ExceptionNormalizer $exceptions,
        private readonly TraceContextProviderInterface $trace,
        private readonly string $target = 'php://stderr'
    ) {
        // Memory to work with when the process died of exhausting its own.
        $this->reserve = str_repeat('x', self::RESERVE_BYTES);
    }

    /**
     * Registers the reporter for this process. Called once from registration.php; safe to call again.
     *
     * @return void
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- called from registration.php, before any container exists
    public static function register(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $env = new Environment();
        $config = new Config($env);
        if (!$config->isFatalReporterEnabled()) {
            return;
        }
        $reporter = new self(
            $config,
            new ResourceAttributes($env),
            new ExceptionNormalizer($config),
            new TraceContextProvider()
        );
        $reporter->install();
    }

    /**
     * Installs the exception handler and the shutdown function.
     *
     * @return void
     */
    public function install(): void
    {
        $this->previousHandler = set_exception_handler([$this, 'onException']);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the whole point: failures the logger never sees
        register_shutdown_function([$this, 'onShutdown']);
    }

    /**
     * Uncaught exception: report it as a structured record, then let PHP do what it would have done.
     *
     * @param Throwable $e
     * @return void
     * @throws Throwable the same exception again when there is no previous handler, so PHP still ends the process
     *         with its usual fatal error and exit code
     */
    public function onException(Throwable $e): void
    {
        if (!$this->reported) {
            try {
                $this->write($this->exceptionRecord($e));
                $this->reported = true;
            } catch (Throwable) {
                // Reporting must never replace the original failure.
                $this->reported = false;
            }
        }
        if ($this->previousHandler !== null) {
            ($this->previousHandler)($e);

            return;
        }
        // Nothing was installed before us: let PHP end the process the way it would have. While a handler runs, PHP has
        // none set, and restore_exception_handler() here would install this very handler again (and loop), so the
        // handler is cleared instead before the exception goes out once more.
        set_exception_handler(null);
        throw $e;
    }

    /**
     * At shutdown: report a fatal error that no handler saw.
     *
     * @return void
     */
    public function onShutdown(): void
    {
        $this->reserve = null;
        if ($this->reported) {
            return;
        }
        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }
        try {
            $this->write($this->errorRecord($error));
        } catch (Throwable) {
            // Nothing is left to do at shutdown.
            $this->reported = false;
        }
    }

    /**
     * The JSON record of an uncaught exception.
     *
     * @param Throwable $e
     * @return array<string,mixed>
     */
    public function exceptionRecord(Throwable $e): array
    {
        return $this->record(
            'Uncaught ' . $e::class . ': ' . $e->getMessage(),
            ['log.channel' => 'php.uncaught'] + $this->exceptions->attributes($e)
        );
    }

    /**
     * The JSON record of a fatal error.
     *
     * @param array<string,mixed> $error as error_get_last() returns it: type, message, file, line
     * @return array<string,mixed>
     */
    public function errorRecord(array $error): array
    {
        return $this->record($error['message'], [
            'log.channel' => 'php.fatal',
            'error.type' => $this->errorName($error['type']),
            'code.file.path' => $error['file'],
            'code.line.number' => $error['line'],
            'error.fingerprint' => substr(sha1($error['type'] . '|' . $error['file'] . '|' . $error['line']), 0, 16),
        ]);
    }

    /**
     * Common shape of both records.
     *
     * @param string $body
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function record(string $body, array $attributes): array
    {
        $micros = (int) (fmod(microtime(true), 1) * 1000000);
        $record = [
            'timestamp' => sprintf('%s.%06dZ', gmdate('Y-m-d\TH:i:s'), $micros),
            'severity_text' => 'CRITICAL',
            'severity_number' => 21,
            'body' => mb_strcut($body, 0, $this->config->maxValueBytes(), 'UTF-8'),
        ];
        $trace = $this->trace->current();
        if ($trace !== null) {
            $record += $trace;
        }
        $record['resource'] = $this->resource->all();
        $record['attributes'] = $attributes;

        return $record;
    }

    /**
     * Writes one line.
     *
     * @param array<string,mixed> $record
     * @return void
     */
    private function write(array $record): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($record, $flags);
        if ($json === false) {
            return;
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $this->stream ??= fopen($this->target, 'ab') ?: null;
        if ($this->stream !== null) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fwrite($this->stream, $json . "\n");
        }
    }

    /**
     * Name of an E_* constant.
     *
     * @param int $type
     * @return string
     */
    private function errorName(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            default => 'E_' . $type,
        };
    }
}
