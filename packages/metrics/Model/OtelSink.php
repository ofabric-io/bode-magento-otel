<?php

declare(strict_types=1);

namespace Bode\Metrics\Model;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;

/**
 * SinkInterface over the OpenTelemetry metrics API. Instruments are created on first use. Temporality (delta for
 * short-lived PHP processes) is chosen by the standard OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE variable.
 */
class OtelSink implements SinkInterface
{
    public const SCOPE = 'bode.magento';

    /**
     * @var array<string,object>
     */
    private array $instruments = [];

    /**
     * @var MeterInterface|null
     */
    private ?MeterInterface $meter = null;

    /**
     * @inheritdoc
     */
    public function add(string $name, int|float $value, array $attributes): void
    {
        $this->instrument($name)->add($value, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function record(string $name, float $value, array $attributes): void
    {
        $this->instrument($name)->record($value, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function gauge(string $name, int|float $value, array $attributes): void
    {
        $this->instrument($name)->record($value, $attributes);
    }

    /**
     * @inheritdoc
     */
    public function flush(): void
    {
        $provider = Globals::meterProvider();
        if ($provider instanceof MeterProviderInterface) {
            $provider->forceFlush();
        }
    }

    /**
     * Creates the instrument on first use.
     *
     * @param string $name
     * @return object
     */
    private function instrument(string $name): object
    {
        if (isset($this->instruments[$name])) {
            return $this->instruments[$name];
        }
        [$type, $unit, $description] = InstrumentCatalog::INSTRUMENTS[$name];
        $meter = $this->meter ??= Globals::meterProvider()->getMeter(self::SCOPE);

        return $this->instruments[$name] = match ($type) {
            InstrumentCatalog::COUNTER => $meter->createCounter($name, $unit, $description),
            InstrumentCatalog::GAUGE => $meter->createGauge($name, $unit, $description),
            default => $meter->createHistogram(
                $name,
                $unit,
                $description,
                ['ExplicitBucketBoundaries' => InstrumentCatalog::SECONDS_BUCKETS]
            ),
        };
    }
}
