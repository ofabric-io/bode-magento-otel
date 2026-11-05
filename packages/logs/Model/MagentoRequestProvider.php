<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Bode\Tracing\Api\RouteNormalizerInterface;
use Bode\Tracing\Api\TelemetryStateInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Throwable;

/**
 * Area and route from Magento's State and request. Both arrive as Proxies, because the logger is built before the
 * application, and every lookup is guarded: logging must work in the middle of a bootstrap failure, when neither
 * exists.
 *
 * The resource name matches Bode_Tracing's span name exactly (same RouteNormalizerInterface); the real controller
 * class and resolved action name are read through TelemetryStateInterface, the bridge Bode_Tracing exposes for code
 * built by dependency injection, since only the hook layer ever sees the instantiated controller.
 */
class MagentoRequestProvider implements RequestProviderInterface
{
    /** Magento's own marker for a request forwarded to its "no route" (404) handler (App\Router\Base::NO_ROUTE). */
    private const NO_ROUTE_ACTION = 'noroute';

    /**
     * @param State $state
     * @param RequestInterface $request
     * @param RouteNormalizerInterface $routes
     * @param TelemetryStateInterface $telemetry
     */
    public function __construct(
        private readonly State $state,
        private readonly RequestInterface $request,
        private readonly RouteNormalizerInterface $routes,
        private readonly TelemetryStateInterface $telemetry
    ) {
    }

    /**
     * @inheritdoc
     */
    public function area(): ?string
    {
        try {
            return $this->state->getAreaCode();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function route(): ?string
    {
        try {
            // getRouteName()/getControllerName() are not declared on RequestInterface (only on the concrete Http
            // request); this is the real guard, unlike the "always true" getActionName() check this replaced.
            if (!method_exists($this->request, 'getRouteName') || !method_exists($this->request, 'getControllerName')) {
                return null;
            }
            $routeName = (string) $this->request->getRouteName();
            if ($routeName === '') {
                return null;
            }
            if ($this->isNoRoute()) {
                // The controller segment of a 404 is whatever the visitor typed (unbounded); only the front name is
                // validated against a known module before Magento falls back to noroute.
                return $this->routes->resourceName($routeName, self::NO_ROUTE_ACTION, self::NO_ROUTE_ACTION);
            }
            $controllerName = (string) $this->request->getControllerName();
            $actionName = (string) $this->request->getActionName();
            $resource = $this->routes->resourceName($routeName, $controllerName, $actionName);

            return $resource !== 'unknown' ? $resource : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function isNoRoute(): bool
    {
        try {
            return (string) $this->request->getActionName() === self::NO_ROUTE_ACTION;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function controllerClass(): ?string
    {
        return $this->telemetry->resolvedControllerClass();
    }

    /**
     * @inheritdoc
     */
    public function magentoAction(): ?string
    {
        return $this->telemetry->resolvedActionName();
    }
}
