<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Support;

/**
 * The few JSON Schema keywords schema/log-record.schema.json uses (type, required, properties, pattern, enum,
 * items), so the shipped schema is enforced against real formatter output without a validator dependency. Unknown
 * keywords are ignored; unknown properties are allowed.
 */
class MiniJsonSchema
{
    /**
     * Validation errors of a value against a schema, empty when valid.
     *
     * @param mixed $value
     * @param array<string,mixed> $schema
     * @param string $path
     * @return string[]
     */
    public static function errors(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        if (isset($schema['type']) && !self::typeMatches($value, $schema['type'])) {
            return [sprintf('%s: expected %s, got %s', $path, json_encode($schema['type']), get_debug_type($value))];
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf('%s: %s is not one of %s', $path, json_encode($value), json_encode($schema['enum']));
        }
        if (isset($schema['pattern']) && is_string($value) && !preg_match('/' . $schema['pattern'] . '/', $value)) {
            $errors[] = sprintf('%s: "%s" does not match %s', $path, $value, $schema['pattern']);
        }
        if (is_array($value) && isset($schema['required'])) {
            foreach ($schema['required'] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = sprintf('%s: missing required "%s"', $path, $key);
                }
            }
        }
        if (is_array($value) && isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    array_push($errors, ...self::errors($value[$key], $sub, $path . '.' . $key));
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($errors, ...self::errors($item, $schema['items'], $path . '[' . $index . ']'));
            }
        }

        return $errors;
    }

    /**
     * Whether a value has one of the JSON types.
     *
     * @param mixed $value
     * @param string|string[] $types
     * @return bool
     */
    private static function typeMatches(mixed $value, string|array $types): bool
    {
        foreach ((array) $types as $type) {
            $ok = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'null' => $value === null,
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
