<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — ReactPHP Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the ReactPHP bootable in-process inside the self-contained bench image
 * (bootgly/bootgly_benchmarks:reactphp), where react/* and voryx/pgasync are
 * vendored natively — no docker-in-docker. The async PostgreSQL client speaks
 * the wire protocol over a TCP socket (pure PHP, no pdo_pgsql).
 *
 * TechEmpower only — there is no generic router branch: this opponent always
 * runs reactphp-techempower.php in the FOREGROUND (the runner backgrounds it
 * with `&`). It forks BOOTGLY_WORKERS children (each its own event loop on :8082).
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:reactphp test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,reactphp --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php reactphp.php start
 *   php reactphp.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/reactphp');
$bootable = 'reactphp-techempower.php';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The ReactPHP opponent runs only inside the bootgly/bootgly_benchmarks:reactphp image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:reactphp test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,reactphp --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Launch the async TechEmpower server in the foreground (the runner backgrounds it).
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
         "cd {$bootablesDir} && {$db}BOOTGLY_WORKERS={$workers} "
         . "php {$bootable} > /dev/null 2>&1"
      );
   })(),

   // @ Kill the bootable (and its forked workers) by argv pattern, then free the port.
   'stop' => (function () use ($port, $bootable) {
      exec('pkill -9 -f ' . escapeshellarg($bootable) . ' > /dev/null 2>&1');
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
