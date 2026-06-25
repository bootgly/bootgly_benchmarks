<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — FrankenPHP Opponent (Docker)
 * --------------------------------------------------------------------------
 *
 * Runs FrankenPHP (Worker Mode) inside a Docker container — kept off the host
 * to avoid polluting it with the frankenphp binary / php build. The container
 * runs `frankenphp run --config <Caddyfile>` in the FOREGROUND (the container
 * IS the daemon). The entrypoint picks the Caddyfile by router: when
 * BOOTGLY_HTTP_SERVER_CLI_ROUTER === 'techempower' it serves the TechEmpower
 * worker (index-techempower.php, raw PDO over PostgreSQL); otherwise it serves
 * the generic route worker (index.php). This reproduces the native script's
 * generic-vs-TechEmpower selection — now via the container entrypoint.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php frankenphp.php start
 *   php frankenphp.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.frankenphp -t bootgly-frankenphp \
 *     ../../bootables/frankenphp
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/frankenphp');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.frankenphp';
$image = 'bootgly-frankenphp';
$container = 'bench-frankenphp';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Honor the active router/load set. The TechEmpower loads (/plaintext, /json,
//   /db, ...) are not in the generic router set, so when ROUTER=techempower the
//   container entrypoint runs the TechEmpower worker via Caddyfile.techempower.
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

      // # TechEmpower worker DB env (host PG via host network) — the worker reads
      //   DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS directly.
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      // # Forward worker count + router selector; the Caddyfiles read
      //   FRANKENPHP_NUM_WORKERS, the entrypoint branches on the router.
      exec(
         "docker run -d --network host --name {$container} "
         . "-e FRANKENPHP_NUM_WORKERS={$workers} "
         . "-e BOOTGLY_HTTP_SERVER_CLI_ROUTER=" . escapeshellarg($router) . " "
         . "{$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
