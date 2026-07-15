<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — WS_Server_CLI — Bootgly Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop the Bootgly WebSocket Server for benchmarking.
 *
 * Usage:
 *   php bootgly.php start
 *   php bootgly.php stop
 */

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

// @ BOOTGLY_DIR env overrides the default sibling checkout.
$bootglyDir = getenv('BOOTGLY_DIR') ?: __DIR__ . '/../../../../bootgly';
$port = getenv('BENCHMARK_PORT') ?: '8085';

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   'start' => (function () use ($bootglyDir, $port): int {
      // @ Stop any stale instance
      exec("php {$bootglyDir}/bootgly project Benchmark/WS_Server_CLI stop > /dev/null 2>&1");
      usleep(500_000);

      // ! Server env prefix
      $env = "PORT={$port} ";

      // # Workers (A/B override)
      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers !== false) {
         $env .= "BOOTGLY_WORKERS={$workers} ";
      }

      // # Server mode — derive from the active load set so the server behaves the
      //   way the client load expects. `broadcast` fans each frame out to a room;
      //   `echo` and `connect` both run against the echo server (connect only
      //   handshakes, so it needs no special server behavior). An explicit
      //   BENCH_WS_MODE wins.
      $wsMode = getenv('BENCH_WS_MODE');
      if ($wsMode === false) {
         $loadSet = strtolower(getenv('BENCHMARK_LOAD_SET') ?: 'echo');
         $wsMode = match ($loadSet) {
            'broadcast' => 'broadcast',
            default     => 'echo',
         };
      }
      $env .= "BENCH_WS_MODE={$wsMode} ";

      // @ Start server via bootgly project command
      return ServerCapture::run("{$env}php {$bootglyDir}/bootgly project Benchmark/WS_Server_CLI start");
   })(),

   'stop' => (function () use ($bootglyDir): int {
      exec("php {$bootglyDir}/bootgly project Benchmark/WS_Server_CLI stop > /dev/null 2>&1");
      return 0;
   })(),

   default => 1,
};

exit($exit);
