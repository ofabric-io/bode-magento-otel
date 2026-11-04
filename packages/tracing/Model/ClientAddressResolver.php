<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

/**
 * Finds the client address to record.
 *
 * By default this is REMOTE_ADDR, i.e. the address the web server already resolved (Apache mod_remoteip with a
 * trusted proxy list does that before PHP runs). A forwarded header is read only when the operator opts in with
 * MAGENTO_OTEL_FORWARDED_HEADER (a header name) and the direct peer is in MAGENTO_OTEL_TRUSTED_PROXIES (addresses or
 * CIDR ranges). Anything else is ignored: a header from an untrusted peer is client-controlled.
 */
class ClientAddressResolver
{
    /**
     * @param Environment $env
     */
    public function __construct(private readonly Environment $env)
    {
    }

    /**
     * Client address to record for a request.
     *
     * @param array<string,mixed> $server $_SERVER
     * @return string empty when unknown
     */
    public function resolve(array $server): string
    {
        $peer = (string) ($server['REMOTE_ADDR'] ?? '');
        $header = $this->env->get('MAGENTO_OTEL_FORWARDED_HEADER');
        if ($header === '' || $peer === '' || !$this->isTrusted($peer)) {
            return $peer;
        }

        $value = (string) ($server['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '');
        if ($value === '') {
            return $peer;
        }

        // Walk right to left past trusted hops: the first untrusted address is the client.
        $hops = array_map('trim', explode(',', $value));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            if (filter_var($hops[$i], FILTER_VALIDATE_IP) === false) {
                return $peer;
            }
            if (!$this->isTrusted($hops[$i])) {
                return $hops[$i];
            }
        }

        return $peer;
    }

    /**
     * Whether an address belongs to a configured trusted proxy.
     *
     * @param string $address
     * @return bool
     */
    private function isTrusted(string $address): bool
    {
        foreach ($this->env->list('MAGENTO_OTEL_TRUSTED_PROXIES') as $entry) {
            if ($this->matches($address, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address is inside a single address or CIDR range.
     *
     * @param string $address
     * @param string $entry single address or CIDR
     * @return bool
     */
    private function matches(string $address, string $entry): bool
    {
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $a = inet_pton($address);
        $n = inet_pton((string) $net);
        if ($a === false || $n === false || strlen($a) !== strlen($n)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($n, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($n[$bytes]) & $mask);
    }
}
