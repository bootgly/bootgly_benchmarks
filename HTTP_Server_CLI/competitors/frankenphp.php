<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — FrankenPHP Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop FrankenPHP (Worker Mode) for benchmarking.
 *
 * Usage:
 *   php frankenphp.php start
 *   php frankenphp.php stop
 */

$artifactDir = realpath(__DIR__ . '/../artifacts/frankenphp');
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($artifactDir, $port, $workers) {
      exec(
         "cd {$artifactDir} && "
         . "FRANKENPHP_PORT={$port} FRANKENPHP_DIR={$artifactDir} FRANKENPHP_NUM_WORKERS={$workers} "
         . "frankenphp run --config {$artifactDir}/Caddyfile > /dev/null 2>&1 &"
      );
   })(),

   'stop' => (function () use ($port) {
      // FrankenPHP spawns multiple worker processes — kill all by name, then by port
      exec("pkill -9 -f 'frankenphp run' 2>/dev/null");
      usleep(500_000);
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};
