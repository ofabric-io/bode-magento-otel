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
# Needs only Docker. Installed Magento lives in e2e/.magento and the composer cache in e2e/.composer-cache
# (both gitignored) when MAGENTO_MOUNT/COMPOSER_CACHE_MOUNT are overridden to those host paths, so CI can cache both by
# path with actions/cache; locally they default to named Docker volumes instead (bind-mounting is slow on Windows).
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
# Same pattern as MAGENTO_MOUNT, for the composer cache: a named volume locally, overridable to a host path in CI so
# actions/cache can key it by hash (Magento version, PHP/extension versions, packages/*/composer.json content).
COMPOSER_CACHE_MOUNT=${COMPOSER_CACHE_MOUNT:-bode-e2e-composer-cache}

# Bounded, not `until ... do sleep ...; done` forever: a genuinely broken service (a crash-looping container, a bad
# image) must fail with a clear message within a couple of minutes, not hang the whole job until a CI-level timeout
# eventually kills it with no indication of which service never came up. Top-level, not inside one `case` branch:
# `up` and `web-up` are separate invocations of this script (separate processes), so a function defined inside one
# case branch would not exist for another.
wait_ready() { # wait_ready <label> <max_attempts> <sleep_seconds> <check command...>
  local label="$1" max="$2" delay="$3"
  shift 3
  local attempt=0
  until "$@" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "$max" ]; then
      echo "$label did not become ready after $((max * delay))s" >&2
      return 1
    fi
    sleep "$delay"
  done
}

