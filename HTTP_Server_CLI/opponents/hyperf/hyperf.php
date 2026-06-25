<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Hyperf Opponent
 * --------------------------------------------------------------------------
 *
 * Runs Hyperf (Swoole coroutine engine) inside a Docker container — kept off
 * the host to avoid polluting it with swoole/pdo_pgsql and the hyperf vendor.
 * Hyperf serves both the TechEmpower routes and the generic route set from a
 * single config/routes.php, so no bootable swap is needed; the same server
 * answers both. BOOTGLY_HTTP_SERVER_CLI_ROUTER is forwarded harmlessly for
 * parity with the other opponents.
 *
 * Container uses --network host: the server binds :8082 directly and reaches
 * host PostgreSQL at 127.0.0.1:5432 (no NAT — fair). The container runs the
 * server in the FOREGROUND (the container IS the daemon) — SERVER_DAEMONIZE is
 * intentionally NOT set.
 *
 * Usage:
 *   php hyperf.php start
 *   php hyperf.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.hyperf -t bootgly-hyperf \
 *     ../../bootables/hyperf
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/hyperf');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.hyperf';
$image = 'bootgly-hyperf';
$container = 'bench-hyperf';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Forward the active router/load set so the container has the same env the
//   native bootable read. Hyperf serves both sets from one routes.php, so this
//   does not swap a bootable — it is forwarded for parity only.
$router = getenv('BOOTGLY_HTTP_SERVER_CLI_ROUTER') ?: '';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $port, $workers, $router) {
      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # Hyperf server + DB env (Bootgly DB_* names, read directly by the app);
      //   host PG via host network. No SERVER_DAEMONIZE — foreground container.
      $env = sprintf(
         '-e SERVER_PORT=%s -e SERVER_WORKER_NUM=%s -e BOOTGLY_HTTP_SERVER_CLI_ROUTER=%s '
         . '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg($port),
         escapeshellarg($workers),
         escapeshellarg($router),
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec("docker run -d --network host --name {$container} {$env} {$image} > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
