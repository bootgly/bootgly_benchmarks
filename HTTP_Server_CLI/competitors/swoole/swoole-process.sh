#!/usr/bin/env bash
# =============================================================================
# Swoole (SWOOLE_PROCESS mode) — Benchmark Driver
# =============================================================================

# --- Configuration ---
SWOOLE_DIR="$BENCHMARK_DIR/artifacts/swoole"
SWOOLE_SCRIPT="swoole-process-routes.php"

DRIVER_NAME="Swoole (Process)"
DRIVER_VERSION=$(php -r "echo phpversion('swoole') ?? 'unknown';" 2>/dev/null || echo "unknown")

# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1
DRIVER_WRK_THREADS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WRK_THREADS" -lt 1 ]] && DRIVER_WRK_THREADS=1

driver_start () {
   cd "$SWOOLE_DIR" || return 1
   SERVER_WORKER_NUM="$DRIVER_WORKERS" \
   php "$SWOOLE_SCRIPT" >/dev/null 2>&1
   wait_for_server
}

driver_stop () {
   kill_port
}
