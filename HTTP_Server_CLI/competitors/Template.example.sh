#!/usr/bin/env bash
# =============================================================================
# Benchmark Driver — Template
# =============================================================================
#
# SETUP:
#   1. Copy this file to: scripts/benchmark/competitors/<name>.sh
#        cp Template.example.sh myserver.sh
#   2. Install/configure the HTTP server you want to benchmark.
#   3. Create a route handler with the same route set as the Bootgly benchmark:
#      10 static + 10 dynamic + 6 nested + 3 middleware + catch-all 404.
#      See: artifacts/ for examples of each competitor's route handler.
#   4. Edit the variables below (SERVER_DIR, SERVER_SCRIPT, DRIVER_NAME, etc.)
#   5. Implement driver_start() and driver_stop() for your server.
#   6. Run: bash scripts/benchmark HTTP_Server_CLI --competitors=bootgly,<name>
#
# Driver Interface (required exports):
#   DRIVER_NAME      — Display name
#   DRIVER_VERSION   — Version string
#   DRIVER_WORKERS   — Number of worker processes
#   driver_start ()  — Start the server (must block until ready)
#   driver_stop ()   — Stop the server and release the port
#
# Shared helpers available from the main script:
#   wait_for_server  — Polls port until server is listening
#   kill_port        — Force-kills any process on $PORT
# =============================================================================

# --- Configuration (edit these) ---
SERVER_DIR="/path/to/your/server"
SERVER_SCRIPT="server.php"

DRIVER_NAME="MyServer"
DRIVER_VERSION="unknown"
# Match Bootgly's worker count for a fair comparison
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1

driver_start () {
   cd "$SERVER_DIR" || return 1
   php "$SERVER_SCRIPT" start -d >/dev/null 2>&1
   wait_for_server
}

driver_stop () {
   cd "$SERVER_DIR" || return 1
   php "$SERVER_SCRIPT" stop >/dev/null 2>&1 || true
   sleep 1
   kill_port
}
