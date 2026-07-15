<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Bootgly Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop the Bootgly HTTP Server for benchmarking.
 *
 * Usage:
 *   php bootgly.php start
 *   php bootgly.php stop
 */

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

// @ BOOTGLY_DIR env overrides the default sibling checkout (e.g. to benchmark
//   a git worktree of bootgly without touching the main working copy).
$bootglyDir = getenv('BOOTGLY_DIR') ?: __DIR__ . '/../../../../bootgly';

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   'start' => (function () use ($bootglyDir): int {
      // @ Stop any stale instance
      exec("php {$bootglyDir}/bootgly project Benchmark/HTTP_Server_CLI stop > /dev/null 2>&1");
      usleep(500_000);

      // ! Server env prefix
      $env = '';

      // # Workers (A/B override)
      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers !== false) {
         $env .= "BOOTGLY_WORKERS={$workers} ";
      }

      // @ Start server via bootgly project command. The server derives its router
      //   from the active load set (BENCHMARK_LOAD_SET), inherited from this env.
      return ServerCapture::run("{$env}php {$bootglyDir}/bootgly project Benchmark/HTTP_Server_CLI start");
   })(),

   'stop' => (function () use ($bootglyDir): int {
      exec("php {$bootglyDir}/bootgly project Benchmark/HTTP_Server_CLI stop > /dev/null 2>&1");
      return 0;
   })(),

   default => 1,
};

exit($exit);
