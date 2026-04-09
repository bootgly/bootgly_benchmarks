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

$artifactDir = __DIR__ . '/../artifacts/swoole';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($artifactDir, $port, $workers) {
      exec(
         "cd {$artifactDir} && SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . "php swoole-coroutine-routes.php > /dev/null 2>&1 &"
      );
   })(),

   'stop' => (function () use ($artifactDir, $port) {
      $pidfile = $artifactDir . '/swoole-coroutine.pid';
      if (file_exists($pidfile)) {
         $pid = trim(file_get_contents($pidfile));
         exec("kill -- -{$pid} 2>/dev/null || kill {$pid} 2>/dev/null");
         @unlink($pidfile);
      }
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
