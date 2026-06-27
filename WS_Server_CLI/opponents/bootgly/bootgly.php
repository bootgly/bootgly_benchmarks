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

// @ BOOTGLY_DIR env overrides the default sibling checkout.
$bootglyDir = getenv('BOOTGLY_DIR') ?: __DIR__ . '/../../../../bootgly';
$port = getenv('BENCHMARK_PORT') ?: '8085';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootglyDir, $port) {
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
      exec("{$env}php {$bootglyDir}/bootgly project Benchmark/WS_Server_CLI start > /dev/null 2>&1");
   })(),

   'stop' => (function () use ($bootglyDir) {
      exec("php {$bootglyDir}/bootgly project Benchmark/WS_Server_CLI stop > /dev/null 2>&1");
   })(),

   default => exit(1),
};
