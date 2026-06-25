<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel Octane (Swoole) Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop the Laravel app served by Octane on Swoole — persistent
 * in-memory workers (no per-request bootstrap), the fast Laravel stack.
 *
 * Usage:
 *   php laravel-octane.php start
 *   php laravel-octane.php stop
 *
 * Environment variables:
 *   BENCHMARK_PORT   — port to listen on (default: 8082)
 *   BOOTGLY_WORKERS  — Octane HTTP workers (default: nproc / 2)
 *
 * Workers map 1:1 to server-workers, so each Octane worker holds one
 * persistent Eloquent/PDO connection — matching the pooled servers'
 * DB_POOL_MAX=1 per-worker database footprint.
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/laravel');
$runDir = $bootablesDir . '/run';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $runDir, $port, $workers) {
      @mkdir($runDir, 0777, true);

      // Persistent Swoole workers. --max-requests very high so workers never
      // recycle mid-benchmark (a recycle would cold-restart that worker).
      // XDEBUG_MODE=off keeps the zend executor (and JIT) intact.
      exec(
         "cd {$bootablesDir} && XDEBUG_MODE=off APP_ENV=production "
         . "php artisan octane:start --server=swoole --host=0.0.0.0 --port={$port} "
         . "--workers={$workers} --max-requests=100000000 "
         . "> {$runDir}/octane.log 2>&1 &"
      );
   })(),

   'stop' => (function () use ($bootablesDir, $port) {
      exec("cd {$bootablesDir} && php artisan octane:stop > /dev/null 2>&1");
      usleep(400_000);

      // Fallbacks: the octane master, then anything still on the port.
      exec("pkill -9 -f 'octane:start' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
