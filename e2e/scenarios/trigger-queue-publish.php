<?php

declare(strict_types=1);

/**
 * One-shot bootstrap process (same shape as e2e/assert/di.php) that publishes a message to a *plain*
 * Magento\Framework\MessageQueue\Consumer-backed queue, not a bulk/async one.
 *
 * Why this topic, not the async/bulk REST path an earlier version of this scenario used: "async.operations.all"
 * (the consumer behind async/bulk REST calls) is wired in queue_consumer.xml with `consumerInstance="Magento\
 * AsynchronousOperations\Model\MassConsumer"` - a different class entirely from `Magento\Framework\MessageQueue\
 * Consumer`, which is the one packages/tracing/Instrumentation/QueueInstrumentation.php actually hooks
 * (`dispatchMessage`). Verified directly: publishing through async/bulk REST and running that consumer did move the
 * message to status=COMPLETE in queue_message_status (it really was processed), but produced no "queue ..." span at
 * all, because MassConsumer never calls dispatchMessage. That is a real, separate finding about
 * packages/tracing's queue coverage (bulk/async consumers are not instrumented), reported but not fixed here (out of
 * scope for this e2e PR per AGENTS.md "a bug fix and a refactor are two PRs").
 *
 * Every other queue_consumer.xml entry that uses a `handler="Class::method"` attribute instead of
 * `consumerInstance="..."` (confirmed by reading every queue_consumer.xml under vendor/magento) is wrapped by
 * Magento's own generic Consumer class internally - which does go through dispatchMessage. Picked
 * inventory.reservations.cleanup because its handler (DeleteReservationsBySkus::execute(array $skus)) takes a
 * trivial string[] payload (no product/order/customer fixture needed) and is naturally a safe no-op against SKUs
 * that do not exist.
 */

require '/magento/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var \Magento\Framework\MessageQueue\PublisherInterface $publisher */
$publisher = $objectManager->get(\Magento\Framework\MessageQueue\PublisherInterface::class);
$publisher->publish('inventory.reservations.cleanup', ['bode-e2e-nonexistent-sku-xyz']);

echo "trigger-queue-publish: published to inventory.reservations.cleanup\n";
