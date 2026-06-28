<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole (Base) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs Swoole in SWOOLE_BASE mode in-process inside the self-contained bench
 * image (bootgly/bootgly_benchmarks:swoole), where Swoole is installed natively
 * — no docker-in-docker.
 *
 * The bootable is derived from the active load set (BENCHMARK_LOAD_SET, set by
 * `--loads=<set>:<indexes>`): techempower -> swoole-techempower-postgres.php
 * (the 7 TFB routes, per-worker PDO pool) still in SWOOLE_BASE mode
 * (SWOOLE_SERVER_MODE=base); any other set -> swoole-base-routes.php.
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,swoole-base --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php swoole-base.php start
 *   php swoole-base.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/swoole');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);

// @ Bootable + mode from the active load set — techempower runs the TFB bootable
//   in SWOOLE_BASE mode; any other set runs the generic base-routes bootable.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$bootable = $techempower ? 'swoole-techempower-postgres.php' : 'swoole-base-routes.php';
$mode = $techempower ? 'base' : '';

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The Swoole (Base) opponent runs only inside the bootgly/bootgly_benchmarks:swoole image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,swoole-base --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Launch the bootable directly (the runner backgrounds this with `&`).
   'start' => (function () use ($bootablesDir, $port, $workers, $bootable, $mode) {
      // # TechEmpower DB env (local PG) — only read by the TechEmpower bootable.
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );
      $modeEnv = $mode !== '' ? "SWOOLE_SERVER_MODE={$mode} " : '';

      exec(
         "cd {$bootablesDir} && {$db}{$modeEnv}"
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
