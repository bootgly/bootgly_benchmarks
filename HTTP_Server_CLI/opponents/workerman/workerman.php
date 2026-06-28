<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Workerman Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Workerman bootable in-process inside the self-contained bench image
 * (bootgly/bootgly_benchmarks:workerman), where Workerman is installed natively
 * — no docker-in-docker. The bootable is chosen from the active load set
 * (BENCHMARK_LOAD_SET, set by `--loads=<set>:<indexes>`):
 *   techempower   -> workerman-techempower-postgres.php (PostgreSQL TFB loads)
 *   anything else -> workerman-routes.php               (generic route set)
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:workerman test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,workerman --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php workerman.php start
 *   php workerman.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/workerman');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Bootable from the active load set — techempower serves the TFB routes (PDO),
//   any other set serves the generic route set.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$bootable = $techempower ? 'workerman-techempower-postgres.php' : 'workerman-routes.php';

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The Workerman opponent runs only inside the bootgly/bootgly_benchmarks:workerman image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:workerman test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,workerman --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Launch the bootable directly (the runner backgrounds this with `&`).
   'start' => (function () use ($bootablesDir, $workers, $bootable) {
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "cd {$bootablesDir} && {$db}SERVER_WORKER_NUM={$workers} "
         . "php {$bootable} start > /dev/null 2>&1"
      );
   })(),

   // @ Kill the bootable by argv pattern, then free the port.
   'stop' => (function () use ($port, $bootable) {
      exec('pkill -9 -f ' . escapeshellarg($bootable) . ' > /dev/null 2>&1');
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
