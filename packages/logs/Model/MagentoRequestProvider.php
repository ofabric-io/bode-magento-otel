<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Throwable;

/**
 * Area and route from Magento's State and request. Both arrive as Proxies, because the logger is built before the
 * application, and every lookup is guarded: logging must work in the middle of a bootstrap failure, when neither
 * exists.
 */
class MagentoRequestProvider implements RequestProviderInterface
{
    /**
     * @param State $state
     * @param RequestInterface $request
     */
    public function __construct(private readonly State $state, private readonly RequestInterface $request)
    {
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
            if (!method_exists($this->request, 'getFullActionName')) {
                return null;
            }
            $route = (string) $this->request->getFullActionName();

            return $route !== '' && $route !== '__' ? $route : null;
        } catch (Throwable) {
            return null;
        }
    }
}
