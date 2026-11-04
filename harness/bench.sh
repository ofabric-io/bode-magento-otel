#!/usr/bin/env bash
# Micro-benchmark of the instrumentation itself (not of Magento): the time one simulated request spends in the hooks of
# Bode_Tracing and Bode_Metrics, with and without them, at the default groups and 5% sampling, and with everything
# sampled as a worst case. Needs `harness/run.sh up` and `harness/run.sh collector` first (the instrumented runs export for real).
#
# What this does NOT show: overhead on a real Magento request (hundreds of milliseconds, with many more hooked calls
# only when opt-in groups are on). It gives the fixed cost per request to put next to your real p50/p95.
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
NET=bode-otel-net
IMAGE=bode-otel-dev
N=${BENCH_N:-5000}

run() { # run <label> <extra docker -e args...>
  local label=$1; shift
  printf '%-42s ' "$label"
  docker run --rm --network "$NET" -v "$PWD:/repo" -v bode-otel-harness-vendor:/harness-vendor \
    -e HARNESS_VENDOR=/harness-vendor/vendor -e HARNESS_NO_TRACEPARENT=1 -e BENCH_N="$N" \
    -e MAGENTO_OTEL_ASSUME_WEB=true -e MAGENTO_OTEL_IP_MODE=full -e OTEL_PHP_AUTOLOAD_ENABLED=true \
    -e OTEL_SERVICE_NAME=bode-bench "$@" "$IMAGE" php /repo/harness/run.php bench
}

OTLP=(-e OTEL_TRACES_EXPORTER=otlp -e OTEL_METRICS_EXPORTER=otlp -e OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
      -e OTEL_EXPORTER_OTLP_ENDPOINT=http://bode-alloy-otlp:4318 -e OTEL_EXPORTER_OTLP_TIMEOUT=1000
      -e OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta -e OTEL_BSP_MAX_QUEUE_SIZE=512
      -e OTEL_BSP_MAX_EXPORT_BATCH_SIZE=256 -e OTEL_BSP_SCHEDULE_DELAY=1000 -e OTEL_BSP_EXPORT_TIMEOUT=1000)

for round in 1 2 3; do
  echo "--- round $round"
  run "baseline (OTEL_SDK_DISABLED=true)" -e OTEL_SDK_DISABLED=true
  run "instrumented, 5% sampling, exporting" "${OTLP[@]}" -e OTEL_TRACES_SAMPLER=parentbased_traceidratio -e OTEL_TRACES_SAMPLER_ARG=0.05
  run "instrumented, 100% sampling (worst case)" "${OTLP[@]}" -e OTEL_TRACES_SAMPLER=parentbased_always_on
done
