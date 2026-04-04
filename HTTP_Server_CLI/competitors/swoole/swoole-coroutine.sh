#!/usr/bin/env bash
# =============================================================================
# Swoole (Coroutine HTTP Server) — Benchmark Driver
# =============================================================================

# --- Configuration ---
SWOOLE_DIR="$BENCHMARK_DIR/artifacts/swoole"
SWOOLE_SCRIPT="swoole-coroutine-routes.php"

DRIVER_NAME="Swoole (Coroutine)"
DRIVER_VERSION=$(php -r "echo phpversion('swoole') ?? 'unknown';" 2>/dev/null || echo "unknown")

# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1

driver_start () {
   cd "$SWOOLE_DIR" || return 1
   php "$SWOOLE_SCRIPT" >/dev/null 2>&1 &
   wait_for_server
}

driver_stop () {
   # Kill via PID file first
   local pidfile="$SWOOLE_DIR/swoole-coroutine.pid"
   if [[ -f "$pidfile" ]]; then
      local main_pid
      main_pid=$(cat "$pidfile")
      # Kill the process group (parent + forked children)
      kill -- -"$main_pid" 2>/dev/null || kill "$main_pid" 2>/dev/null
      rm -f "$pidfile"
   fi
   kill_port
}
