<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel + OpenLiteSpeed + LSCache
 * --------------------------------------------------------------------------
 *
 * Runs the Laravel app under OpenLiteSpeed (with the LSCache module) inside a
 * Docker container — kept off the host to avoid polluting it with OLS/lsphp.
 * The deterministic routes (/plaintext, /json, /fortunes) are served from
 * LSCache without touching PHP; the rest hit lsphp + PostgreSQL.
 *
 * Container uses --network host: binds :8082 directly and reaches host
 * PostgreSQL at 127.0.0.1:5432 (no NAT — fair).
 *
 * Usage:
 *   php laravel-ols.php start
 *   php laravel-ols.php stop
 *
 * Requires a running Docker daemon. The image is built once (if missing) on
 * first start — pre-build it before a sweep so the first round is not stalled:
 *   docker build -f ../../../Dockerfile.laravel-ols -t bootgly-laravel-ols \
 *     ../../bootables/laravel
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.laravel-ols';
$image = 'bootgly-laravel-ols';
$container = 'bench-laravel-ols';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $workers) {
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

      exec("docker run -d --network host --name {$container} -e BOOTGLY_WORKERS={$workers} {$db} {$image} > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($container, $port) {
      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
