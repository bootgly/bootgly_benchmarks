<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\Benchmarks\Runners\RunArtifacts;

require_once dirname(__DIR__) . '/RunArtifacts.php';


return new Specification(
   description: 'It should stop only the PID-owned AMPHP worker tree without leaking process groups',
   test: static function (): bool
   {
      $Check = static function (bool $condition, string $message): void {
         if (!$condition) {
            throw new AssertionError($message);
         }
      };
      $Await = static function (array $files, float $seconds = 3.0): void {
         $deadline = microtime(true) + $seconds;

         do {
            $ready = true;
            foreach ($files as $file) {
               if (!is_file($file)) {
                  $ready = false;
                  break;
               }
            }
            if ($ready) {
               return;
            }
            usleep(20_000);
         } while (microtime(true) < $deadline);

         throw new RuntimeException('Timed out waiting for the AMPHP stop proof processes.');
      };

      $wrapper = dirname(__DIR__, 2) . '/HTTP_Server_CLI/opponents/amphp/amphp.php';
      $root = sys_get_temp_dir() . '/bootgly-amphp-stop-' . bin2hex(random_bytes(12));
      $serverDirectory = "{$root}/server";
      $PIDFile = "{$serverDirectory}/amphp.pid";
      $firstReady = "{$root}/worker-1.ready";
      $secondReady = "{$root}/worker-2.ready";
      $decoyReady = "{$root}/decoy.ready";
      $unrelatedReady = "{$root}/unrelated.ready";

      if (!mkdir($serverDirectory, 0o755, true)) {
         throw new RuntimeException("Could not create AMPHP stop proof directory: {$serverDirectory}");
      }

      $SupervisorArtifacts = RunArtifacts::create('amphp-stop-supervisor');
      $DecoyArtifacts = RunArtifacts::create('amphp-stop-decoy');
      $UnrelatedArtifacts = RunArtifacts::create('amphp-stop-unrelated');
      $StopArtifacts = RunArtifacts::create('amphp-stop-command');
      $MissingArtifacts = RunArtifacts::create('amphp-stop-missing-pid');
      $OwnershipArtifacts = RunArtifacts::create('amphp-stop-ownership');
      $Supervisor = null;
      $Decoy = null;
      $Unrelated = null;

      try {
         $supervisorCode = <<<'PHP'
$pidFile = getenv('PROOF_PID_FILE');
$readyFiles = [getenv('PROOF_FIRST_READY'), getenv('PROOF_SECOND_READY')];
$PIDs = [];

foreach ($readyFiles as $index => $readyFile) {
   $PID = pcntl_fork();
   if ($PID < 0) {
      exit(70);
   }
   if ($PID === 0) {
      pcntl_async_signals(true);
      if ($index === 0) {
         pcntl_signal(SIGTERM, static function (): void { exit(0); });
      }
      else {
         pcntl_signal(SIGTERM, SIG_IGN);
      }
      file_put_contents($readyFile, (string) getmypid(), LOCK_EX);
      while (true) {
         usleep(100_000);
      }
   }

   $PIDs[] = $PID;
}

$deadline = microtime(true) + 2.0;
do {
   if (is_file($readyFiles[0]) && is_file($readyFiles[1])) {
      break;
   }
   usleep(10_000);
} while (microtime(true) < $deadline);

if (!is_file($readyFiles[0]) || !is_file($readyFiles[1])) {
   exit(71);
}
file_put_contents($pidFile, (string) getmypid(), LOCK_EX);

foreach ($PIDs as $ChildPID) {
   pcntl_waitpid($ChildPID, $status);
}
@unlink($pidFile);
exit(0);
PHP;
         $Supervisor = $SupervisorArtifacts->start(
            [PHP_BINARY, '-r', $supervisorCode, 'amphp-techempower.php'],
            [
               'PROOF_PID_FILE' => $PIDFile,
               'PROOF_FIRST_READY' => $firstReady,
               'PROOF_SECOND_READY' => $secondReady,
            ],
         );

         $decoyCode = 'file_put_contents(getenv("PROOF_READY"), (string) getmypid(), LOCK_EX); '
            . 'usleep(30_000_000);';
         $Decoy = $DecoyArtifacts->start(
            [PHP_BINARY, '-r', $decoyCode, 'amphp-techempower.php'],
            ['PROOF_READY' => $decoyReady],
         );
         $Unrelated = $UnrelatedArtifacts->start(
            [PHP_BINARY, '-r', $decoyCode, 'unrelated-server.php'],
            ['PROOF_READY' => $unrelatedReady],
         );

         $Await([$PIDFile, $firstReady, $secondReady, $decoyReady, $unrelatedReady]);
         $Check($Supervisor->check(), 'The synthetic AMPHP supervisor did not start.');
         $Check($Decoy->check(), 'The AMPHP argv decoy did not start.');
         $Check($Unrelated->check(), 'The unrelated ownership sentinel did not start.');

         $result = $StopArtifacts->start(
            [PHP_BINARY, $wrapper, 'stop'],
            [
               'BENCHMARK_PORT' => '19083',
               'BENCHMARK_SERVER_DIR' => $serverDirectory,
            ],
         )->wait(7.0, 0.5);

         $Check($result['exit'] === 0, 'The AMPHP stop wrapper returned a failure exit status.');
         $Check(!$result['timed_out'], 'The AMPHP stop wrapper leaked a process-group descendant.');
         $Check($Decoy->check(), 'PID-scoped AMPHP cleanup killed an unrelated argv match.');
         $Check($Unrelated->check(), 'PID-scoped AMPHP cleanup killed an unrelated process.');

         $supervisorResult = $Supervisor->wait(2.0, 0.5);
         $Supervisor = null;
         $Check(!$supervisorResult['timed_out'], 'The AMPHP supervisor did not reap its workers.');
         $Check(!is_file($PIDFile), 'The AMPHP supervisor remained registered after cleanup.');

         $missing = $MissingArtifacts->start(
            [PHP_BINARY, $wrapper, 'stop'],
            [
               'BENCHMARK_PORT' => '19083',
               'BENCHMARK_SERVER_DIR' => $serverDirectory,
            ],
         )->wait(3.0, 0.5);
         $Check($missing['exit'] !== 0, 'Managed cleanup accepted a missing supervisor PID artifact.');
         $Check(!$missing['timed_out'], 'Missing-PID validation leaked a process-group descendant.');

         file_put_contents($PIDFile, trim((string) file_get_contents($unrelatedReady)), LOCK_EX);
         $ownership = $OwnershipArtifacts->start(
            [PHP_BINARY, $wrapper, 'stop'],
            [
               'BENCHMARK_PORT' => '19083',
               'BENCHMARK_SERVER_DIR' => $serverDirectory,
            ],
         )->wait(3.0, 0.5);
         $Check($ownership['exit'] !== 0, 'AMPHP cleanup accepted a PID with mismatched ownership.');
         $Check(!$ownership['timed_out'], 'Ownership validation leaked a process-group descendant.');
         $Check($Unrelated->check(), 'Ownership validation killed the unrelated process.');

         return true;
      }
      finally {
         @unlink($PIDFile);
         if ($Supervisor !== null) {
            $Supervisor->wait(0.0, 0.2);
         }
         if ($Decoy !== null) {
            $Decoy->wait(0.0, 0.2);
         }
         if ($Unrelated !== null) {
            $Unrelated->wait(0.0, 0.2);
         }

         $SupervisorArtifacts->clean();
         $DecoyArtifacts->clean();
         $UnrelatedArtifacts->clean();
         $StopArtifacts->clean();
         $MissingArtifacts->clean();
         $OwnershipArtifacts->clean();

         if (is_dir($root)) {
            $Children = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
            $Iterator = new RecursiveIteratorIterator($Children, RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($Iterator as $Child) {
               $Child->isDir() ? @rmdir($Child->getPathname()) : @unlink($Child->getPathname());
            }
            @rmdir($root);
         }
      }
   },
);
