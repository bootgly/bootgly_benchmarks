<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole (Base) Opponent
 * --------------------------------------------------------------------------
 *
 * Start/stop Swoole in SWOOLE_BASE mode for benchmarking.
 *
 * Usage:
 *   php swoole-base.php start
 *   php swoole-base.php stop
 */

$bootablesDir = __DIR__ . '/../../bootables/swoole';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

// @ Honor the active router/load set. The TechEmpower loads (/plaintext, /json,
//   /db, ...) are not in the base router set, so when ROUTER=techempower run the
//   TechEmpower bootable — in SWOOLE_BASE mode, so this opponent stays base-mode.
$techempower = getenv('BOOTGLY_HTTP_SERVER_CLI_ROUTER') === 'techempower';
$bootable = $techempower ? 'swoole-techempower-postgres.php' : 'swoole-base-routes.php';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $port, $workers, $bootable, $techempower) {
      $mode = $techempower ? 'SWOOLE_SERVER_MODE=base ' : '';
      exec(
         "cd {$bootablesDir} && SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . "{$mode}php {$bootable} > /dev/null 2>&1 &"
      );
   })(),

   'stop' => (function () use ($port, $bootable) {
      // Kill the master by process pattern first: it does not listen on the
      // port, so a port-only kill leaves it alive respawning workers that
      // steal benchmark connections via SO_REUSEPORT.
      exec("pkill -9 -f {$bootable} 2>/dev/null");
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
