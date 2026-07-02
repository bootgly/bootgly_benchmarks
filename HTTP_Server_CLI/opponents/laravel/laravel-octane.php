<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel Octane (Swoole) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Laravel app served by Octane on Swoole in-process inside the
 * self-contained bench image (bootgly/bootgly_benchmarks:laravel-octane), where
 * swoole + pdo_pgsql and the Laravel vendor tree live natively — no
 * docker-in-docker. Octane runs persistent in-memory workers (no per-request
 * bootstrap), the fast Laravel stack. The server runs in the FOREGROUND (the
 * runner backgrounds it with `&`); --max-requests is very high so workers never
 * recycle mid-benchmark.
 *
 * Workers map 1:1 to server-workers, so each Octane worker holds one persistent
 * Eloquent/PDO connection — matching the pooled servers' DB_POOL_MAX=1 per-worker
 * database footprint.
 *
 * Zero-setup path — the self-contained Docker image; native host runs also work
 * when the swoole extension + the Laravel vendor tree are installed:
 *   docker run --rm bootgly/bootgly_benchmarks:laravel-octane test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,laravel-octane --loads=techempower:*
 *
 * Usage (invoked by the runner):
 *   php laravel-octane.php start
 *   php laravel-octane.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// ? Capability guard — the swoole extension + the Laravel vendor tree must be
//   available. Always true inside the self-contained bench image; on a bare host,
//   install swoole and run `composer install` in bootables/laravel, or use the image.
if (extension_loaded('swoole') === false || is_file("{$bootablesDir}/vendor/autoload.php") === false) {
   fwrite(STDERR,
      "The Laravel Octane opponent requires the swoole PHP extension and its Composer "
      . "dependencies (bootables/laravel/vendor).\n"
      . "Install them natively or use the self-contained image: "
      . "docker run --rm bootgly/bootgly_benchmarks:laravel-octane test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,laravel-octane --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Start Octane on Swoole in the foreground (the runner backgrounds it). Bootgly
   //   DB_* are mapped to Laravel's DB_* names; production env, Xdebug off.
   'start' => (function () use ($bootablesDir, $port, $workers) {
      $db = sprintf(
         'DB_CONNECTION=pgsql DB_HOST=%s DB_PORT=%s DB_DATABASE=%s DB_USERNAME=%s DB_PASSWORD=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "cd {$bootablesDir} && {$db}APP_ENV=production XDEBUG_MODE=off "
         . "php artisan octane:start --server=swoole --host=0.0.0.0 "
         . "--port={$port} --workers={$workers} --max-requests=100000000 > /dev/null 2>&1"
      );
   })(),

   // @ Kill the Octane master (which brings down its Swoole workers), then free the port.
   'stop' => (function () use ($port) {
      exec("pkill -9 -f 'octane:start' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
