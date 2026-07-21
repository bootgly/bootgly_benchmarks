<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Bun (Bun.serve) Opponent
 * --------------------------------------------------------------------------
 *
 * Runs Bun's native HTTP server (Bun.serve) in-process — no docker-in-docker.
 * The worker model mirrors SWOOLE_BASE: a thin supervisor spawns
 * SERVER_WORKER_NUM plain child processes and each worker binds the benchmark
 * port itself with SO_REUSEPORT, so no master dispatcher sits on the accept
 * path.
 *
 * Only the `techempower` load set is supported: the bootable serves the 7 TFB
 * routes with ONE persistent pg connection per worker (fixed-one parity).
 *
 * Usage (invoked by the runner):
 *   php bun.php start
 *   php bun.php stop
 */

use Bootgly\Benchmarks\Runners\ServerCapture;

require_once dirname(__DIR__, 3) . '/runners/ServerCapture.php';

$action = $argv[1] ?? 'start';
$port = getenv('BENCHMARK_PORT') ?: '8082';
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65_535) {
   fwrite(STDERR, "Invalid Bun opponent port: {$port}\n");
   exit(1);
}
$bootable = 'bun-techempower-postgres.js';
$serverDirectory = getenv('BENCHMARK_SERVER_DIR');
$managed = is_string($serverDirectory) && $serverDirectory !== '';
$PIDFile = $managed
   ? rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/bun.pid'
   : sys_get_temp_dir() . "/bootgly-benchmark-bun-{$port}.pid";

$exit = match ($action) {
   // @ Launch the supervisor directly (the runner backgrounds it).
   'start' => (function () use ($port, $bootable, $PIDFile): int {
      // ? Load-set guard — this opponent ships only the TechEmpower bootable.
      $loadSet = strtolower(getenv('BENCHMARK_LOAD_SET') ?: 'techempower');
      if ($loadSet !== 'techempower') {
         fwrite(STDERR,
            "The Bun opponent supports only the techempower load set (received '{$loadSet}').\n"
            . "Pass --loads=techempower:*\n"
         );

         return 1;
      }

      // ? Capability guard — bun on the PATH plus installed dependencies.
      if (trim((string) exec('command -v bun 2>/dev/null')) === '') {
         fwrite(STDERR,
            "The Bun opponent requires the bun binary on the PATH.\n"
            . "Install Bun natively (https://bun.sh) or use the self-contained image.\n"
         );

         return 1;
      }

      $bootablesDir = realpath(__DIR__ . '/../../bootables/bun');
      if (!is_string($bootablesDir)) {
         fwrite(STDERR, "Could not resolve the Bun opponent bootables directory.\n");

         return 1;
      }
      if (!is_dir("{$bootablesDir}/node_modules/pg")) {
         fwrite(STDERR,
            "The Bun opponent dependencies are not installed.\n"
            . "Run: cd " . escapeshellarg($bootablesDir) . " && bun install\n"
         );

         return 1;
      }

      $workers = getenv('BOOTGLY_WORKERS');
      if ($workers === false || $workers === '') {
         $workers = (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));
      }
      if (!ctype_digit($workers) || (int) $workers < 1) {
         fwrite(STDERR, "Invalid Bun opponent worker count: {$workers}\n");

         return 1;
      }
      $workers = (string) (int) $workers;

      // # TechEmpower DB env (local PG) — read by the bootable workers.
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
         . 'SERVER_PID_FILE=' . escapeshellarg($PIDFile) . ' '
         . "SERVER_WORKER_NUM={$workers} SERVER_PORT={$port} "
         . 'bun ' . escapeshellarg($bootable)
      );
   })(),

   // @ Signal only the supervisor recorded by this opponent — it forwards
   //   termination to its workers. `pkill -f` is deliberately avoided (the
   //   pattern also matches the helper shell argv).
   'stop' => (function () use ($bootable, $PIDFile): int {
      if (!function_exists('posix_kill')) {
         fwrite(STDERR, "Stopping the Bun opponent requires posix_kill().\n");

         return 1;
      }

      // ? The supervisor publishes its PID as its first action; a missing
      //   artifact is the signature of a start that never launched (e.g. a
      //   capability guard), so there is nothing to stop. The runner's port
      //   sweep covers any stray listener.
      if (!is_file($PIDFile)) {
         return 0;
      }

      $value = trim((string) file_get_contents($PIDFile));
      if ($value === '' || !ctype_digit($value) || (int) $value < 2) {
         fwrite(STDERR, "Invalid Bun supervisor PID artifact: {$PIDFile}\n");

         return 1;
      }

      // ! Authoritative ownership record: never adopt a different process
      //   merely because it later binds the same port.
      $PID = (int) $value;
      $command = @file_get_contents("/proc/{$PID}/cmdline");
      if ($command === false || $command === '') {
         @unlink($PIDFile);

         return 0;
      }
      $command = str_replace("\0", ' ', $command);
      if (!str_contains($command, $bootable)) {
         fwrite(STDERR, "Bun supervisor PID ownership verification failed: {$PID}\n");

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

      fwrite(STDERR, "Bun supervisor process survived TERM/KILL cleanup: {$PID}\n");

      return 1;
   })(),

   default => 1,
};

exit($exit);
