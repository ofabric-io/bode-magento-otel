<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Bode\Tracing\Api\PrivacyInterface;
use Bode\Tracing\Api\RouteNormalizerInterface;
use Bode\Tracing\Model\ClientAddressResolver;
use Bode\Tracing\Model\Environment;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * What a log line says about the unit of work it belongs to: the web request (method, path, route, client), the CLI
 * command or queue consumer, the cron job that is running, the Magento area and a correlation id.
 *
 * It reads only what is already there ($_SERVER, argv, the cron plugin's notes), computes it once per request or
 * process and never records query strings, bodies, cookies or headers other than the configured correlation headers.
 */
class ExecutionContext implements ResetAfterRequestInterface
{
    private const CORRELATION_MAX = 128;
    /** The command whose first argument names the consumer it runs. */
    private const CONSUMER_COMMAND = 'queue:consumers:start';

    /**
     * @var array<string,mixed>|null
     */
    private ?array $unit = null;

    /**
     * @var string|null
     */
    private ?string $correlationId = null;

    /**
     * @var string|null
     */
    private ?string $cronJobCode = null;

    /**
     * @var string|null
     */
    private ?string $cronScheduleId = null;

    /**
     * @param Environment $env
     * @param RouteNormalizerInterface $routes
     * @param PrivacyInterface $privacy
     * @param ClientAddressResolver $clientAddress
     * @param RequestProviderInterface $request
     * @param array<string,mixed>|null $server null reads $_SERVER
     * @param array<int,string>|null $argv null reads the process arguments
     * @param string|null $sapi null reads PHP_SAPI
     */
    public function __construct(
        private readonly Environment $env,
        private readonly RouteNormalizerInterface $routes,
        private readonly PrivacyInterface $privacy,
        private readonly ClientAddressResolver $clientAddress,
        private readonly RequestProviderInterface $request,
        private readonly ?array $server = null,
        private readonly ?array $argv = null,
        private readonly ?string $sapi = null
    ) {
    }

    /**
     * Context attributes for a log record, in a stable order.
     *
     * @return array<string,mixed>
     */
    public function attributes(): array
    {
        $attributes = [];
        $area = $this->request->area();
        if ($area !== null) {
            $attributes['magento.area'] = $area;
        }
        $unit = $this->unit();
        $attributes += $unit;
        if (!$this->isCli()) {
            $route = $this->webRoute($area, (string) ($unit['url.path'] ?? ''));
            if ($route !== null) {
                $attributes['http.route'] = $route;
            }
        }
        $attributes['correlation.id'] = $this->correlationId();
        if ($this->cronJobCode !== null) {
            $attributes['cron.job_code'] = $this->cronJobCode;
        }
        if ($this->cronScheduleId !== null) {
            $attributes['cron.schedule_id'] = $this->cronScheduleId;
        }

        return $attributes;
    }

    /**
     * Notes the cron job that is about to run (or, with null, that no job is running any more).
     *
     * @param string|null $jobCode
     * @param string|null $scheduleId
     * @return void
     */
    public function setCronJob(?string $jobCode, ?string $scheduleId = null): void
    {
        $this->cronJobCode = $jobCode !== null && $jobCode !== '' ? $jobCode : null;
        $this->cronScheduleId = $this->cronJobCode === null ? null : $scheduleId;
    }

    /**
     * Forgets everything that belonged to the finished request (long-running consumers reuse the process).
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->unit = null;
        $this->correlationId = null;
        $this->cronJobCode = null;
        $this->cronScheduleId = null;
    }

    /**
     * Whether this process is a CLI process.
     *
     * @return bool
     */
    private function isCli(): bool
    {
        return ($this->sapi ?? PHP_SAPI) === 'cli';
    }

    /**
     * Facts that do not change during a request or CLI run.
     *
     * @return array<string,mixed>
     */
    private function unit(): array
    {
        return $this->unit ??= $this->isCli() ? $this->cliFacts() : $this->webFacts();
    }

    /**
     * Method, path and client of the web request.
     *
     * @return array<string,mixed>
     */
    private function webFacts(): array
    {
        // phpcs:ignore Magento2.Security.Superglobal
        $server = $this->server ?? $_SERVER;
        $facts = ['http.request.method' => $this->method($server)];
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        if ($uri !== '') {
            $facts['url.path'] = $this->routes->path($uri);
        }
        $client = $this->privacy->clientAddress($this->clientAddress->resolve($server));
        if ($client !== null) {
            $facts['client.address'] = $client;
        }

        return $facts;
    }

    /**
     * Command and, for the consumer runner, the consumer name.
     *
     * @return array<string,mixed>
     */
    private function cliFacts(): array
    {
        // phpcs:ignore Magento2.Security.Superglobal
        $args = $this->argv ?? ($_SERVER['argv'] ?? []);
        $positional = array_values(array_filter(
            array_slice($args, 1),
            static fn ($arg): bool => is_string($arg) && $arg !== '' && !str_starts_with($arg, '-')
        ));
        $facts = [];
        if (isset($positional[0])) {
            $facts['cli.command'] = substr($positional[0], 0, 128);
            if ($positional[0] === self::CONSUMER_COMMAND && isset($positional[1])) {
                $facts['messaging.consumer'] = substr($positional[1], 0, 128);
            }
        }

        return $facts;
    }

    /**
     * Route of the web request: Magento's action name, or the REST route template for web API requests.
     *
     * @param string|null $area
     * @param string $path
     * @return string|null
     */
    private function webRoute(?string $area, string $path): ?string
    {
        if ($area === 'webapi_rest' && $path !== '') {
            return $this->routes->restTemplate($path);
        }

        return $this->request->route();
    }

    /**
     * Request method, letters only.
     *
     * @param array<string,mixed> $server
     * @return string
     */
    private function method(array $server): string
    {
        $method = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($server['REQUEST_METHOD'] ?? 'GET')) ?? 'GET');

        return $method === '' ? 'GET' : $method;
    }

    /**
     * Id that ties the lines of one request or run together.
     *
     * The first configured correlation header (a CDN or load balancer request id), else a random id per request or
     * process.
     *
     * @return string
     */
    private function correlationId(): string
    {
        if ($this->correlationId !== null) {
            return $this->correlationId;
        }
        if (!$this->isCli()) {
            // phpcs:ignore Magento2.Security.Superglobal
            $server = $this->server ?? $_SERVER;
            foreach ($this->env->list('MAGENTO_OTEL_CORRELATION_HEADERS') as $header) {
                $value = $server['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? null;
                if (is_string($value) && $value !== '') {
                    return $this->correlationId = substr($value, 0, self::CORRELATION_MAX);
                }
            }
        }

        return $this->correlationId = bin2hex(random_bytes(8));
    }
}
