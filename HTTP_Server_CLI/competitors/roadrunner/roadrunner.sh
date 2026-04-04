#!/usr/bin/env bash
# =============================================================================
# RoadRunner — Benchmark Driver
# =============================================================================

# --- Configuration ---
ROADRUNNER_DIR="$BENCHMARK_DIR/artifacts/roadrunner"

DRIVER_NAME="RoadRunner"
DRIVER_VERSION=$(cd "$ROADRUNNER_DIR" && ./rr -v 2>/dev/null | grep -oP 'version \K[0-9]+\.[0-9]+\.[0-9]+' || echo "unknown")

# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1

driver_start () {
   cd "$ROADRUNNER_DIR" || return 1
   ./rr serve -p -s -c .rr.yaml \
      -o "http.pool.num_workers=$DRIVER_WORKERS" \
      -o "http.address=0.0.0.0:$PORT" \
      >/dev/null 2>&1 &
   wait_for_server
}

driver_stop () {
   cd "$ROADRUNNER_DIR" || return 1
   ./rr stop -f >/dev/null 2>&1 || true
   sleep 1
   kill_port
}
