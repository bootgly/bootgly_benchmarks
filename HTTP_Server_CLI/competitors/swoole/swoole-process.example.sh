#!/usr/bin/env bash
# =============================================================================
# Swoole (SWOOLE_PROCESS mode) — Benchmark Driver (EXAMPLE)
# =============================================================================
#
# SETUP:
#   1. Install the Swoole PHP extension (>= 6.2.0):
#        git clone --depth 1 --branch v6.2.0 https://github.com/swoole/swoole-src.git
#        cd swoole-src && phpize && ./configure && make -j$(nproc) && sudo make install
#      Verify: php -r "echo phpversion('swoole');"
#
#   2. Route handler files are already in scripts/benchmarks/HTTP_Server_CLI/artifacts/swoole/.
#
#   3. Copy this file to activate:
#        cp swoole-process.example.sh swoole-process.sh
#
#   4. Run: bash scripts/benchmark HTTP_Server_CLI --competitors=bootgly,swoole-process
#
#   All 3 modes at once:
#     bash scripts/benchmark HTTP_Server_CLI \
#       --competitors=bootgly,swoole-process,swoole-base,swoole-coroutine
#
# SWOOLE_PROCESS MODE:
#   Multi-process architecture: master + manager + worker processes.
#   The master process handles all connections and dispatches them to workers.
#   This is Swoole's default mode.
# =============================================================================

# --- Configuration (edit these) ---
SWOOLE_DIR="$BENCHMARK_DIR/artifacts/swoole"
SWOOLE_SCRIPT="swoole-process-routes.php"

DRIVER_NAME="Swoole (Process)"
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
