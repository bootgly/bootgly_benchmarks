<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel Octane (Swoole) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Laravel app served by Octane on Swoole inside a Docker container —
 * persistent in-memory workers (no per-request bootstrap), the fast Laravel
 * stack. Kept off the host to avoid polluting it with Swoole/Octane.
 *
 * Workers map 1:1 to server-workers, so each Octane worker holds one
 * persistent Eloquent/PDO connection — matching the pooled servers'
 * DB_POOL_MAX=1 per-worker database footprint.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php laravel-octane.php start
 *   php laravel-octane.php stop
 *
 * Environment variables:
 *   BENCHMARK_PORT                  — port to listen on (default: 8082)
 *   BOOTGLY_WORKERS                 — Octane HTTP workers (default: nproc / 2)
 *   BOOTGLY_HTTP_SERVER_CLI_ROUTER  — forwarded for generic-vs-TechEmpower parity
 *   DB_HOST/DB_PORT/DB_NAME/...     — host PostgreSQL (mapped to Laravel DB_*)
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.laravel-octane -t bootgly-laravel-octane \
 *     ../../bootables/laravel
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.laravel-octane';
$image = 'bootgly-laravel-octane';
$container = 'bench-laravel-octane';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $port, $workers) {
      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # Laravel DB env (Bootgly DB_* → Laravel names); host PG via host network.
      $db = sprintf(
         '-e DB_CONNECTION=pgsql -e DB_HOST=%s -e DB_PORT=%s -e DB_DATABASE=%s -e DB_USERNAME=%s -e DB_PASSWORD=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      // # Router parity: forward the active set so the container can serve the
      //   TechEmpower bootable vs the generic one — exactly how the native
      //   opponents branch. The Laravel app exposes one fixed route set, so
      //   both selections resolve to the same app; the env is forwarded anyway
      //   to keep the contract identical across opponents.
      $router = getenv('BOOTGLY_HTTP_SERVER_CLI_ROUTER');
      $routerEnv = $router !== false
         ? '-e BOOTGLY_HTTP_SERVER_CLI_ROUTER=' . escapeshellarg($router) . ' '
         : '';

      exec(
         "docker run -d --network host --name {$container} "
         . "-e BOOTGLY_WORKERS={$workers} -e BENCHMARK_PORT={$port} "
         . "-e XDEBUG_MODE=off -e APP_ENV=production {$routerEnv}{$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
