<?php

declare(strict_types=1);

namespace Bode\Tracing\Plugin;

use Bode\Tracing\Api\PrivacyInterface;
use Bode\Tracing\Api\TelemetryStateInterface;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Adds who and where to the request's root span after the front controller has run: area, store and website code,
 * customer id and group (storefront) or admin user id and role (admin), a keyed hash of the session id, and the
 * session-independent facts only. It never starts a session: on a cacheable storefront page that would add a cookie.
 *
 * Personal data goes through PrivacyInterface (modes: id, hash, off); email addresses and names are never read.
 */
class EnrichRequestSpan
{
    /**
     * @param TelemetryStateInterface $telemetry
     * @param PrivacyInterface $privacy
     * @param StoreManagerInterface $storeManager
     * @param State $state
     * @param CustomerSession $customerSession injected as its proxy (etc/di.xml)
     * @param AdminSession $adminSession injected as its proxy (etc/di.xml)
     * @param GroupRepositoryInterface $groupRepository injected as its proxy (etc/di.xml)
     */
    public function __construct(
        private readonly TelemetryStateInterface $telemetry,
        private readonly PrivacyInterface $privacy,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $state,
        private readonly CustomerSession $customerSession,
        private readonly AdminSession $adminSession,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * Describes the request on the root span.
     *
     * @param FrontControllerInterface $subject
     * @param mixed $result
     * @param RequestInterface $request
     * @return mixed
     */
    public function afterDispatch(FrontControllerInterface $subject, mixed $result, RequestInterface $request): mixed
    {
        $root = $this->telemetry->rootSpan();
        if ($root === null) {
            return $result;
        }

        try {
            $area = $this->state->getAreaCode();
            $root->setAttribute('bode.magento.area', $area);
            $store = $this->storeManager->getStore();
            $root->setAttribute('bode.magento.store', (string) $store->getCode());
            $root->setAttribute('bode.magento.website', (string) $this->storeManager->getWebsite()->getCode());

            // Never start a session just to describe it.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (session_status() === PHP_SESSION_ACTIVE) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $session = $this->privacy->sessionHash((string) session_id());
                if ($session !== null) {
                    $root->setAttribute('session.id', $session);
                }
                $area === 'adminhtml' ? $this->enrichAdmin($root) : $this->enrichCustomer($root);
            }
        } catch (Throwable) {
            // Enrichment is best effort; the span is already useful without it.
            return $result;
        }

        return $result;
    }

    /**
     * Adds the admin user id and role.
     *
     * @param \Bode\Tracing\Hook\SpanHandleInterface $root
     * @return void
     */
    private function enrichAdmin($root): void
    {
        $user = $this->adminSession->getUser();
        if (!is_object($user) || !$user->getId()) {
            return;
        }
        $id = $this->privacy->userId((int) $user->getId());
        if ($id !== null) {
            $root->setAttribute('enduser.id', $id);
        }
        $role = is_object($user->getRole()) ? (string) $user->getRole()->getRoleName() : '';
        if ($role !== '') {
            $root->setAttribute('bode.magento.admin.role', substr($role, 0, 100));
        }
    }

    /**
     * Adds the customer id and group.
     *
     * @param \Bode\Tracing\Hook\SpanHandleInterface $root
     * @return void
     */
    private function enrichCustomer($root): void
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId === 0) {
            return;
        }
        $id = $this->privacy->userId($customerId);
        if ($id !== null) {
            $root->setAttribute('enduser.id', $id);
        }
        $group = $this->groupRepository->getById((int) $this->customerSession->getCustomerGroupId());
        $root->setAttribute('bode.magento.customer.group', substr((string) $group->getCode(), 0, 100));
    }
}
