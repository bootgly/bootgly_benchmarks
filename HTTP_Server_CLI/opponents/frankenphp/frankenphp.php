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
 * Zero-setup path — the self-contained Docker image; native host runs also work
 * when the frankenphp binary is on the PATH:
 *   docker run --rm bootgly/bootgly_benchmarks:frankenphp test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,frankenphp --loads=techempower:*
 *
 * Usage (invoked by the runner):
 *   php frankenphp.php start
 *   php frankenphp.php stop
 */

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

$bootablesDir = realpath(__DIR__ . '/bootable');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Caddyfile from the active load set — techempower serves the TFB routes (raw
//   PDO), any other set serves the generic route worker.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$caddyfile = $techempower ? 'Caddyfile.techempower' : 'Caddyfile';

// ? Capability guard — the frankenphp binary must be on the PATH. Always true
//   inside the self-contained bench image; on a bare host, install FrankenPHP
//   natively or use the image.
if (trim((string) exec('command -v frankenphp 2>/dev/null')) === '') {
   fwrite(STDERR,
      "The FrankenPHP opponent requires the frankenphp binary on the PATH.\n"
      . "Install it natively (https://frankenphp.dev) or use the self-contained image: "
      . "docker run --rm bootgly/bootgly_benchmarks:frankenphp test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,frankenphp --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   // @ Run frankenphp in the foreground (the runner backgrounds it). The Caddyfiles
   //   read FRANKENPHP_PORT / FRANKENPHP_DIR / FRANKENPHP_NUM_WORKERS; the TechEmpower
   //   worker reads DB_* directly.
   'start' => (function () use ($bootablesDir, $port, $workers, $caddyfile): int {
      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      return ServerCapture::run(
         "cd {$bootablesDir} && {$db}"
         . "FRANKENPHP_PORT={$port} FRANKENPHP_DIR={$bootablesDir} FRANKENPHP_NUM_WORKERS={$workers} "
         . "frankenphp run --config {$caddyfile}"
      );
   })(),

   // @ Kill the frankenphp server by argv pattern, then free the port.
   'stop' => (function () use ($port): int {
      exec("pkill -9 -f 'frankenphp run' > /dev/null 2>&1");
      exec("lsof -ti :{$port} 2>/dev/null | xargs -r kill -9 > /dev/null 2>&1");
      return 0;
   })(),

   default => 1,
};

exit($exit);
