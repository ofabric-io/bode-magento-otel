#!/usr/bin/env bash
# Runs the Bode modules against stub Magento classes with the real OpenTelemetry extension and SDK, exporting to a
# local Grafana LGTM stack (Tempo, Prometheus, Loki) so the result can be inspected for real.
#
#   harness/run.sh up         start LGTM and build the PHP image with the extensions
#   ALLOY_OTLP_CONFIG=path harness/run.sh collector  start an Alloy OTLP pipeline in front of LGTM
#   harness/run.sh scenarios  run every scenario once (ENDPOINT=http://bode-alloy-otlp:4318 to go
#                                           through the collector, which turns delta metrics into cumulative ones)
#   harness/run.sh down
#
# Needs only Docker. Grafana: http://localhost:3000 (admin/admin), Tempo API: http://localhost:3200.
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

NET=bode-otel-net
LGTM=bode-lgtm
IMAGE=bode-otel-dev
SCENARIOS=${SCENARIOS:-"storefront admin rest graphql cron-ok cron-fail cron-many indexer cache queue outbound gauges log"}

php_run() { # php_run <extra docker args> -- <php args>
  docker run --rm --network "$NET" -v "$PWD:/repo" -v bode-otel-harness-vendor:/harness-vendor \
    -e HARNESS_VENDOR=/harness-vendor/vendor "$@"
}

case "${1:-}" in
  up)
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
    docker rm -f "$LGTM" >/dev/null 2>&1 || true
    docker run -d --name "$LGTM" --network "$NET" -p 3000:3000 -p 3200:3200 -p 4318:4318 -p 9090:9090 -p 3100:3100 \
      grafana/otel-lgtm:latest >/dev/null
    docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" docker/php >/dev/null
    docker volume inspect bode-otel-harness-vendor >/dev/null 2>&1 || docker volume create bode-otel-harness-vendor >/dev/null
    docker run --rm -v "$PWD/harness:/h" -v bode-otel-harness-vendor:/harness-vendor -w /harness-vendor \
      "$IMAGE" sh -c 'cp /h/composer.json . && composer install --no-interaction --no-progress -q'
    echo "waiting for LGTM..."
    until curl -fsS http://localhost:3200/ready >/dev/null 2>&1; do sleep 3; done
    echo "ready"
    ;;
  collector)
    # An Alloy OTLP pipeline (ALLOY_OTLP_CONFIG=path to its config.alloy, e.g. the file a deployment runs in production), with
    # its exporters pointed at the local LGTM stack: Tempo and Mimir accept the same OTLP and remote-write, credentials are ignored.
    : "${ALLOY_OTLP_CONFIG:?set ALLOY_OTLP_CONFIG to the config.alloy to run in front of LGTM}"
    docker rm -f bode-alloy-otlp >/dev/null 2>&1 || true
    docker run -d --name bode-alloy-otlp --network "$NET" \
      -v "$(cd "$(dirname "$ALLOY_OTLP_CONFIG")" && pwd)/$(basename "$ALLOY_OTLP_CONFIG"):/etc/alloy/config.alloy:ro" \
      -e GRAFANA_TEMPO_URL="http://$LGTM:4318" -e GRAFANA_TEMPO_USER=1 -e GRAFANA_CLOUD_TOKEN=local \
      -e GRAFANA_PROM_URL="http://$LGTM:9090/api/v1/write" -e GRAFANA_PROM_USER=1 -e MONITORING_ENV=harness \
      "${ALLOY_IMAGE:-grafana/alloy:v1.21.3}" run --stability.level=experimental --storage.path=/tmp/alloy \
      --server.http.listen-addr=0.0.0.0:12345 /etc/alloy/config.alloy >/dev/null
    echo "collector started as bode-alloy-otlp"
    ;;
  scenarios)
    for s in $SCENARIOS; do
      echo "== $s"
      php_run \
        -e OTEL_PHP_AUTOLOAD_ENABLED=true -e OTEL_SERVICE_NAME=bode-harness \
        -e OTEL_TRACES_EXPORTER=otlp -e OTEL_METRICS_EXPORTER=otlp -e OTEL_LOGS_EXPORTER=none \
        -e OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf -e "OTEL_EXPORTER_OTLP_ENDPOINT=${ENDPOINT:-http://$LGTM:4318}" \
        -e OTEL_METRICS_EXPORT_INTERVAL=1000 -e OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta \
        -e MAGENTO_OTEL_ASSUME_WEB=true -e MAGENTO_OTEL_TRACING_GROUPS="${GROUPS_ENV:-http,cron,cli,indexer,cache,queue,outbound}" \
        -e MAGENTO_OTEL_IP_MODE="${IP_MODE:-full}" -e MAGENTO_OTEL_CORRELATION_HEADERS=CF-Ray -e MAGENTO_OTEL_OUTBOUND_HOSTS=bode-lgtm \
        "$IMAGE" php /repo/harness/run.php "$s"
    done
    ;;
  down)
    docker rm -f "$LGTM" bode-alloy-otlp >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    ;;
  *)
    sed -n 2,10p "$0"
    exit 2
    ;;
esac
