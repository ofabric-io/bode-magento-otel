<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Support;

use Bode\Logs\Model\RequestProviderInterface;

class FakeRequestProvider implements RequestProviderInterface
{
    /**
     * @param string|null $area
     * @param string|null $route
     * @param bool $noRoute
     * @param string|null $controllerClass
     * @param string|null $magentoAction
     */
    public function __construct(
        private readonly ?string $area = null,
        private readonly ?string $route = null,
        private readonly bool $noRoute = false,
        private readonly ?string $controllerClass = null,
        private readonly ?string $magentoAction = null
    ) {
    }

    /**
     * @inheritdoc
     */
    public function area(): ?string
    {
        return $this->area;
    }

    /**
     * @inheritdoc
     */
    public function route(): ?string
    {
        return $this->route;
    }

    /**
     * @inheritdoc
     */
    public function isNoRoute(): bool
    {
        return $this->noRoute;
    }

    /**
     * @inheritdoc
     */
    public function controllerClass(): ?string
    {
        return $this->controllerClass;
    }

    /**
     * @inheritdoc
     */
    public function magentoAction(): ?string
    {
        return $this->magentoAction;
    }
}
