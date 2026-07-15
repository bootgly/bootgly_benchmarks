<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — AMPHP Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the AMPHP bootable (Amp v3, fibers) in-process inside the self-contained
 * bench image (bootgly/bootgly_benchmarks:amphp), where amphp/* is vendored and
 * ext-pgsql is built in — no docker-in-docker. amphp/postgres talks to
 * PostgreSQL through ext-pgsql (the `pgsql` extension, NOT pdo).
 *
 * TechEmpower only — there is no generic router branch: this opponent always
 * runs amphp-techempower.php in the FOREGROUND (the runner backgrounds it with
 * `&`). Worker model is manual pcntl_fork + SO_REUSEPORT (BOOTGLY_WORKERS
 * children, each its own Revolt event loop on :8082) — one async PostgreSQL pool
 * per worker (DB_POOL_MAX, default 1).
 *
 * Zero-setup path — the self-contained Docker image; native host runs also work
 * when the bootable's Composer dependencies (and ext-pgsql) are installed:
 *   docker run --rm bootgly/bootgly_benchmarks:amphp test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,amphp --loads=techempower:*
 *
 * Usage (invoked by the runner):
 *   php amphp.php start
 *   php amphp.php stop
 */

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

$bootablesDir = realpath(__DIR__ . '/../../bootables/amphp');
$bootable = 'amphp-techempower.php';

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Async PostgreSQL pool size per worker (default 1 for parity with the other opponents).
$poolMax = getenv('DB_POOL_MAX') ?: '1';

// ? Capability guard — the bootable's Composer dependencies must be installed.
//   Always true inside the self-contained bench image; on a bare host, run
//   `composer install` in bootables/amphp (ext-pgsql also required) or use the image.
if (is_file("{$bootablesDir}/vendor/autoload.php") === false) {
   fwrite(STDERR,
      "The AMPHP opponent requires its Composer dependencies (bootables/amphp/vendor missing).\n"
      . "Run `composer install` in bootables/amphp (ext-pgsql also required) or use the "
      . "self-contained image: docker run --rm bootgly/bootgly_benchmarks:amphp test benchmark "
      . "HTTP_Server_CLI --opponents=bootgly,amphp --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   // @ Launch the async TechEmpower server in the foreground (the runner backgrounds it).
   'start' => (function () use ($bootablesDir, $workers, $poolMax, $bootable): int {
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      return ServerCapture::run(
         "cd {$bootablesDir} && {$db}BOOTGLY_WORKERS={$workers} DB_POOL_MAX={$poolMax} "
         . "php {$bootable}"
      );
   })(),

   // @ Kill the bootable (and its forked workers) by argv pattern, then free the port.
   'stop' => (function () use ($port, $bootable): int {
      exec('pkill -9 -f ' . escapeshellarg($bootable) . ' > /dev/null 2>&1');
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
      return 0;
   })(),

   default => 1,
};

exit($exit);
