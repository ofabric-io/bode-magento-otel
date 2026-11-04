<?php

declare(strict_types=1);

namespace Bode\Tracing\Instrumentation;

use Symfony\Component\Console\Command\Command;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Hook\SpanHandleInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * bin/magento commands: one root span per command named "cli <command>". The long-running processes cron:run and
 * queue:consumers:start get no span of their own: their jobs and messages are traced individually.
 */
class CliInstrumentation implements InstrumentationInterface
{
    private const COMMAND = Command::class;
    private const UNTRACED = ['cron:run', 'queue:consumers:start', 'list', 'help', '_complete'];

    /**
     * @inheritdoc
     */
    public function register(Runtime $runtime): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $runtime->instrumenter->span(
            'cli',
            self::COMMAND,
            'run',
            function (?object $subject) use ($runtime): ?SpanHandleInterface {
                $name = $subject !== null && method_exists($subject, 'getName') ? (string) $subject->getName() : '';
                if ($name === '' || in_array($name, self::UNTRACED, true)) {
                    return null;
                }
                $name = substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $name), 0, 100);

                return $runtime->spans->start(
                    'cli ' . $name,
                    SpanKind::KIND_INTERNAL,
                    ['bode.magento.cli.command' => $name]
                );
            },
            static function (SpanHandleInterface $span, ?object $subject, array $params, mixed $exit): void {
                if (is_int($exit)) {
                    $span->setAttribute('process.exit.code', $exit);
                    if ($exit !== 0) {
                        $span->markError('exit_' . $exit);
                    }
                }
            },
            true
        );
    }
}
