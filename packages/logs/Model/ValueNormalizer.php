<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns anything a caller can put in a PSR-3 context into JSON-safe data: bounded in depth, width and string size,
 * valid UTF-8, no resources, no recursion. It records what a value is; it never changes what the caller said.
 */
class ValueNormalizer
{
    private const MAX_ITEMS = 100;

    /**
     * @param Config $config
     * @param ExceptionNormalizer $exceptions
     */
    public function __construct(private readonly Config $config, private readonly ExceptionNormalizer $exceptions)
    {
    }

    /**
     * JSON-safe copy of a value.
     *
     * @param mixed $value
     * @param int|null $depth remaining nesting depth, null for the configured depth
     * @return mixed
     */
    public function normalize(mixed $value, ?int $depth = null): mixed
    {
        return $this->walk($value, $depth ?? $this->config->contextDepth(), []);
    }

    /**
     * Valid UTF-8, cut to the configured length with a marker that says how much was dropped.
     *
     * @param string $value
     * @param int|null $maxBytes
     * @return string
     */
    public function string(string $value, ?int $maxBytes = null): string
    {
        $maxBytes ??= $this->config->maxValueBytes();
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $length = strlen($value);
        if ($length <= $maxBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maxBytes, 'UTF-8') . sprintf('…[%d bytes dropped]', $length - $maxBytes);
    }

    /**
     * Recursive worker.
     *
     * @param mixed $value
     * @param int $depth
     * @param array<int,true> $seen object ids on the current path
     * @return mixed
     */
    private function walk(mixed $value, int $depth, array $seen): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            // PHP 8.5 warns when NAN or INF are coerced to string, so name them explicitly.
            return is_finite($value) ? $value : (is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF'));
        }
        if (is_string($value)) {
            return $this->string($value);
        }
        if (is_array($value)) {
            return $this->walkArray($value, $depth, $seen);
        }
        if (is_object($value)) {
            return $this->walkObject($value, $depth, $seen);
        }

        return sprintf('[%s]', get_debug_type($value));
    }

    /**
     * Arrays: bounded width, keys kept.
     *
     * @param array<mixed> $value
     * @param int $depth
     * @param array<int,true> $seen
     * @return mixed
     */
    private function walkArray(array $value, int $depth, array $seen): mixed
    {
        if ($depth <= 0) {
            return sprintf('[array(%d), depth limit]', count($value));
        }
        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > self::MAX_ITEMS) {
                $result['…'] = sprintf('%d more items', count($value) - self::MAX_ITEMS);
                break;
            }
            $result[is_string($key) ? $this->string($key, 256) : $key] = $this->walk($item, $depth - 1, $seen);
        }

        return $result;
    }

    /**
     * Objects: Throwables as exception summaries, value objects by their natural form, the rest by class name.
     *
     * @param object $value
     * @param int $depth
     * @param array<int,true> $seen
     * @return mixed
     */
    private function walkObject(object $value, int $depth, array $seen): mixed
    {
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            return sprintf('[recursion: %s]', $value::class);
        }
        $seen[$id] = true;

        return match (true) {
            $value instanceof Throwable => $this->exceptions->summary($value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof JsonSerializable => $this->walk($value->jsonSerialize(), $depth - 1, $seen),
            $value instanceof Stringable => $this->string((string) $value),
            default => ['class' => $value::class],
        };
    }
}
