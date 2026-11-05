<?php

declare(strict_types=1);

/**
 * Ported from packages/logs/Test/Unit/Support/MiniJsonSchema.php (plain functions here, matching the style of
 * e2e/lib/otlp.php and e2e/lib/trace.php, which are function libraries, not classes). Deliberately the same hand
 * written subset of JSON Schema (type, required, properties, pattern, enum, items) so the real schema file
 * (packages/logs/schema/log-record.schema.json) is enforced against real formatter output on a real Magento process,
 * without adding a schema-validation Composer dependency (AGENTS.md: allowed deps are tightly scoped). Unknown
 * keywords are ignored; unknown properties are allowed (the schema is intentionally permissive about additional
 * attributes).
 */

/**
 * Validation errors of a value against a schema, empty when valid.
 *
 * @param mixed $value
 * @param array<string,mixed> $schema
 * @param string $path
 * @return string[]
 */
function jsonSchemaErrors(mixed $value, array $schema, string $path = '$'): array
{
    $errors = [];
    if (isset($schema['type']) && !jsonSchemaTypeMatches($value, $schema['type'])) {
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
                array_push($errors, ...jsonSchemaErrors($value[$key], $sub, $path . '.' . $key));
            }
        }
    }
    if (is_array($value) && isset($schema['items'])) {
        foreach ($value as $index => $item) {
            array_push($errors, ...jsonSchemaErrors($item, $schema['items'], $path . '[' . $index . ']'));
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
function jsonSchemaTypeMatches(mixed $value, string|array $types): bool
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

/**
 * Loads a JSON Schema document from disk.
 *
 * @param string $path
 * @return array<string,mixed>
 */
function loadJsonSchema(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fail("could not parse JSON schema at $path");
    }

    return $decoded;
}
