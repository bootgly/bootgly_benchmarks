#!/usr/bin/env bash
# =============================================================================
# Workerman — Benchmark Driver
# =============================================================================

# --- Configuration ---
WORKERMAN_DIR="$BENCHMARK_DIR/artifacts/workerman"
WORKERMAN_SCRIPT="workerman-routes.php"

DRIVER_NAME="Workerman"

# Extract Workerman version from composer.lock
DRIVER_VERSION=$(grep -A1 '"name": "workerman/workerman"' "$WORKERMAN_DIR/composer.lock" 2>/dev/null \
   | grep '"version"' | grep -oP '"\Kv?[0-9][^"]+' || echo "unknown")

# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1

driver_start () {
   cd "$WORKERMAN_DIR" || return 1
   php "$WORKERMAN_SCRIPT" start -d >/dev/null 2>&1
   wait_for_server
}

driver_stop () {
   cd "$WORKERMAN_DIR" || return 1
   php "$WORKERMAN_SCRIPT" stop >/dev/null 2>&1 || true
   sleep 1
   kill_port
}
