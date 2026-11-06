#!/usr/bin/env bash
# Runs inside the bode-otel-dev container, cwd /magento (empty, freshly cleared by e2e/run.sh). Installs a real
# Magento 2.4.9 from the public Mage-OS mirror, requires packages/* from a path repository with symlinks off (so
# .gitattributes export-ignore is exercised like a real install, same spirit as bin/verify-install), compiles it in
# production mode, and deploys a minimal static content set. DB_HOST/SEARCH_HOST are the service container names on
# the shared Docker network.
set -euo pipefail

MAGENTO_VERSION=${MAGENTO_VERSION:-2.4.9}
DB_HOST=${DB_HOST:?}
SEARCH_HOST=${SEARCH_HOST:?}

composer config -g allow-plugins true
composer create-project --repository='{"type":"composer","url":"https://mirror.mage-os.org/"}' \
  "magento/project-community-edition=$MAGENTO_VERSION" . --no-interaction --no-progress --prefer-dist

php -r '
$f = "composer.json";
$j = json_decode(file_get_contents($f), true);
$j["repositories"]["bode-packages"] = ["type" => "path", "url" => "/repo/packages/*", "options" => ["symlink" => false]];
// magento/project-community-edition requires composer-dependency-version-audit-plugin, which checks every required
// package against the public Packagist registry and refuses when a dev-main build here is "older" than a tagged
// public release of the same name - which is always true for bode/*, since this project already ships real
// releases there (docs/releasing.md). Excluding only bode/* from the default Packagist repository (not disabling
// it outright, which would also block open-telemetry/* below) is the correct fix on its own terms too: this
// project must always resolve bode/* from the working tree under test, never silently fall back to what is
// published.
$j["repositories"]["packagist.org"] = ["type" => "composer", "url" => "https://repo.packagist.org", "exclude" => ["bode/*"]];
file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'
composer require --no-interaction --no-progress \
  "bode/module-tracing:dev-main" "bode/module-metrics:dev-main" "bode/module-logs:dev-main" \
  "open-telemetry/api:^1.4" "open-telemetry/sdk:^1.4" "open-telemetry/exporter-otlp:^1.3" "open-telemetry/sem-conv:^1.30"
# bode/module-tracing only *suggests* the OTel SDK/exporter/sem-conv packages, so it stays a true no-op without them
# (AGENTS.md: "no-op without the runtime"). Without requiring them here, spans are created through the OTel API but
# recorded by its default no-op tracer and never exported - nothing reaches the Collector, silently.

# No module:enable here: this is a from-scratch install, so setup:install discovers and enables every registered
# module itself (including the three just required) via ComponentRegistrar. Running module:enable first, before
# app/etc/config.php exists, overwrites it with only the named modules enabled and silently disables the rest of
# Magento's own ~300 modules - including Magento_Config, whose di.xml wires the system-config XML compiler. That
# produced a real, confusing failure ("Cannot instantiate interface ... CompilerInterface") during development of
# this script; module:enable is for adding a module to an *already installed* Magento (then setup:upgrade), not this.
# 2FA is off in this install: POST /rest/V1/integration/admin/token (the wrap-product-save scenario) answers 400 "configure
# 2FA first" while it is on, and nothing here is about 2FA. The two modules are disabled in the same step as the install.
bin/magento setup:install \
  --base-url="http://bode-e2e-web:8000/" \
  --db-host="$DB_HOST" --db-name=magento --db-user=magento --db-password=magento \
  --admin-firstname=E2E --admin-lastname=Bot --admin-email=e2e@example.test \
  --admin-user=admin --admin-password='Adm1n_Pass!123' \
  --language=en_US --currency=USD --timezone=America/Chicago --use-rewrites=1 \
  --search-engine=opensearch --opensearch-host="$SEARCH_HOST" --opensearch-port=9200 \
  --opensearch-index-prefix=magento2 --opensearch-timeout=15 \
  --backend-frontname=admin --no-interaction \
  --disable-modules=Magento_AdminAdobeImsTwoFactorAuth,Magento_TwoFactorAuth

# One locale, one theme per area: the default deploy walks every installed theme/locale and is the single biggest
# avoidable cost in the install phase.
bin/magento setup:static-content:deploy -f --area frontend --theme Magento/blank en_US
bin/magento setup:static-content:deploy -f --area adminhtml --theme Magento/backend en_US

bin/magento deploy:mode:set production
bin/magento setup:di:compile

bin/magento cache:flush
