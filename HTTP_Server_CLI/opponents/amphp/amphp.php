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
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65_535) {
   fwrite(STDERR, "Invalid AMPHP opponent port: {$port}\n");
   exit(1);
}
$workers = getenv('BOOTGLY_WORKERS') ?: (string) max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

// @ Async PostgreSQL pool size per worker (default 1 for parity with the other opponents).
$poolMax = getenv('DB_POOL_MAX') ?: '1';

$serverDirectory = getenv('BENCHMARK_SERVER_DIR');
$managed = is_string($serverDirectory) && $serverDirectory !== '';
$PIDFile = $managed
   ? rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/amphp.pid'
   : sys_get_temp_dir() . "/bootgly-benchmark-amphp-{$port}.pid";

$action = $argv[1] ?? 'start';

$exit = match ($action) {
   // @ Launch the async TechEmpower server in the foreground (the runner backgrounds it).
   'start' => (function () use ($bootablesDir, $workers, $poolMax, $bootable, $PIDFile): int {
      // ? Capability guard — stopping an already running server must not depend
      //   on the current wrapper still being able to load AMPHP's dependencies.
      if (!is_string($bootablesDir) || is_file("{$bootablesDir}/vendor/autoload.php") === false) {
         fwrite(STDERR,
            "The AMPHP opponent requires its Composer dependencies (bootables/amphp/vendor missing).\n"
            . "Run `composer install` in bootables/amphp (ext-pgsql also required) or use the "
            . "self-contained image: docker run --rm bootgly/bootgly_benchmarks:amphp test benchmark "
            . "HTTP_Server_CLI --opponents=bootgly,amphp --loads=techempower:*\n"
         );

         return 1;
      }

      $db = sprintf(
         'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s ',
         escapeshellarg(getenv('DB_HOST') ?: '127.0.0.1'),
         escapeshellarg(getenv('DB_PORT') ?: '5432'),
         escapeshellarg(getenv('DB_NAME') ?: 'bootgly'),
         escapeshellarg(getenv('DB_USER') ?: 'postgres'),
         escapeshellarg(getenv('DB_PASS') ?: '')
      );

      return ServerCapture::run(
         'cd ' . escapeshellarg($bootablesDir) . " && {$db}"
         . 'AMPHP_PID_FILE=' . escapeshellarg($PIDFile) . ' '
         . "BOOTGLY_WORKERS={$workers} DB_POOL_MAX={$poolMax} "
         . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootable)
      );
   })(),

   // @ Stop only the supervisor recorded by this invocation. A `pkill -f`
   //   helper also matches its own shell argv; killing that shell can orphan a
   //   descendant zombie under a non-reaping container PID 1 and keep the
   //   isolated process group observable forever.
   'stop' => (function () use ($bootable, $PIDFile, $managed): int {
      if (!function_exists('posix_kill')) {
         fwrite(STDERR, "Stopping the AMPHP opponent requires posix_kill().\n");

         return 1;
      }

      if (!is_file($PIDFile)) {
         if ($managed) {
            fwrite(STDERR, "AMPHP supervisor PID artifact is missing: {$PIDFile}\n");

            return 1;
         }

         return 0;
      }

      $value = trim((string) file_get_contents($PIDFile));
      if ($value === '' || !ctype_digit($value) || (int) $value < 2) {
         fwrite(STDERR, "Invalid AMPHP supervisor PID artifact: {$PIDFile}\n");

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
         fwrite(STDERR, "AMPHP supervisor PID ownership verification failed: {$PID}\n");

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
      $Inspect = static function (int $PID) use ($bootable): array|false {
         $value = @file_get_contents("/proc/{$PID}/task/{$PID}/children");
         if ($value === false || trim($value) === '') {
            return [];
         }

         $children = preg_split('/\s+/', trim($value)) ?: [];
         $PIDs = [];

         foreach ($children as $child) {
            if (!ctype_digit($child) || (int) $child < 2) {
               return false;
            }

            $ChildPID = (int) $child;
            $command = @file_get_contents("/proc/{$ChildPID}/cmdline");
            if ($command === false || $command === '') {
               continue;
            }
            if (!str_contains(str_replace("\0", ' ', $command), $bootable)) {
               return false;
            }

            $PIDs[] = $ChildPID;
         }

         return $PIDs;
      };
      $Signal = static function (array $PIDs, int $signal): void {
         foreach ($PIDs as $ChildPID) {
            @posix_kill($ChildPID, $signal);
         }
      };

      $PIDs = $Inspect($PID);
      if ($PIDs === false) {
         fwrite(STDERR, "AMPHP worker ownership verification failed: {$PID}\n");

         return 1;
      }

      // @ Children own the event loops and trap SIGTERM. Let the supervisor
      //   reap them and exit naturally so no descendant is orphaned.
      $Signal($PIDs, SIGTERM);
      if (!$Wait($PID, 2.0)) {
         @unlink($PIDFile);

         return 0;
      }

      $PIDs = $Inspect($PID);
      if ($PIDs === false) {
         fwrite(STDERR, "AMPHP worker ownership verification failed during cleanup: {$PID}\n");

         return 1;
      }
      $Signal($PIDs, SIGKILL);
      if (!$Wait($PID, 1.0)) {
         @unlink($PIDFile);

         return 0;
      }

      // ! At this point every directly owned worker has exited or received
      //   SIGKILL. It is now safe to escalate against the supervisor itself.
      $PIDs = $Inspect($PID);
      if ($PIDs === false || $PIDs !== []) {
         fwrite(STDERR, "AMPHP workers survived TERM/KILL cleanup: {$PID}\n");

         return 1;
      }

      @posix_kill($PID, SIGTERM);
      if (!$Wait($PID, 1.1)) {
         @unlink($PIDFile);

         return 0;
      }

      @posix_kill($PID, SIGKILL);
      if (!$Wait($PID, 1.2)) {
         @unlink($PIDFile);

         return 0;
      }

      fwrite(STDERR, "AMPHP supervisor process survived TERM/KILL cleanup: {$PID}\n");

      return 1;
   })(),

   default => 1,
};

exit($exit);
