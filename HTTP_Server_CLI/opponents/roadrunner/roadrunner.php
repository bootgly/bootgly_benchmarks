<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — RoadRunner Opponent
 * --------------------------------------------------------------------------
 *
 * Runs RoadRunner (Spiral) inside a Docker container — the `rr` Go binary and
 * its PHP worker pool are kept off the host. The container runs `rr serve` in
 * the FOREGROUND (the container IS the daemon).
 *
 * Generic vs TechEmpower is selected exactly as the native runner did, via the
 * BOOTGLY_HTTP_SERVER_CLI_ROUTER env: forwarded into the container, the image
 * entrypoint branches on it to pick worker.php (generic) vs
 * worker-techempower.php (`-o server.command=php worker-techempower.php`).
 * RoadRunner passes the parent (container) env down to the workers, so the
 * forwarded DB_* vars reach the PDO in the TechEmpower worker.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php roadrunner.php start
 *   php roadrunner.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.roadrunner -t bootgly-roadrunner \
 *     ../../bootables/roadrunner
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/roadrunner');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.roadrunner';
$image = 'bootgly-roadrunner';
$container = 'bench-roadrunner';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Honor the active router/load set. The entrypoint branches on this env to
//   run worker-techempower.php (TechEmpower loads) vs worker.php (generic).
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

      // # DB env (Bootgly DB_* names, read by worker-techempower.php's PDO);
      //   host PostgreSQL reached via the host network.
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      // # Forward the router selector so the entrypoint picks the right worker.
      $routerEnv = '-e BOOTGLY_HTTP_SERVER_CLI_ROUTER=' . escapeshellarg($router);

      exec("docker run -d --network host --name {$container} -e BOOTGLY_WORKERS={$workers} {$routerEnv} {$db} {$image} > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
