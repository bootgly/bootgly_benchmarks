#!/usr/bin/env bash
# =============================================================================
# Hyperf — Benchmark driver
# =============================================================================
#
# Full Hyperf framework (hyperf-skeleton) running on Swoole engine.
# Uses config/routes.php with Router::get() closures — no annotation scanning.
#
# SETUP:
#   1. cd scripts/benchmarks/HTTP_Server_CLI/artifacts/hyperf
#   2. composer install --no-dev
#   3. php bin/hyperf.php di:init-proxy   (optional: pre-cache DI proxies)
#
# Requires: Swoole PHP extension with swoole.use_shortname=Off
# =============================================================================

HYPERF_DIR="$BENCHMARK_DIR/artifacts/hyperf"

DRIVER_NAME="Hyperf"
DRIVER_VERSION=$(grep -A5 '"name": "hyperf/framework"' "$HYPERF_DIR/composer.lock" 2>/dev/null \
   | grep '"version"' | grep -oP '"\Kv?[0-9][^"]+' || echo "unknown")
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1
DRIVER_WRK_THREADS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WRK_THREADS" -lt 1 ]] && DRIVER_WRK_THREADS=1

driver_start () {
   cd "$HYPERF_DIR" || return 1

   SERVER_PORT="$PORT" \
   SERVER_WORKER_NUM="$DRIVER_WORKERS" \
   SERVER_DAEMONIZE=1 \
   php -d swoole.use_shortname=Off bin/hyperf.php start >/dev/null 2>&1

   wait_for_server
}

driver_stop () {
   kill_port
}
