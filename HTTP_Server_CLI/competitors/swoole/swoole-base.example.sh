#!/usr/bin/env bash
# =============================================================================
# Swoole (SWOOLE_BASE mode) — Benchmark Driver (EXAMPLE)
# =============================================================================
#
# SETUP: Same as swoole-process.example.sh (same Swoole extension needed).
#
#   Copy to activate: cp swoole-base.example.sh swoole-base.sh
#   Run: bash scripts/benchmark HTTP_Server_CLI --competitors=bootgly,swoole-base
#
# SWOOLE_BASE MODE:
#   Reactor-based architecture: each worker independently accepts connections
#   and handles requests without a master dispatcher. Similar to Nginx.
#   Lower IPC overhead, potentially faster for simple request handling.
# =============================================================================

# --- Configuration (edit these) ---
SWOOLE_DIR="$BENCHMARK_DIR/artifacts/swoole"
SWOOLE_SCRIPT="swoole-base-routes.php"

DRIVER_NAME="Swoole (Base)"
DRIVER_VERSION=$(php -r "echo phpversion('swoole') ?? 'unknown';" 2>/dev/null || echo "unknown")

# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1

driver_start () {
   cd "$SWOOLE_DIR" || return 1
   php "$SWOOLE_SCRIPT" >/dev/null 2>&1
   wait_for_server
}

driver_stop () {
   kill_port
}
