<?php

declare(strict_types=1);

namespace Bode\Tracing\Observer;

use Bode\Tracing\Api\PrivacyInterface;
use Bode\Tracing\Api\TelemetryStateInterface;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Throwable;

/**
 * Admin audit trail as span events on the request's root span: who (admin user id, through the privacy mode), what
 * kind of entity, which action. Never field values, names or e-mail addresses. At most MAX_EVENTS per request.
 */
class AdminAudit implements ObserverInterface
{
    private const MAX_EVENTS = 50;
    private const ACTIONS = [
        'model_save_after' => 'save',
        'model_delete_after' => 'delete',
        'adminhtml_cache_flush_all' => 'cache_flush_all',
        'adminhtml_cache_flush_system' => 'cache_flush_system',
        'admin_user_authenticate_after' => 'login',
    ];

    /**
     * @var int
     */
    private int $emitted = 0;

    /**
     * @param TelemetryStateInterface $telemetry
     * @param PrivacyInterface $privacy
     * @param AdminSession $adminSession injected as its proxy (etc/di.xml)
     */
    public function __construct(
        private readonly TelemetryStateInterface $telemetry,
        private readonly PrivacyInterface $privacy,
        private readonly AdminSession $adminSession
    ) {
    }

    /**
     * Adds an audit event to the root span.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $root = $this->telemetry->rootSpan();
        if ($root === null || $this->emitted >= self::MAX_EVENTS) {
            return;
        }

        try {
            $action = self::ACTIONS[$observer->getEvent()->getName()] ?? null;
            if ($action === null) {
                return;
            }
            $attributes = ['bode.magento.audit.action' => $action];
            $object = $observer->getEvent()->getData('object');
            if (is_object($object)) {
                $attributes['bode.magento.audit.entity'] = $this->entityType($object);
            }
            $user = $this->adminSession->getUser();
            $id = is_object($user) && $user->getId() ? $this->privacy->userId((int) $user->getId()) : null;
            if ($id !== null) {
                $attributes['enduser.id'] = $id;
            }
            $this->emitted++;
            $root->addEvent('admin.audit', $attributes);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Event prefix of the model (catalog_product, sales_order, ...): a bounded set, never an id.
     *
     * @param object $object
     * @return string
     */
    private function entityType(object $object): string
    {
        $type = method_exists($object, 'getEventPrefix') ? (string) $object->getEventPrefix() : '';
        if ($type === '') {
            $short = substr(strrchr('\\' . $object::class, '\\') ?: '', 1);
            $type = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        }

        return substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($type)), 0, 80);
    }
}
