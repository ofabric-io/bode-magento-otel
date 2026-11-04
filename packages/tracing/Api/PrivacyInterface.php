<?php

declare(strict_types=1);

namespace Bode\Tracing\Api;

/**
 * Applies the operator's privacy modes (MAGENTO_OTEL_IP_MODE, MAGENTO_OTEL_USER_MODE) to personal data before it
 * is attached to telemetry. Returns null when the value must not be recorded.
 *
 * @api
 */
interface PrivacyInterface
{
    /**
     * Client address as it may be recorded under the configured mode.
     *
     * @param string $address IPv4 or IPv6 address as resolved by the web server
     * @return string|null
     */
    public function clientAddress(string $address): ?string;

    /**
     * User id as it may be recorded under the configured mode.
     *
     * @param int|string $id customer or admin user id (never email or name)
     * @return string|null
     */
    public function userId(int|string $id): ?string;

    /**
     * Keyed hash of the session id; the raw id is never returned.
     *
     * @param string $sessionId
     * @return string|null
     */
    public function sessionHash(string $sessionId): ?string;

    /**
     * User agent, truncated.
     *
     * @param string $userAgent
     * @return string
     */
    public function userAgent(string $userAgent): string;
}
