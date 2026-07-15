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
 * Both supported bootables run in SWOOLE_BASE mode: every
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

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

$action = $argv[1] ?? 'start';
$port = getenv('BENCHMARK_PORT') ?: '8082';
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65_535) {
   fwrite(STDERR, "Invalid Swoole opponent port: {$port}\n");
   exit(1);
}
// @ Bootable from the active load set — techempower serves the TFB routes
//   (PDO); any other set serves the generic routes. Both use SWOOLE_BASE.
$techempower = strtolower(getenv('BENCHMARK_LOAD_SET') ?: '') === 'techempower';
$bootable = $techempower
   ? 'swoole-techempower-postgres.php'
   : 'swoole-base-routes.php';
$serverDirectory = getenv('BENCHMARK_SERVER_DIR');
$managed = is_string($serverDirectory) && $serverDirectory !== '';
$PIDFile = $managed
   ? rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/swoole.pid'
   : sys_get_temp_dir() . "/bootgly-benchmark-swoole-{$port}.pid";

$exit = match ($action) {
   // @ Launch the bootable directly in SWOOLE_BASE mode (the runner backgrounds it).
   'start' => (function () use ($port, $bootable, $PIDFile): int {
      // ? Capability guard — stopping a running container must remain possible
      //   even when the current PHP binary cannot load the Swoole extension.
      if (extension_loaded('swoole') === false) {
         fwrite(STDERR,
            "The Swoole opponent requires the swoole PHP extension (not loaded in this PHP binary).\n"
            . "Install it natively or run the self-contained image: "
            . "docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark HTTP_Server_CLI "
            . "--opponents=bootgly,swoole --loads=techempower:*\n"
         );

         return 1;
      }

      $bootablesDir = realpath(__DIR__ . '/../../bootables/swoole');
      if (!is_string($bootablesDir)) {
         fwrite(STDERR, "Could not resolve the Swoole opponent bootables directory.\n");

         return 1;
      }

      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers === false || $workers === '') {
         $workers = (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));
      }
      if (!ctype_digit($workers) || (int) $workers < 1) {
         fwrite(STDERR, "Invalid Swoole opponent worker count: {$workers}\n");

         return 1;
      }
      $workers = (string) (int) $workers;

      // # TechEmpower DB env (local PG) — only read by the TechEmpower bootable.
      $DB = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      return ServerCapture::run(
         'cd ' . escapeshellarg($bootablesDir) . " && {$DB}"
         . 'SWOOLE_PID_FILE=' . escapeshellarg($PIDFile) . ' '
         . "SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootable)
      );
   })(),

   // @ Signal only the master recorded by this opponent. `pkill -f` is
   //   deliberately avoided: its pattern is also present in the helper shell
   //   argv and can kill that shell, orphaning a zombie under a container PID 1
   //   that does not reap descendants. Such a zombie keeps the isolated stop
   //   process group observable forever.
   'stop' => (function () use ($bootable, $PIDFile, $managed): int {
      if (!function_exists('posix_kill')) {
         fwrite(STDERR, "Stopping the Swoole opponent requires posix_kill().\n");

         return 1;
      }

      // @ Harness runs publish the Swoole master PID inside the collision-proof
      //   server directory; standalone runs use a port-keyed file under /tmp.
      //   This is the authoritative ownership record: never adopt a different
      //   process merely because it later binds the same port.
      if (is_file($PIDFile)) {
         $value = trim((string) file_get_contents($PIDFile));
         if ($value === '' || !ctype_digit($value) || (int) $value < 2) {
            fwrite(STDERR, "Invalid Swoole master PID artifact: {$PIDFile}\n");

            return 1;
         }

         $PID = (int) $value;
         $command = @file_get_contents("/proc/{$PID}/cmdline");
         if ($command === false || $command === '') {
            @unlink($PIDFile);

            return 0;
         }
         $command = str_replace("\0", ' ', $command);
         if (!str_contains($command, $bootable)) {
            fwrite(STDERR, "Swoole master PID ownership verification failed: {$PID}\n");

            return 1;
         }

         $Check = static function (int $PID): bool {
            $status = @file_get_contents("/proc/{$PID}/stat");
            if ($status === false) {
               return false;
            }

            $end = strrpos($status, ') ');

            return $end === false || substr($status, $end + 2, 1) !== 'Z';
         };
         $Wait = static function (int $PID, float $seconds) use ($Check): bool {
            $deadline = microtime(true) + $seconds;

            while ($Check($PID) && microtime(true) < $deadline) {
               usleep(20_000);
            }

            return $Check($PID);
         };

         @posix_kill($PID, 15);
         if (!$Wait($PID, 2.0)) {
            @unlink($PIDFile);

            return 0;
         }

         @posix_kill($PID, 9);
         if (!$Wait($PID, 1.0)) {
            @unlink($PIDFile);

            return 0;
         }

         fwrite(STDERR, "Swoole master process survived TERM/KILL cleanup: {$PID}\n");

         return 1;
      }

      if ($managed) {
         fwrite(STDERR, "Swoole master PID artifact is missing: {$PIDFile}\n");

         return 1;
      }

      return 0;
   })(),

   default => 1,
};

exit($exit);
