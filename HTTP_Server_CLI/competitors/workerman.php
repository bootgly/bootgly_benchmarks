<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Workerman Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop Workerman for benchmarking.
 *
 * Usage:
 *   php workerman.php start
 *   php workerman.php stop
 */

$artifactDir = __DIR__ . '/../artifacts/workerman';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($artifactDir, $port, $workers) {
      exec(
         "cd {$artifactDir} && SERVER_WORKER_NUM={$workers} "
         . "php workerman-routes.php start -d > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($artifactDir, $port) {
      exec("cd {$artifactDir} && php workerman-routes.php stop > /dev/null 2>&1");
      sleep(1);
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
