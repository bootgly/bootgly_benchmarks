<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — UDP_Server_CLI — Bootgly Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop the Bootgly UDP Server for benchmarking.
 *
 * Usage:
 *   php bootgly.php start
 *   php bootgly.php stop
 */

$bootglyDir = __DIR__ . '/../../../bootgly';
$port = getenv('BENCHMARK_PORT') ?: '8084';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootglyDir, $port) {
      // @ Stop any stale instance
      exec("php {$bootglyDir}/bootgly project Benchmark --UDP_Server_CLI stop > /dev/null 2>&1");
      usleep(500_000);

      // @ Start server via bootgly project command
      $env = "PORT={$port} ";
      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers !== false) {
         $env .= "BOOTGLY_WORKERS={$workers} ";
      }
      exec("{$env}php {$bootglyDir}/bootgly project Benchmark --UDP_Server_CLI start > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($bootglyDir) {
      exec("php {$bootglyDir}/bootgly project Benchmark --UDP_Server_CLI stop > /dev/null 2>&1");
   })(),

   default => exit(1),
};
