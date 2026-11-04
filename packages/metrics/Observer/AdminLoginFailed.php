<?php

declare(strict_types=1);

namespace Bode\Metrics\Observer;

use Bode\Metrics\Model\RecorderAccess;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Counts failed admin logins. The user name is deliberately not used: it is attacker-controlled input.
 */
class AdminLoginFailed implements ObserverInterface
{
    /**
     * @param RecorderAccess $access
     */
    public function __construct(private readonly RecorderAccess $access)
    {
    }

    /**
     * Counts one failed login.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $this->access->recorder()->count('magento.admin.login.failures');
    }
}
