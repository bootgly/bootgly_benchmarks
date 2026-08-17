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

// # Delayed upstream backing the `deferred_fanout` load. Only the `benchmark`
//   load set routes to it; TechEmpower has no fan-out scenario.
$upstreamScript = __DIR__ . '/../../artifacts/@upstream/upstream.php';
$upstreamSocket = getenv('BENCHMARK_UPSTREAM_SOCKET') ?: '/tmp/bootgly-upstream.sock';
$upstreamDelay = getenv('BENCHMARK_UPSTREAM_DELAY') ?: '10';
$upstreamWanted = strtolower((string) getenv('BENCHMARK_LOAD_SET')) === 'benchmark';

$Upstream = static function (string $action) use ($upstreamScript, $upstreamSocket, $upstreamDelay): void {
   $script = escapeshellarg($upstreamScript);
   $socket = escapeshellarg("--socket={$upstreamSocket}");

   // @ Always reclaim first: a run killed without its stop hook leaves a
   //   listener holding the path, and it would answer the next run with the
   //   PREVIOUS delay — a silently mismeasured case instead of a failed one.
   exec("php {$script} {$socket} stop > /dev/null 2>&1");

   if ($action === 'stop') {
      return;
   }

   $delay = escapeshellarg('--delay=' . $upstreamDelay);
   exec("php {$script} {$socket} {$delay} > /dev/null 2>&1 &");
   usleep(300_000);
};

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   'start' => (function () use ($bootglyDir, $Upstream, $upstreamWanted): int {
      // @ Stop any stale instance
      exec("php {$bootglyDir}/bootgly project Benchmark/HTTP_Server_CLI stop > /dev/null 2>&1");
      usleep(500_000);

      if ($upstreamWanted) {
         $Upstream('start');
      }

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

   'stop' => (function () use ($bootglyDir, $Upstream): int {
      // @ Capture instead of discarding: a silently failed stop is exactly how
      //   a surviving master reaches the next opponent, where it keeps the
      //   benchmark port bound and surfaces as an unrelated
      //   `Address already in use` instead of the real cause.
      $output = [];
      $status = 0;
      exec(
         "php {$bootglyDir}/bootgly project Benchmark/HTTP_Server_CLI stop 2>&1",
         $output,
         $status
      );

      // ! Unconditional: the load set that started it is not guaranteed to be
      //   readable here, and stopping an absent upstream is a no-op.
      $Upstream('stop');

      // ? `stop` also exits non-zero for an already-absent project, so its
      //   status alone cannot separate "nothing to stop" from "could not
      //   stop" — and the runner turns any non-zero into a fatal error. The
      //   bound port is the authority: only an actual survivor fails here.
      $port = (int) (getenv('BENCHMARK_PORT') ?: 8082);
      $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1.0);
      if ($socket === false) {
         return 0;
      }
      fclose($socket);

      fwrite(STDERR, implode("\n", $output) . "\n");
      fwrite(STDERR, "The Bootgly opponent still holds port {$port} after stop.\n");

      return $status !== 0 ? $status : 1;
   })(),

   default => 1,
};

exit($exit);
