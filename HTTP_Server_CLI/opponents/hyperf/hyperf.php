<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Hyperf Opponent
 * --------------------------------------------------------------------------
 *
 * Runs Hyperf (Swoole coroutine engine) in-process inside the self-contained
 * bench image (bootgly/bootgly_benchmarks:hyperf), where swoole + pdo_pgsql and
 * the hyperf vendor live natively — no docker-in-docker. The server runs in the
 * FOREGROUND (the runner backgrounds it with `&`); SERVER_DAEMONIZE is
 * intentionally NOT set.
 *
 * Hyperf serves both the TechEmpower routes and the generic route set from a
 * single config/routes.php, so there is NO bootable swap on the load set — the
 * same server answers both. It honors SERVER_PORT, SERVER_WORKER_NUM and DB_*.
 *
 * Image-only: run through the Docker image, never on a bare host —
 *   docker run --rm bootgly/bootgly_benchmarks:hyperf test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,hyperf --loads=techempower:*
 *
 * Usage (inside the image, invoked by the runner):
 *   php hyperf.php start
 *   php hyperf.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/hyperf');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// ? Image-only: this opponent runs natively inside the self-contained bench image
//   (ENV BOOTGLY_BENCH_INPROCESS=1). It is not runnable on a bare host.
if (getenv('BOOTGLY_BENCH_INPROCESS') !== '1') {
   fwrite(STDERR,
      "The Hyperf opponent runs only inside the bootgly/bootgly_benchmarks:hyperf image.\n"
      . "Run: docker run --rm bootgly/bootgly_benchmarks:hyperf test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,hyperf --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Run the Hyperf server in the foreground (the runner backgrounds it). The bin
   //   script expects the namespaced `Swoole\` API, so force swoole.use_shortname=Off.
   'start' => (function () use ($bootablesDir, $port, $workers) {
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      exec(
         "cd {$bootablesDir} && {$db}SERVER_PORT={$port} SERVER_WORKER_NUM={$workers} "
         . "php -d swoole.use_shortname=Off bin/hyperf.php start > /dev/null 2>&1"
      );
   })(),

   // @ Kill the Hyperf master (which brings down its workers) by argv pattern, then
   //   free the port.
   'stop' => (function () use ($port) {
      exec("pkill -9 -f 'bin/hyperf.php' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
   })(),

   default => exit(1),
};
