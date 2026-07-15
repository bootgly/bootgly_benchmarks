<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — UDP_Server_CLI — Bootgly Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop the Bootgly UDP Server for benchmarking.
 *
 * Usage:
 *   php bootgly.php start
 *   php bootgly.php stop
 */

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

// @ BOOTGLY_DIR env overrides the default sibling checkout.
$bootglyDir = getenv('BOOTGLY_DIR') ?: __DIR__ . '/../../../../bootgly';
$port = getenv('BENCHMARK_PORT') ?: '8084';

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   'start' => (function () use ($bootglyDir, $port): int {
      // @ Stop any stale instance
      exec("php {$bootglyDir}/bootgly project Benchmark/UDP_Server_CLI stop > /dev/null 2>&1");
      usleep(500_000);

      // @ Start server via bootgly project command
      $env = "PORT={$port} ";
      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers !== false) {
         $env .= "BOOTGLY_WORKERS={$workers} ";
      }
      return ServerCapture::run("{$env}php {$bootglyDir}/bootgly project Benchmark/UDP_Server_CLI start");
   })(),

   'stop' => (function () use ($bootglyDir): int {
      exec("php {$bootglyDir}/bootgly project Benchmark/UDP_Server_CLI stop > /dev/null 2>&1");
      return 0;
   })(),

   default => 1,
};

exit($exit);
