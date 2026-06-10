<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole (Base) Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop Swoole in SWOOLE_BASE mode for benchmarking.
 *
 * Usage:
 *   php swoole-base.php start
 *   php swoole-base.php stop
 */

$bootablesDir = __DIR__ . '/../../bootables/swoole';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $port, $workers) {
      exec(
         "cd {$bootablesDir} && SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . "php swoole-base-routes.php > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($port) {
      // Kill the master by process pattern first: it does not listen on the
      // port, so a port-only kill leaves it alive respawning workers that
      // steal benchmark connections via SO_REUSEPORT.
      exec("pkill -9 -f swoole-base-routes.php 2>/dev/null");
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
