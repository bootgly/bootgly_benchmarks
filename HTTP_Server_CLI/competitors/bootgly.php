<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Bootgly Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop the Bootgly HTTP Server for benchmarking.
 *
 * Usage:
 *   php bootgly.php start
 *   php bootgly.php stop
 */

$bootglyDir = __DIR__ . '/../../../bootgly';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootglyDir) {
      // @ Stop any stale instance
      exec("php {$bootglyDir}/bootgly project Benchmark --HTTP_Server_CLI stop > /dev/null 2>&1");
      usleep(500_000);

      // @ Start server via bootgly project command
      $env = '';
      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers !== false) {
         $env = "BOOTGLY_WORKERS={$workers} ";
      }
      exec("{$env}php {$bootglyDir}/bootgly project Benchmark --HTTP_Server_CLI start > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($bootglyDir) {
      exec("php {$bootglyDir}/bootgly project Benchmark --HTTP_Server_CLI stop > /dev/null 2>&1");
   })(),

   default => exit(1),
};
