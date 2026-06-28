<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — FrankenPHP Opponent
 * --------------------------------------------------------------------------
 *
 * Runs FrankenPHP (Worker Mode — Caddy + embedded PHP) in-process inside the
 * self-contained bench image (bootgly/bootgly_benchmarks:frankenphp), where the
 * frankenphp binary lives natively — no docker-in-docker. `frankenphp run` runs
 * in the FOREGROUND (the runner backgrounds it with `&`).
 *
 * The Caddyfile is chosen from the active load set (BENCHMARK_LOAD_SET, set by
 * `--loads=<set>:<indexes>`): techempower -> Caddyfile.techempower (the 7 TFB
 * routes worker, index-techempower.php, raw PDO over PostgreSQL); any other set
 * -> Caddyfile (generic route worker, index.php).
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:frankenphp test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,frankenphp --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php frankenphp.php start
 *   php frankenphp.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/frankenphp');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Caddyfile from the active load set — techempower serves the TFB routes (raw
//   PDO), any other set serves the generic route worker.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$caddyfile = $techempower ? 'Caddyfile.techempower' : 'Caddyfile';

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The FrankenPHP opponent runs only inside the bootgly/bootgly_benchmarks:frankenphp image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:frankenphp test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,frankenphp --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Run frankenphp in the foreground (the runner backgrounds it). The Caddyfiles
   //   read FRANKENPHP_PORT / FRANKENPHP_DIR / FRANKENPHP_NUM_WORKERS; the TechEmpower
   //   worker reads DB_* directly.
   'start' => (function () use ($bootablesDir, $port, $workers, $caddyfile) {
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "cd {$bootablesDir} && {$db}"
         . "FRANKENPHP_PORT={$port} FRANKENPHP_DIR={$bootablesDir} FRANKENPHP_NUM_WORKERS={$workers} "
         . "frankenphp run --config {$caddyfile} > /dev/null 2>&1"
      );
   })(),

   // @ Kill the frankenphp server by argv pattern, then free the port.
   'stop' => (function () use ($port) {
      exec("pkill -9 -f 'frankenphp run' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