case "${1:-}" in
  up)
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
    mkdir -p "$OUTPUT_DIR"
    # World-writable: the Collector image runs its process as its own non-root UID, which does not match whatever
    # UID created this directory (the GitHub Actions runner's own user in CI, a different one again locally).
    # Windows Docker Desktop does not enforce host/container UID matching on bind mounts the way Linux does, so this
    # went unnoticed until the first real CI run failed with "open /output/traces.jsonl: permission denied". This
    # directory only ever holds this e2e run's own disposable Collector output (gitignored), so world-writable is a
    # non-issue here, not a real exposure.
    chmod 777 "$OUTPUT_DIR"
    # No explicit volume/dir creation for COMPOSER_CACHE_MOUNT: like MAGENTO_MOUNT, `docker run -v` creates a missing
    # named volume on demand, and a missing host-path directory too - same implicit behaviour, so no branching here.
    docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" docker/php >/dev/null

    docker rm -f "$DB" "$SEARCH" "$COLLECTOR" >/dev/null 2>&1 || true
    docker run -d --name "$DB" --network "$NET" \
      -e MARIADB_DATABASE=magento -e MARIADB_USER=magento -e MARIADB_PASSWORD=magento \
      -e MARIADB_ROOT_PASSWORD=root "$MARIADB_IMAGE" >/dev/null
    # No bootstrap.memory_lock: it requires `docker run --ulimit memlock=-1:-1` to actually succeed, which this
    # never set - OpenSearch's bootstrap checks can then fail and crash-loop on hosts where memory locking isn't
    # otherwise available (observed: the readiness wait below hung for 15+ minutes in CI before this was removed).
    # Not needed for a disposable single-node e2e search index anyway.
    docker run -d --name "$SEARCH" --network "$NET" \
      -e discovery.type=single-node -e plugins.security.disabled=true -e DISABLE_INSTALL_DEMO_CONFIG=true \
      -e OPENSEARCH_JAVA_OPTS="-Xms512m -Xmx512m" "$OPENSEARCH_IMAGE" >/dev/null
    docker run -d --name "$COLLECTOR" --network "$NET" \
      -v "$PWD/e2e/collector/config.yaml:/etc/otelcol-contrib/config.yaml:ro" -v "$OUTPUT_DIR:/output" \
      "$OTEL_COLLECTOR_IMAGE" >/dev/null

    echo "waiting for MariaDB, OpenSearch, the Collector..."
    wait_ready "MariaDB" 60 2 docker exec "$DB" healthcheck.sh --connect --innodb_initialized \
      || { docker logs "$DB" 2>&1 | tail -n 50; exit 1; }
    wait_ready "OpenSearch" 60 2 docker run --rm --network "$NET" "$IMAGE" \
      php -r 'exit(@file_get_contents("http://'"$SEARCH"':9200") ? 0 : 1);' \
      || { docker logs "$SEARCH" 2>&1 | tail -n 50; exit 1; }
    # 120s, not 30s: a cold image pull on a CI runner can leave otelcol-contrib slower to start accepting
    # connections than on a warm local machine (observed: 30s was not enough in the first real CI run). If this
    # still isn't ready in time, the dumped container log is the actual reason, not a guess.
    wait_ready "the Collector" 60 2 docker run --rm --network "$NET" "$IMAGE" \
      php -r 'exit(@fsockopen("'"$COLLECTOR"'", 4318) ? 0 : 1);' \
      || { docker logs "$COLLECTOR" 2>&1 | tail -n 50; exit 1; }
    echo "ready"
    ;;
  install)
    # Clear through a container, not the host shell: MAGENTO_MOUNT may be a named volume, which has no host path to rm.
    docker run --rm -v "$MAGENTO_MOUNT:/magento" "$IMAGE" sh -c 'rm -rf /magento/* /magento/.??* 2>/dev/null || true'
    docker run --rm --network "$NET" -v "$MAGENTO_MOUNT:/magento" -v "$PWD:/repo:ro" \
      -v "$COMPOSER_CACHE_MOUNT:/root/.composer/cache" -w /magento \
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
    wait_ready "the web server" 60 1 docker run --rm --network "$NET" "$IMAGE" \
      php -r 'exit(@fsockopen("'"$WEB"'", 8000) ? 0 : 1);' \
      || { docker logs "$WEB" 2>&1 | tail -n 50; exit 1; }
    ;;
  web-down)
    docker rm -f "$WEB" >/dev/null 2>&1 || true
    ;;
  scenario)
    shift
    # Full OTel env, not just WEB_HOST/WEB_PORT: HTTP scenarios (httpGet/httpPost) only need WEB_HOST, since tracing
    # happens server-side in the already-configured $WEB container - but CLI scenarios run `bin/magento`/trigger
    # scripts *inside this container* (magento()/runScript()/runTrigger() in e2e/scenarios/run.php), inheriting
    # whatever env this docker run sets. Without it, the OTel SDK falls back to its spec default
    # (http://localhost:4318), unreachable from here since the Collector is a separate container - CLI scenarios
    # would silently produce no telemetry at all. Keep this block identical to web-up's.
    docker run --rm --network "$NET" -v "$MAGENTO_MOUNT:/magento" -v "$PWD/e2e:/e2e:ro" -w /magento \
      -e WEB_HOST="$WEB" -e WEB_PORT=8000 \
      -e OTEL_PHP_AUTOLOAD_ENABLED=true -e OTEL_SERVICE_NAME=bode-e2e \
      -e OTEL_TRACES_EXPORTER=otlp -e OTEL_METRICS_EXPORTER=otlp -e OTEL_LOGS_EXPORTER=none \
      -e OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf -e "OTEL_EXPORTER_OTLP_ENDPOINT=http://$COLLECTOR:4318" \
      -e OTEL_METRICS_EXPORT_INTERVAL=1000 -e OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta \
      -e MAGENTO_OTEL_TRACING_GROUPS=http,cron,cli,indexer,cache,queue,outbound \
      -e MAGENTO_OTEL_IP_MODE=full -e MAGENTO_OTEL_CORRELATION_HEADERS=X-E2E-Run \
      -e MAGENTO_OTEL_LOGS_OUTPUT=file,stdout -e MAGENTO_OTEL_LOGS_ENABLED=true \
      "$IMAGE" php /e2e/scenarios/run.php "$@"
    ;;
  assert)
    shift
    # disabled-mode needs a writable /magento: it runs real `bin/magento` subprocesses to compare telemetry-on vs
    # disabled, and a read-only mount silently breaks span export for those (verified, not assumed: identical
    # command, same env, same network - only the mount's read-only flag differed between a run that exported spans
    # and one that exported none; likely something in the OTel SDK/hook path needs write access under BP that a
    # pure read, no-CLI-subprocess script like spans.php/di.php/metrics.php/logs.php never touches). Every other
    # assertion only reads var/log and bootstraps in-process, so they keep the read-only guarantee.
    MOUNT_FLAG=":ro"
    if [ "${1:-}" = "disabled-mode" ]; then
      MOUNT_FLAG=""
    fi
    docker run --rm --network "$NET" -v "$MAGENTO_MOUNT:/magento$MOUNT_FLAG" -v "$OUTPUT_DIR:/output:ro" \
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
