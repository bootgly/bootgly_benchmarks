<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole (Coroutine) Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop Swoole Coroutine HTTP Server for benchmarking.
 *
 * Usage:
 *   php swoole-coroutine.php start
 *   php swoole-coroutine.php stop
 */

$bootablesDir = __DIR__ . '/../../bootables/swoole';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $port, $workers) {
      exec(
         "cd {$bootablesDir} && SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . "php swoole-coroutine-routes.php > /dev/null 2>&1 &"
      );
   })(),

   'stop' => (function () use ($bootablesDir, $port) {
      $pidfile = $bootablesDir . '/swoole-coroutine.pid';
      if (file_exists($pidfile)) {
         $pid = trim(file_get_contents($pidfile));
         exec("kill -- -{$pid} 2>/dev/null || kill {$pid} 2>/dev/null");
         @unlink($pidfile);
      }
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
