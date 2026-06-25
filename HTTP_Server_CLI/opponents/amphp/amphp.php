<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — AMPHP Opponent (Docker)
 * --------------------------------------------------------------------------
 *
 * Runs the AMPHP bootable (Amp v3, fibers) inside a Docker container — kept off
 * the host to avoid polluting it with the ext-pgsql build toolchain and the
 * composer vendor tree. TFB only: the container always runs
 * amphp-techempower.php in the FOREGROUND (the container IS the daemon).
 *
 * Worker model is manual pcntl_fork + SO_REUSEPORT (BOOTGLY_WORKERS children,
 * each its own Revolt event loop on :8082) — NOT amphp/cluster — for parity
 * with the ReactPHP opponent. DB is async amphp/postgres (ext-pgsql), one pool
 * per worker (DB_POOL_MAX, default 1).
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php amphp.php start
 *   php amphp.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.amphp -t bootgly-amphp \
 *     ../../bootables/amphp
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/amphp');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.amphp';
$image = 'bootgly-amphp';
$container = 'bench-amphp';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Async PostgreSQL pool size per worker (default 1 for parity with the other
//   opponents).
$poolMax = getenv('DB_POOL_MAX') ?: '1';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $workers, $poolMax) {
      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # DB env the bootable reads (DB_HOST/PORT/NAME/USER/PASS); host PG via host network.
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      // # Worker count → BOOTGLY_WORKERS (pcntl_fork count); pool size → DB_POOL_MAX.
      exec(
         "docker run -d --network host --name {$container} "
         . "-e BOOTGLY_WORKERS={$workers} "
         . '-e DB_POOL_MAX=' . escapeshellarg($poolMax) . ' '
         . "{$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
