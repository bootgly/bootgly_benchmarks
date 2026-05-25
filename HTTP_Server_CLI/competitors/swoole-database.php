<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks - HTTP_Server_CLI - Swoole Database Competitor
 * --------------------------------------------------------------------------
 *
 * Start/stop Swoole HTTP Server with native PDO PostgreSQL pool routes.
 *
 * Usage:
 *   php swoole-database.php start
 *   php swoole-database.php stop
 */

$artifactDir = __DIR__ . '/../artifacts/swoole';
$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($artifactDir, $port, $workers) {
      exec(
         "cd {$artifactDir} && SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . "php swoole-database-postgres.php > /dev/null 2>&1 &"
      );
   })(),

   'stop' => (function () use ($port) {
      exec("lsof -ti :{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
   })(),

   default => exit(1),
};