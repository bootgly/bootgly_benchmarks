<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Swoole Opponent
 * --------------------------------------------------------------------------
 *
 * Runs the Swoole HTTP Server in-process inside the self-contained bench image
 * (bootgly/bootgly_benchmarks:swoole), where Swoole is installed natively
 * — no docker-in-docker. The bootable is chosen from the active load set
 * (BENCHMARK_LOAD_SET, set by `--loads=<set>:<indexes>`):
 *   techempower   -> swoole-techempower-postgres.php (the 7 TFB routes, per-worker PDO pool)
 *   anything else -> swoole-base-routes.php          (generic route set)
 *
 * Both bootables run in SWOOLE_BASE mode (SWOOLE_SERVER_MODE=base): every
 * worker accepts its own connections via SO_REUSEPORT, which scales keep-alive
 * throughput far better than PROCESS mode's single master dispatcher
 * (+~27% on /plaintext, /json).
 *
 * Zero-setup path — the self-contained Docker image; native host runs also work
 * when the swoole extension is installed:
 *   docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark \
 *     HTTP_Server_CLI --opponents=bootgly,swoole --loads=techempower:*
 *
 * Usage (invoked by the runner):
 *   php swoole.php start
 *   php swoole.php stop
 */

$bootablesDir = realpath(__DIR__ . '/../../bootables/swoole');

$port = getenv('BENCHMARK_PORT') ?: '8082';
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Bootable from the active load set — techempower serves the TFB routes (PDO),
//   any other set serves the generic route set. Both run in SWOOLE_BASE mode.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$bootable = $techempower ? 'swoole-techempower-postgres.php' : 'swoole-base-routes.php';

// ? Capability guard — the swoole extension must be loaded in this PHP binary.
//   Always true inside the self-contained bench image; on a bare host, install
//   the extension natively or use the image.
if (extension_loaded('swoole') === false) {
   fwrite(STDERR,
      "The Swoole opponent requires the swoole PHP extension (not loaded in this PHP binary).\n"
      . "Install it natively or run the self-contained image: "
      . "docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark HTTP_Server_CLI "
      . "--opponents=bootgly,swoole --loads=techempower:*\n"
   );
   exit(1);
}

$action = $argv[1] ?? 'start';

match ($action) {
   // @ Launch the bootable directly in SWOOLE_BASE mode (the runner backgrounds it).
   'start' => (function () use ($bootablesDir, $port, $workers, $bootable) {
      // # TechEmpower DB env (local PG) — only read by the TechEmpower bootable.
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
