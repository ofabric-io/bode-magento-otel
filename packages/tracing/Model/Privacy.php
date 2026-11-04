<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

use Bode\Tracing\Api\PrivacyInterface;

/**
 * Privacy modes for personal data in telemetry.
 *
 * MAGENTO_OTEL_IP_MODE    full | truncate | hash | off   (default truncate: /24 for IPv4, /48 for IPv6)
 * MAGENTO_OTEL_USER_MODE  id | hash | off                (default id; also accepts full, truncate as id)
 * MAGENTO_OTEL_HASH_SECRET secret for the keyed hashes; without it hash modes record nothing
 *
 * "full" sends the visitor's IP address to the telemetry backend. That is personal data leaving the server: the
 * operator decides region, retention and the data processing agreement before enabling it.
 */
class Privacy implements PrivacyInterface
{
    public const MODES = ['full', 'truncate', 'hash', 'off'];
    private const UA_MAX = 120;

    /**
     * @param Environment $env
     */
    public function __construct(private readonly Environment $env)
    {
    }

    /**
     * @inheritdoc
     */
    public function clientAddress(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return match ($this->mode('MAGENTO_OTEL_IP_MODE', 'truncate')) {
            'full' => $address,
            'hash' => $this->hash($address),
            'off' => null,
            default => $this->truncate($address),
        };
    }

    /**
     * @inheritdoc
     */
    public function userId(int|string $id): ?string
    {
        $id = (string) $id;
        if ($id === '' || $id === '0') {
            return null;
        }

        return match ($this->mode('MAGENTO_OTEL_USER_MODE', 'id')) {
            'off' => null,
            'hash' => $this->hash($id),
            default => $id,
        };
    }

    /**
     * @inheritdoc
     */
    public function sessionHash(string $sessionId): ?string
    {
        return $sessionId === '' ? null : $this->hash($sessionId);
    }

    /**
     * @inheritdoc
     */
    public function userAgent(string $userAgent): string
    {
        return function_exists('mb_substr')
            ? mb_substr($userAgent, 0, self::UA_MAX, 'UTF-8')
            : substr($userAgent, 0, self::UA_MAX);
    }

    /**
     * Privacy mode from the environment, falling back to the default when unknown.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    private function mode(string $name, string $default): string
    {
        $mode = strtolower($this->env->get($name, $default));

        return in_array($mode, [...self::MODES, 'id'], true) ? $mode : $default;
    }

    /**
     * Keyed hash, or null without a secret.
     *
     * @param string $value
     * @return string|null null when no secret is configured: an unkeyed hash of an IP or id is reversible
     */
    private function hash(string $value): ?string
    {
        $secret = $this->env->get('MAGENTO_OTEL_HASH_SECRET');
        if ($secret === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $value, $secret), 0, 16);
    }

    /**
     * Zeroes the host part of an address.
     *
     * @param string $address
     * @return string
     */
    private function truncate(string $address): string
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return '';
        }
        $keep = strlen($packed) === 4 ? 3 : 6;
        $packed = substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep);

        return (string) inet_ntop($packed);
    }
}
