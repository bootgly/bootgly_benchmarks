<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks - HTTP_Server_CLI - Swoole TechEmpower Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Swoole HTTP Server serving the canonical TechEmpower routes
 * (/plaintext, /json, /db, /query, /fortunes, /updates, /cached-queries)
 * in-process inside the self-contained bench image
 * (bootgly/bootgly_benchmarks:swoole), where Swoole is installed natively — no
 * docker-in-docker. Backed by the native per-worker PDO PostgreSQL pool.
 *
 * Runs in SWOOLE_BASE (SWOOLE_SERVER_MODE=base): every worker accepts its own
 * connections via SO_REUSEPORT, which scales keep-alive throughput far better
 * than PROCESS mode's single master dispatcher (+~27% on /plaintext, /json)
 * while leaving the per-worker PDO pool — and the DB routes — unchanged. The
 * bootable self-daemonizes; the runner backgrounds the start with `&`.
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,swoole-techempower --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php swoole-techempower.php start
 *   php swoole-techempower.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/swoole');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

$bootable = 'swoole-techempower-postgres.php';

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The Swoole TechEmpower opponent runs only inside the bootgly/bootgly_benchmarks:swoole image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,swoole-techempower --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Launch the bootable directly in SWOOLE_BASE mode (the runner backgrounds it).
   'start' => (function () use ($bootablesDir, $port, $workers, $bootable) {
      // # TechEmpower DB env (local PG) read by the bootable's PDO pool.
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "cd {$bootablesDir} && {$db}SWOOLE_SERVER_MODE=base "
         . "SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . "php {$bootable} > /dev/null 2>&1"
      );
   })(),

   // @ Kill the bootable by argv pattern, then free the port.
   'stop' => (function () use ($port, $bootable) {
      exec('pkill -9 -f ' . escapeshellarg($bootable) . ' > /dev/null 2>&1');
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
