#!/usr/bin/env bash
# =============================================================================
# Bootgly HTTP Server CLI — Benchmark Driver
# =============================================================================
#
# This is the baseline driver. It is always loaded by the benchmark script.
#
# Driver Interface (required exports):
#   DRIVER_NAME      — Display name
#   DRIVER_VERSION   — Version string
#   DRIVER_WORKERS   — Number of worker processes
#   driver_start ()  — Start the server (must block until ready)
#   driver_stop ()   — Stop the server and release the port
# =============================================================================

DRIVER_NAME="Bootgly"

# Extract version from autoboot.php constant: define('BOOTGLY_VERSION', '...');
DRIVER_VERSION="v$(grep -oP "define\('BOOTGLY_VERSION',\s*'\\K[^']+" "$BOOTGLY_DIR/autoboot.php" 2>/dev/null || echo "unknown")"

# Worker count matches WPI.project.php: max(1, nproc / 2)
DRIVER_WORKERS=$(( $(nproc 2>/dev/null || echo 1) / 2 ))
[[ "$DRIVER_WORKERS" -lt 1 ]] && DRIVER_WORKERS=1

driver_start () {
   cd "$BOOTGLY_DIR" || return 1
   # Stop any existing instance first (stale PID files cause "already running" errors)
   php bootgly project stop HTTP_Server_CLI >/dev/null 2>&1 || true
   sleep 0.5
   php bootgly project run HTTP_Server_CLI >/dev/null 2>&1
   wait_for_server
}

driver_stop () {
   cd "$BOOTGLY_DIR" || return 1
   php bootgly project stop HTTP_Server_CLI >/dev/null 2>&1 || true
   sleep 1
   kill_port
}
