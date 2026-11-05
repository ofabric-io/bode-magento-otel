<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

/**
 * The real PHP controller/service class and the resolved action name (Magento's action name, or a REST service
 * method) for the current request. Written by the hook layer (HttpInstrumentation) the moment a controller or REST
 * route is resolved, read by code created by dependency injection (Bode_Logs) through TelemetryStateInterface.
 *
 * Reset at the start of every request (see HttpInstrumentation::begin()): a process can serve many requests, and
 * this must never leak from one request into the next.
 */
class ResolvedAction
{
    /**
     * @var string|null
     */
    private ?string $controllerClass = null;

    /**
     * @var string|null
     */
    private ?string $actionName = null;

    /**
     * Notes the real class Magento (or the REST router) instantiated to handle the request.
     *
     * @param string|null $class
     * @return void
     */
    public function setControllerClass(?string $class): void
    {
        $this->controllerClass = $class !== null && $class !== '' ? $class : null;
    }

    /**
     * Notes the resolved action name (Magento's action name, or a REST service method).
     *
     * @param string|null $action
     * @return void
     */
    public function setActionName(?string $action): void
    {
        $this->actionName = $action !== null && $action !== '' ? $action : null;
    }

    /**
     * Real class of the resolved controller or REST service, if any.
     *
     * @return string|null
     */
    public function controllerClass(): ?string
    {
        return $this->controllerClass;
    }

    /**
     * Resolved action name, if any.
     *
     * @return string|null
     */
    public function actionName(): ?string
    {
        return $this->actionName;
    }

    /**
     * Forgets what the previous request resolved.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->controllerClass = null;
        $this->actionName = null;
    }
}
