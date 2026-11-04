<?php

declare(strict_types=1);

namespace Bode\Metrics\Hook;

use Bode\Metrics\Model\Recorder;
use Bode\Tracing\Api\RouteNormalizerInterface;
use Bode\Tracing\Hook\HookRegistrarInterface;
use Bode\Tracing\Hook\Runtime;
use Bode\Tracing\Model\Environment;
use Closure;
use Throwable;

/**
 * What every metrics instrumentation needs: the recorder, the hook registrar, the environment and the switches.
 * Also times calls (pre hook stores the start, post hook records) and keeps failures from ever propagating.
 */
class MetricsContext
{
    public const DEFAULT_GROUPS = ['http', 'cron', 'indexer', 'cache', 'queue', 'outbound', 'commerce', 'telemetry'];
    public const OPT_IN_GROUPS = ['db', 'redis', 'cache_ops'];

    /**
     * @var float[]
     */
    private array $starts = [];

    /**
     * @var string[]
     */
    private array $groups;

    /**
     * @var int
     */
    private int $reportedDropped = 0;

    /**
     * @var array<string,int>
     */
    private array $reportedErrors = [];

    /**
     * @param Recorder $recorder
     * @param HookRegistrarInterface $registrar
     * @param Environment $env
     * @param RouteNormalizerInterface $routes
     */
    public function __construct(
        public readonly Recorder $recorder,
        public readonly HookRegistrarInterface $registrar,
        public readonly Environment $env,
        public readonly RouteNormalizerInterface $routes
    ) {
        $requested = $env->list('MAGENTO_OTEL_METRICS_GROUPS');
        $this->groups = $requested === []
            ? self::DEFAULT_GROUPS
            : array_values(array_intersect(
                $requested === ['all'] ? [...self::DEFAULT_GROUPS, ...self::OPT_IN_GROUPS] : $requested,
                [...self::DEFAULT_GROUPS, ...self::OPT_IN_GROUPS]
            ));
    }

    /**
     * Whether a metrics group is switched on (MAGENTO_OTEL_METRICS_GROUPS).
     *
     * @param string $group
     * @return bool
     */
    public function isGroupEnabled(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    /**
     * Times class::method and hands the duration to $record.
     *
     * @param string $group
     * @param string $class
     * @param string $method
     * @param Closure $record function (float $seconds, ?object $subject, array $params, mixed $ret, ?Throwable $e)
     * @return void
     */
    public function timed(string $group, string $class, string $method, Closure $record): void
    {
        if (!$this->isGroupEnabled($group)) {
            return;
        }
        $this->registrar->register(
            $class,
            $method,
            function (): ?array {
                $this->starts[] = hrtime(true) / 1e9;

                return null;
            },
            function (?object $subject, array $params, mixed $ret, ?Throwable $error) use ($record): void {
                $start = array_pop($this->starts);
                if ($start === null) {
                    return;
                }
                try {
                    $record(hrtime(true) / 1e9 - $start, $subject, $params, $ret, $error);
                } catch (Throwable) {
                    // Metrics must never break the application; Recorder counts sink failures itself.
                    return;
                }
            }
        );
    }

    /**
     * Runs $fn after class::method returns, without timing.
     *
     * @param string $group
     * @param string $class
     * @param string $method
     * @param Closure $fn function (?object $subject, array $params, mixed $ret, ?Throwable $e): void
     * @return void
     */
    public function after(string $group, string $class, string $method, Closure $fn): void
    {
        if (!$this->isGroupEnabled($group)) {
            return;
        }
        $this->registrar->register(
            $class,
            $method,
            null,
            function (?object $subject, array $params, mixed $ret, ?Throwable $error) use ($fn): void {
                try {
                    $fn($subject, $params, $ret, $error);
                } catch (Throwable) {
                    return;
                }
            }
        );
    }

    /**
     * Reports what Bode_Tracing swallowed or dropped since the last report.
     *
     * Only the deltas are counted, so a broken hook shows up in metrics and not only in a debug log.
     *
     * @return void
     */
    public function reportSelf(): void
    {
        $tracing = Runtime::get();
        if ($tracing === null || !$this->isGroupEnabled('telemetry')) {
            return;
        }
        $dropped = $tracing->budget->droppedTotal();
        if ($dropped > $this->reportedDropped) {
            $this->recorder->count('bode.telemetry.spans.dropped', [], $dropped - $this->reportedDropped);
            $this->reportedDropped = $dropped;
        }
        foreach ($tracing->errors->counts() as $group => $total) {
            $delta = $total - ($this->reportedErrors[$group] ?? 0);
            if ($delta > 0) {
                $this->recorder->count('bode.telemetry.errors', ['group' => $group], $delta);
                $this->reportedErrors[$group] = $total;
            }
        }
    }

    /**
     * Label-safe, bounded value.
     *
     * @param mixed $value
     * @return string
     */
    public function label(mixed $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $value), 0, 100) ?: 'unknown';
    }
}
