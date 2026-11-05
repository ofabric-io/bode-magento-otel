#!/usr/bin/env bash
# The single local entry point for the Magento end-to-end job: installs a real Magento 2.4.9 (Mage-OS mirror) with
# packages/* required from a path repository, compiles it in production mode, runs scenarios against it with the real
# ext-opentelemetry extension, and asserts on what a local OTel Collector received. CI (.github/workflows/magento-e2e.yml)
# calls this exact script, so a failure in CI reproduces locally with the same command.
#
#   bin/e2e up         start MariaDB, OpenSearch and the OTel Collector
#   bin/e2e install    composer create-project Magento 2.4.9, require packages/*, compile, deploy production mode
#   bin/e2e web-up     start the long-lived storefront/admin web server (phpserver/router.php)
#   bin/e2e scenario <name>   run one scenario (see e2e/scenarios/run.php for the list)
#   bin/e2e assert <name>     run one assertion group (spans, metrics, logs, di, disabled-mode)
#   bin/e2e all        up, install, web-up, every scenario, every assertion, down
#   bin/e2e web-down / bin/e2e down
#
# Needs only Docker. Installed Magento lives in e2e/.magento (gitignored) so it can be cached by path in CI.
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

NET=bode-e2e-net
IMAGE=bode-otel-dev
DB=bode-e2e-db
SEARCH=bode-e2e-search
COLLECTOR=bode-e2e-collector
WEB=bode-e2e-web
OUTPUT_DIR="$PWD/e2e/.output"
OPENSEARCH_IMAGE=${OPENSEARCH_IMAGE:-opensearchproject/opensearch:2}
MARIADB_IMAGE=${MARIADB_IMAGE:-mariadb:10.6}
OTEL_COLLECTOR_IMAGE=${OTEL_COLLECTOR_IMAGE:-otel/opentelemetry-collector-contrib:0.116.1}
# docker -v accepts a plain name (named volume, created on demand) or an absolute path (bind mount) identically, so
# this one variable chooses both: a named volume by default (bind-mounting the Magento tree is very slow on Windows,
# per AGENTS.md), overridable to a host path in CI so actions/cache and upload-artifact can address it directly.
MAGENTO_MOUNT=${MAGENTO_MOUNT:-bode-e2e-magento}

case "${1:-}" in
  up)
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
    mkdir -p "$OUTPUT_DIR"
    docker volume inspect bode-e2e-composer-cache >/dev/null 2>&1 || docker volume create bode-e2e-composer-cache >/dev/null
    docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" docker/php >/dev/null

    docker rm -f "$DB" "$SEARCH" "$COLLECTOR" >/dev/null 2>&1 || true
    docker run -d --name "$DB" --network "$NET" \
      -e MARIADB_DATABASE=magento -e MARIADB_USER=magento -e MARIADB_PASSWORD=magento \
      -e MARIADB_ROOT_PASSWORD=root "$MARIADB_IMAGE" >/dev/null
    docker run -d --name "$SEARCH" --network "$NET" \
      -e discovery.type=single-node -e plugins.security.disabled=true -e DISABLE_INSTALL_DEMO_CONFIG=true \
      -e bootstrap.memory_lock=true -e OPENSEARCH_JAVA_OPTS="-Xms512m -Xmx512m" "$OPENSEARCH_IMAGE" >/dev/null
    docker run -d --name "$COLLECTOR" --network "$NET" \
      -v "$PWD/e2e/collector/config.yaml:/etc/otelcol-contrib/config.yaml:ro" -v "$OUTPUT_DIR:/output" \
      "$OTEL_COLLECTOR_IMAGE" >/dev/null

    echo "waiting for MariaDB, OpenSearch, the Collector..."
    until docker exec "$DB" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 2; done
    until docker run --rm --network "$NET" "$IMAGE" php -r 'exit(@file_get_contents("http://'"$SEARCH"':9200") ? 0 : 1);' \
      >/dev/null 2>&1; do sleep 2; done
    until docker run --rm --network "$NET" "$IMAGE" php -r 'exit(@fsockopen("'"$COLLECTOR"'", 4318) ? 0 : 1);' \
      >/dev/null 2>&1; do sleep 1; done
    echo "ready"
    ;;
  install)
    # Clear through a container, not the host shell: MAGENTO_MOUNT may be a named volume, which has no host path to rm.
    docker run --rm -v "$MAGENTO_MOUNT:/magento" "$IMAGE" sh -c 'rm -rf /magento/* /magento/.??* 2>/dev/null || true'
    docker run --rm --network "$NET" -v "$MAGENTO_MOUNT:/magento" -v "$PWD:/repo:ro" \
      -v bode-e2e-composer-cache:/root/.composer/cache -w /magento \
      -e DB_HOST="$DB" -e SEARCH_HOST="$SEARCH" \
      "$IMAGE" bash /repo/e2e/install.sh
    ;;
  web-up)
    docker rm -f "$WEB" >/dev/null 2>&1 || true
    docker run -d --name "$WEB" --network "$NET" -v "$MAGENTO_MOUNT:/magento" -w /magento \
      -e OTEL_PHP_AUTOLOAD_ENABLED=true -e OTEL_SERVICE_NAME=bode-e2e \
      -e OTEL_TRACES_EXPORTER=otlp -e OTEL_METRICS_EXPORTER=otlp -e OTEL_LOGS_EXPORTER=none \
      -e OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf -e "OTEL_EXPORTER_OTLP_ENDPOINT=http://$COLLECTOR:4318" \
      -e OTEL_METRICS_EXPORT_INTERVAL=1000 -e OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta \
      -e MAGENTO_OTEL_TRACING_GROUPS=http,cron,cli,indexer,cache,queue,outbound \
      -e MAGENTO_OTEL_IP_MODE=full -e MAGENTO_OTEL_CORRELATION_HEADERS=X-E2E-Run \
      -e MAGENTO_OTEL_LOGS_OUTPUT=file,stdout -e MAGENTO_OTEL_LOGS_ENABLED=true \
      "$IMAGE" php -S 0.0.0.0:8000 phpserver/router.php >/dev/null
    until docker run --rm --network "$NET" "$IMAGE" php -r 'exit(@fsockopen("'"$WEB"'", 8000) ? 0 : 1);' \
      >/dev/null 2>&1; do sleep 1; done
    ;;
  web-down)
    docker rm -f "$WEB" >/dev/null 2>&1 || true
    ;;
  scenario)
    shift
    docker run --rm --network "$NET" -v "$MAGENTO_MOUNT:/magento" -v "$PWD/e2e:/e2e:ro" -w /magento \
      -e WEB_HOST="$WEB" -e WEB_PORT=8000 \
      "$IMAGE" php /e2e/scenarios/run.php "$@"
    ;;
  assert)
    shift
    docker run --rm --network "$NET" -v "$MAGENTO_MOUNT:/magento:ro" -v "$OUTPUT_DIR:/output:ro" \
      -v "$PWD/e2e:/e2e:ro" -v "$PWD/packages:/packages:ro" -w /magento \
      "$IMAGE" php "/e2e/assert/$1.php" "${@:2}"
    ;;
  down)
    docker rm -f "$DB" "$SEARCH" "$COLLECTOR" "$WEB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    ;;
  *)
    sed -n '2,16p' "$0"
    exit 2
    ;;
esac
