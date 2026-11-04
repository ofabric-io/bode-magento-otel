<?php

declare(strict_types=1);

namespace Bode\Metrics\Instrumentation;

use Bode\Metrics\Hook\MetricsContext;
use Magento\Email\Model\Transport;
use Magento\Sales\Model\Service\OrderService;

/**
 * Counters that make sense for any Magento store: orders placed (store, payment method), payment failures (payment
 * method), transactional emails sent or failed. Failed admin logins are counted by an observer (no user attribute).
 * Never an order id, customer, address or amount.
 */
class CommerceMetrics implements InstrumentationInterface
{
    /**
     * @inheritdoc
     */
    public function register(MetricsContext $context): void
    {
        $context->after(
            'commerce',
            OrderService::class,
            'place',
            function (?object $s, array $params, mixed $order, mixed $error) use ($context): void {
                $order = $order ?? ($params[0] ?? null);
                $method = 'unknown';
                $store = 'unknown';
                if (is_object($order)) {
                    $payment = method_exists($order, 'getPayment') ? $order->getPayment() : null;
                    $method = $context->label(is_object($payment) ? $payment->getMethod() : 'unknown');
                    $store = $context->label(
                        method_exists($order, 'getStore') ? $order->getStore()->getCode() : 'unknown'
                    );
                }
                if ($error !== null) {
                    $context->recorder->count('magento.payments.failed', ['payment_method' => $method]);

                    return;
                }
                $context->recorder->count('magento.orders.placed', ['store' => $store, 'payment_method' => $method]);
            }
        );

        $context->after(
            'commerce',
            Transport::class,
            'sendMessage',
            static function (?object $s, array $params, mixed $ret, mixed $error) use ($context): void {
                $context->recorder->count('magento.emails', ['outcome' => $error === null ? 'sent' : 'failed']);
            }
        );
    }
}
