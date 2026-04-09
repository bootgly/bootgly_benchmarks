<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — RoadRunner Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop RoadRunner for benchmarking.
 *
 * Usage:
 *   php roadrunner.php start
 *   php roadrunner.php stop
 */

$artifactDir = __DIR__ . '/../artifacts/roadrunner';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($artifactDir, $port, $workers) {
      exec(
         "cd {$artifactDir} && "
         . "./rr serve -p -s -c .rr.yaml "
         . "-o \"http.pool.num_workers={$workers}\" "
         . "-o \"http.address=0.0.0.0:{$port}\" "
         . "> /dev/null 2>&1 &"
      );
   })(),

   'stop' => (function () use ($artifactDir, $port) {
      exec("cd {$artifactDir} && ./rr stop -f > /dev/null 2>&1");
      sleep(1);
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
