<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Hyperf Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop Hyperf (Swoole engine, daemonized) for benchmarking.
 *
 * Usage:
 *   php hyperf.php start
 *   php hyperf.php stop
 */

$artifactDir = __DIR__ . '/../artifacts/hyperf';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($artifactDir, $port, $workers) {
      exec(
         "cd {$artifactDir} && "
         . "SERVER_PORT={$port} SERVER_WORKER_NUM={$workers} SERVER_DAEMONIZE=1 "
         . "php -d swoole.use_shortname=Off bin/hyperf.php start > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($port) {
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
