<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel (nginx + PHP-FPM) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Laravel app under nginx → PHP-FPM 8.4 (per-request mode) inside a
 * Docker container — kept off the host to avoid polluting it with nginx/FPM.
 * Every request is passed straight to public/index.php over a unix fastcgi
 * socket; /db, /query, /updates hit PHP-FPM + PostgreSQL (TFB semantics).
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php laravel-nginx.php start
 *   php laravel-nginx.php stop
 *
 * Environment variables (forwarded into the container):
 *   BENCHMARK_PORT                 — port to listen on (default: 8082)
 *   BOOTGLY_WORKERS                — php-fpm pm.max_children (default: nproc/2)
 *   BOOTGLY_HTTP_SERVER_CLI_ROUTER — generic vs techempower set (parity only;
 *                                    Laravel serves the same web.php routes)
 *   DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS — mapped to Laravel DB_* names
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.laravel-nginx -t bootgly-laravel-nginx \
 *     ../../bootables/laravel
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.laravel-nginx';
$image = 'bootgly-laravel-nginx';
$container = 'bench-laravel-nginx';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// PHP-FPM is per-request (1 child = 1 blocking request), so the pool needs a
// real process count. BOOTGLY_WORKERS sets pm.max_children in the container;
// FPM_MAX_CHILDREN overrides it if a larger pool is wanted (keep < PG max).
$children = getenv('FPM_MAX_CHILDREN') ?: $workers;

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $children) {
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

      // # Generic vs TechEmpower selection (parity with the other Docker
      //   opponents); the entrypoint branches on this env.
      $router = getenv('BOOTGLY_HTTP_SERVER_CLI_ROUTER') ?: 'techempower';

      exec(
         "docker run -d --network host --name {$container} " .
         "-e BOOTGLY_WORKERS={$children} " .
         "-e BOOTGLY_HTTP_SERVER_CLI_ROUTER=" . escapeshellarg($router) . ' ' .
         "-e XDEBUG_MODE=off -e APP_ENV=production {$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
