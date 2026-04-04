#!/usr/bin/env bash
# =============================================================================
# RoadRunner — Benchmark Driver (EXAMPLE)
# =============================================================================
#
# SETUP:
#   1. Go to the artifacts directory:
#        cd scripts/benchmarks/HTTP_Server_CLI/artifacts/roadrunner
#
#   2. Install PHP dependencies (composer.json is already provided):
#        composer install --no-dev
#
#   3. Download the RoadRunner binary:
#        ./vendor/bin/rr get-binary
#      Verify: ./rr -v
#      Requires: php-curl, php-zip, php-sockets extensions
#
#   4. Copy this file to activate:
#        cp roadrunner.example.sh roadrunner.sh
#
#   5. Run: bash scripts/benchmark HTTP_Server_CLI --competitors=bootgly,roadrunner
#
#   Note: composer.json, worker.php, and .rr.yaml are already in the artifacts dir.
#
# Driver Interface (required exports):
#   DRIVER_NAME      — Display name
#   DRIVER_VERSION   — Version string
#   DRIVER_WORKERS   — Number of worker processes
#   driver_start ()  — Start the server (must block until ready)
#   driver_stop ()   — Stop the server and release the port
# =============================================================================

# --- Configuration (edit these) ---
ROADRUNNER_DIR="$BOOTGLY_DIR/scripts/benchmarks/HTTP_Server_CLI/artifacts/roadrunner"

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
