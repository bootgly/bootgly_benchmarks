<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Workerman Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Workerman bootable for benchmarking. The bootable is chosen from the
 * active load set (BENCHMARK_LOAD_SET, set by `--loads=<set>:<indexes>`):
 *   techempower   -> workerman-techempower-postgres.php (PostgreSQL TFB loads)
 *   anything else -> workerman-routes.php               (generic route set)
 *
 * Two run modes:
 *   - In-process (BOOTGLY_BENCH_INPROCESS=1, set inside the self-contained
 *     bootgly/bootgly_benchmarks:<opp> image): Workerman is installed natively, so
 *     the bootable is launched directly — no docker-in-docker.
 *   - Docker (env unset, host runner): runs the shared `bootgly-workerman` image
 *     with --network host.
 *
 * Usage:
 *   php workerman.php start
 *   php workerman.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/workerman');
$dockerfile = realpath(__DIR__ . '/../../..') . '/Dockerfile.workerman';
$image = 'bootgly-workerman';
$container = 'bench-workerman';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// # In-process when running inside the self-contained bench image (Workerman native).
$inprocess = getenv('BOOTGLY_BENCH_INPROCESS') === '1';

// @ Bootable from the active load set — techempower serves the TFB routes (PDO),
//   any other set serves the generic route set.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$bootable = $techempower ? 'workerman-techempower-postgres.php' : 'workerman-routes.php';

$action = $argv[1] ?? 'start';

match ($action) {
   'start' => (function () use ($bootablesDir, $dockerfile, $image, $container, $workers, $bootable, $inprocess) {
      // ? In-process: launch the bootable directly (the runner backgrounds this with `&`).
      if ($inprocess === true) {
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
         return;
      }

      // # Build the image once if it is not present yet.
      exec("docker image inspect {$image} > /dev/null 2>&1", $out, $code);
      if ($code !== 0) {
         exec("docker build -f {$dockerfile} -t {$image} {$bootablesDir} > /dev/null 2>&1");
      }

      // @ Remove any stale container, then run fresh.
      exec("docker rm -f {$container} > /dev/null 2>&1");

      // # DB env the bootable reads (DB_HOST/PORT/NAME/USER/PASS); host PG via host network.
      $db = sprintf(
         '-e DB_HOST=%s -e DB_PORT=%s -e DB_NAME=%s -e DB_USER=%s -e DB_PASS=%s',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "docker run -d --network host --name {$container} "
         . "-e SERVER_WORKER_NUM={$workers} {$db} {$image} > /dev/null 2>&1"
      );
   })(),

   'stop' => (function () use ($container, $port, $bootable, $inprocess) {
      // ? In-process: kill the bootable by argv pattern.
      if ($inprocess === true) {
         exec('pkill -9 -f ' . escapeshellarg($bootable) . ' > /dev/null 2>&1');
         exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
         return;
      }

      exec("docker rm -f {$container} > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
