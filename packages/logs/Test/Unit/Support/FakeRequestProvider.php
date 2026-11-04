<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Support;

use Bode\Logs\Model\RequestProviderInterface;

class FakeRequestProvider implements RequestProviderInterface
{
    /**
     * @param string|null $area
     * @param string|null $route
     */
    public function __construct(private readonly ?string $area = null, private readonly ?string $route = null)
    {
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
}
