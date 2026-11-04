<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

/**
 * Read-only view of the process environment. Injectable so tests need no putenv(): this module never writes to
 * the environment.
 */
class Environment
{
    /**
     * @param array<string,string>|null $values null reads the real process environment
     */
    public function __construct(private readonly ?array $values = null)
    {
    }

    /**
     * Value of an environment variable.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public function get(string $name, string $default = ''): string
    {
        if ($this->values !== null) {
            return isset($this->values[$name]) && trim($this->values[$name]) !== ''
                ? trim($this->values[$name])
                : $default;
        }
        // The process environment is the module's configuration source by design (docs: env only).
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $value = getenv($name);

        return $value === false || trim($value) === '' ? $default : trim($value);
    }

    /**
     * Comma separated list, trimmed, empty items dropped.
     *
     * @param string $name
     * @return string[]
     */
    public function list(string $name): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $this->get($name))),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * Integer environment variable.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    public function int(string $name, int $default): int
    {
        $value = $this->get($name);

        return $value !== '' && ctype_digit($value) ? (int) $value : $default;
    }
}
