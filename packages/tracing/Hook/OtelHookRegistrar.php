<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Closure;
use Throwable;

/**
 * Registers hooks with the OpenTelemetry PHP extension. A no-op when the extension is not loaded.
 */
class OtelHookRegistrar implements HookRegistrarInterface
{
    /**
     * @inheritdoc
     */
    public function register(string $class, string $method, ?Closure $pre, ?Closure $post): void
    {
        if (!function_exists('OpenTelemetry\Instrumentation\hook')) {
            return;
        }

        try {
            \OpenTelemetry\Instrumentation\hook(
                $class,
                $method,
                $pre === null ? null : static function (
                    ?object $subject,
                    array $params
                ) use ($pre): ?array {
                    return $pre($subject, $params);
                },
                $post === null ? null : static function (
                    ?object $subject,
                    array $params,
                    mixed $returnValue,
                    ?Throwable $exception
                ) use ($post): void {
                    $post($subject, $params, $returnValue, $exception);
                }
            );
        } catch (Throwable) {
            // A class that does not exist in this Magento version must never break the application.
            return;
        }
    }
}
