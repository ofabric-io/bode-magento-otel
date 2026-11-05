<?php

declare(strict_types=1);

/**
 * One-shot bootstrap process (same shape as e2e/assert/di.php) exercising Bode\Tracing\Observer\AdminAudit in the
 * adminhtml area of a real, compiled-DI Magento: constructed through the object manager (the historical bug this
 * guards against was a bare `object` constructor parameter that compiled DI handed the raw config array instead of
 * a real object - see AGENTS.md section 6), then run twice through the real Magento code paths that dispatch the
 * two events it listens for in packages/tracing/etc/adminhtml/events.xml:
 *
 *  - admin_user_authenticate_after: dispatched by Magento\User\Model\User::authenticate(), called from
 *    Magento\Backend\Model\Auth::login($username, $password) - the exact call a real admin login POST makes. Params
 *    are ['username' => ..., 'password' => ..., 'user' => ..., 'result' => ...] (verified against
 *    app/code/Magento/User/Model/User.php in the public 2.4.9 source tree); there is no 'object' key, so
 *    AdminAudit's optional entity-type attribute is simply skipped, same as it would be for a real login.
 *  - adminhtml_cache_flush_system: dispatched with *no* params by
 *    Magento\Backend\Controller\Adminhtml\Cache\FlushSystem::execute() (verified against the same source tree) -
 *    reproduced directly here via the event manager, since driving the real HTTP controller would need a CSRF
 *    form_key + authenticated session cookie round trip for no additional DI coverage.
 *
 * Judgment call / known limitation: this process never calls \Magento\Framework\App\Bootstrap::run() (there is no
 * real HTTP request or bin/magento Command::run() here), so none of HttpInstrumentation's or CliInstrumentation's
 * hooks ever open a root span in this process. Bode\Tracing\Api\TelemetryStateInterface::rootSpan() is therefore
 * null the whole time, and AdminAudit::execute() takes its early "no root span" return on both events - no
 * admin.audit span event is produced here. What *is* covered: the compiled object manager constructs AdminAudit
 * (with its real AdminSession proxy dependency) without throwing, and execute() runs to completion without
 * throwing, in the one area (adminhtml) and the one real control flow (Auth::login, cache flush event) that matter
 * for the constructor-typing bug class. Getting the admin.audit span event itself onto a real trace needs the
 * HTTP-login fallback described in the task (real cookie-based admin session over HTTP) - flagged as "Not covered"
 * in the report for this scenario, not silently assumed to work.
 */

require '/magento/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\State $appState */
$appState = $objectManager->get(\Magento\Framework\App\State::class);
$appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
/** @var \Magento\Framework\App\ObjectManager\ConfigLoader $configLoader */
$configLoader = $objectManager->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class);
$objectManager->configure($configLoader->load(\Magento\Framework\App\Area::AREA_ADMINHTML));

// Same credentials e2e/install.sh used for setup:install --admin-user/--admin-password.
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = 'Adm1n_Pass!123';

/** @var \Magento\Backend\Model\Auth $auth */
$auth = $objectManager->get(\Magento\Backend\Model\Auth::class);
try {
    $auth->login(ADMIN_USERNAME, ADMIN_PASSWORD);
} catch (\Magento\Framework\Exception\AuthenticationException $e) {
    fwrite(STDERR, 'trigger-admin-save: admin login failed: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!$auth->isLoggedIn()) {
    fwrite(STDERR, "trigger-admin-save: admin login did not succeed, aborting\n");
    exit(1);
}
echo "trigger-admin-save: admin_user_authenticate_after dispatched via a real Auth::login()\n";

/** @var \Magento\Framework\Event\ManagerInterface $eventManager */
$eventManager = $objectManager->get(\Magento\Framework\Event\ManagerInterface::class);
// Matches Magento\Backend\Controller\Adminhtml\Cache\FlushSystem::execute() exactly: no event params.
$eventManager->dispatch('adminhtml_cache_flush_system');
echo "trigger-admin-save: adminhtml_cache_flush_system dispatched\n";

echo "trigger-admin-save: AdminAudit constructed and ran through the compiled adminhtml object manager "
    . "without throwing\n";
