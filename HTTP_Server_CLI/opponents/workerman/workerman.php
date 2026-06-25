<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Workerman Opponent (Docker)
 * --------------------------------------------------------------------------
 *
 * Runs the Workerman bootable inside a Docker container — kept off the host to
 * avoid polluting it with the pdo_pgsql build toolchain and the composer
 * vendor tree. The container picks the bootable from
 * BOOTGLY_HTTP_SERVER_CLI_ROUTER (its entrypoint branches):
 *   techempower  -> workerman-techempower-postgres.php (PostgreSQL TFB loads)
 *   anything else -> workerman-routes.php              (generic route set)
 * — exactly the selection the native script used to do — and runs the server
 * in the FOREGROUND (the container IS the daemon; NO workerman `-d`).
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php workerman.php start
 *   php workerman.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.workerman -t bootgly-workerman \
 *     ../../bootables/workerman
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/workerman');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.workerman';
$image = 'bootgly-workerman';
$container = 'bench-workerman';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Honor the active router/load set — forwarded into the container so its
//   entrypoint serves the TechEmpower bootable vs the generic one.
$router = getenv('BOOTGLY_HTTP_SERVER_CLI_ROUTER') ?: '';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $workers, $router) {
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

      // # Worker count → SERVER_WORKER_NUM (the bootable's $http_worker->count);
      //   router → BOOTGLY_HTTP_SERVER_CLI_ROUTER (entrypoint bootable selector).
      exec(
         "docker run -d --network host --name {$container} "
         . "-e SERVER_WORKER_NUM={$workers} "
         . '-e BOOTGLY_HTTP_SERVER_CLI_ROUTER=' . escapeshellarg($router) . ' '
         . "{$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
