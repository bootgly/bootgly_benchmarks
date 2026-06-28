<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — RoadRunner Opponent
 * --------------------------------------------------------------------------
 *
 * Runs RoadRunner (Spiral) in-process inside the self-contained bench image
 * (bootgly/bootgly_benchmarks:roadrunner), where the `rr` Go binary + its PHP
 * worker pool live natively — no docker-in-docker. `rr serve` runs in the
 * FOREGROUND (the runner backgrounds it with `&`); RoadRunner passes the parent
 * env down to the workers, so DB_* reaches the PDO in the TechEmpower worker.
 *
 * The worker is chosen from the active load set (BENCHMARK_LOAD_SET, set by
 * `--loads=<set>:<indexes>`): techempower -> worker-techempower.php (raw PDO,
 * the 7 TFB routes); any other set -> worker.php (generic route set, the .rr.yaml
 * default).
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:roadrunner test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,roadrunner --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php roadrunner.php start
 *   php roadrunner.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/roadrunner');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Worker from the active load set — techempower runs worker-techempower.php
//   (raw PDO TFB routes), any other set runs the .rr.yaml default (worker.php).
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The RoadRunner opponent runs only inside the bootgly/bootgly_benchmarks:roadrunner image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:roadrunner test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,roadrunner --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Serve in the foreground (the runner backgrounds it). For techempower swap the
   //   pool command to the TFB worker; otherwise keep the .rr.yaml default.
   'start' => (function () use ($bootablesDir, $workers, $techempower) {
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      $command = $techempower === true
         ? ' -o "server.command=php worker-techempower.php"'
         : '';

      exec(
         "cd {$bootablesDir} && {$db}"
         . "./rr serve -c .rr.yaml -o http.address=0.0.0.0:8082 "
         . "-o http.pool.num_workers={$workers}{$command} > /dev/null 2>&1"
      );
   })(),

   // @ Kill the rr supervisor (and its worker pool) by argv pattern, then free the port.
   'stop' => (function () use ($port) {
      exec("pkill -9 -f 'rr serve' > /dev/null 2>&1");
      exec("pkill -9 -f 'worker-techempower.php' > /dev/null 2>&1");
      exec("pkill -9 -f 'worker.php' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
