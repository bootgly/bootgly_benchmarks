#!/usr/bin/env bash
# =============================================================================
# FrankenPHP (Worker Mode) — Benchmark Driver
# =============================================================================

# --- Configuration ---
FRANKENPHP_DIR="$BENCHMARK_DIR/artifacts/frankenphp"

DRIVER_NAME="FrankenPHP"

# Extract version from frankenphp binary
DRIVER_VERSION=$(frankenphp --version 2>/dev/null | grep -oP 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1 || echo "unknown")

# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1
DRIVER_WRK_THREADS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WRK_THREADS" -lt 1 ]] && DRIVER_WRK_THREADS=1

driver_start () {
   cd "$FRANKENPHP_DIR" || return 1

   FRANKENPHP_PORT="$PORT" \
   FRANKENPHP_DIR="$FRANKENPHP_DIR" \
   FRANKENPHP_NUM_WORKERS="$DRIVER_WORKERS" \
   frankenphp run --config "$FRANKENPHP_DIR/Caddyfile" >/dev/null 2>&1 &
   _FRANKENPHP_PID=$!

   # Custom wait: lsof doesn't detect Go binaries on WSL2, use curl instead
   local elapsed=0
   while ! curl -sf -o /dev/null "http://127.0.0.1:$PORT/" 2>/dev/null; do
      sleep 0.5
      elapsed=$((elapsed + 1))
      if [[ $elapsed -ge $((READY_TIMEOUT * 2)) ]]; then
         echo -e "${RED}ERROR: FrankenPHP did not start on port $PORT within ${READY_TIMEOUT}s${NC}" >&2
         return 1
      fi
   done
   sleep 1
}

driver_stop () {
   # Kill by PID (lsof can't see Go binaries on WSL2)
   if [[ -n "${_FRANKENPHP_PID:-}" ]]; then
      kill "$_FRANKENPHP_PID" 2>/dev/null || true
      wait "$_FRANKENPHP_PID" 2>/dev/null || true
   fi
   kill_port
}
