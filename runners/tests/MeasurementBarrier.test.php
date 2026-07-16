<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\Benchmarks\Runners\MeasurementBarrier;


require_once dirname(__DIR__) . '/MeasurementBarrier.php';

return new Specification(
   description: 'It should align every load child on one monotonic measurement window',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$Reject = static function (Closure $Callback, string $message): void {
   try {
      $Callback();
   }
   catch (RuntimeException) {
      return;
   }

   throw new AssertionError($message);
};

$root = sys_get_temp_dir() . '/bootgly-measurement-barrier-' . bin2hex(random_bytes(12));
if (!mkdir($root, 0o700)) {
   throw new RuntimeException('Could not create the measurement-barrier proof directory.');
}

/** @var array<int,resource> $Channels */
$Channels = [];
/** @var array<int,string> $files */
$files = [];
/** @var list<int> $PIDs */
$PIDs = [];
/** @var array<int,true> $reaped */
$reaped = [];

try {
   for ($index = 0; $index < 2; $index++) {
      [$ParentChannel, $ChildChannel] = MeasurementBarrier::pair();
      $file = $root . '/child-' . $index . '.json';
      $connections = 2 + $index;
      $PID = pcntl_fork();
      if ($PID === -1) {
         fclose($ParentChannel);
         fclose($ChildChannel);
         throw new RuntimeException('Could not fork a measurement-barrier proof child.');
      }

      if ($PID === 0) {
         foreach ($Channels as $InheritedChannel) {
            fclose($InheritedChannel);
         }
         fclose($ParentChannel);

         try {
            $window = MeasurementBarrier::signal($ChildChannel, $connections);
            fclose($ChildChannel);
            $JSON = json_encode($window, JSON_THROW_ON_ERROR);
            if (file_put_contents($file, $JSON) !== strlen($JSON)) {
               exit(2);
            }
            exit(0);
         }
         catch (Throwable $Exception) {
            fclose($ChildChannel);
            file_put_contents($file, json_encode([
               'error' => $Exception->getMessage(),
            ], JSON_THROW_ON_ERROR));
            exit(1);
         }
      }

      fclose($ChildChannel);
      $Channels[$PID] = $ParentChannel;
      $files[$PID] = $file;
      $PIDs[] = $PID;
   }

   $release = MeasurementBarrier::release($Channels, 1);
   $Check(
      $release['deadline_ns'] - $release['origin_ns'] === 1_000_000_000,
      'The parent did not publish the requested exact measurement duration.',
   );
   $Check(
      count($release['ready']) === count($Channels)
         && array_diff_key($release['ready'], $Channels) === []
         && array_diff_key($Channels, $release['ready']) === [],
      'The parent did not retain readiness for every exact child PID.',
   );

   foreach ($PIDs as $index => $PID) {
      $status = 0;
      $waited = pcntl_waitpid($PID, $status);
      $reaped[$PID] = true;
      $Check($waited === $PID, "The measurement-barrier child {$PID} was not reaped.");
      $Check(
         pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
         "The measurement-barrier child {$PID} did not exit successfully.",
      );
      $JSON = file_get_contents($files[$PID]);
      $Check(is_string($JSON), "The measurement-barrier child {$PID} published no result.");
      $window = json_decode($JSON, true, flags: JSON_THROW_ON_ERROR);
      $Check(is_array($window), "The measurement-barrier child {$PID} result was not an object.");
      $Check(
         ($window['origin_ns'] ?? null) === $release['origin_ns']
            && ($window['deadline_ns'] ?? null) === $release['deadline_ns'],
         "The measurement-barrier child {$PID} received a different window.",
      );
      $activatedNS = $window['activated_ns'] ?? null;
      $startLagNS = $window['start_lag_ns'] ?? null;
      $Check(
         is_int($activatedNS)
            && is_int($startLagNS)
            && $activatedNS >= $release['origin_ns']
            && $startLagNS === $activatedNS - $release['origin_ns']
            && $startLagNS <= MeasurementBarrier::MAXIMUM_START_LAG_NS,
         "The measurement-barrier child {$PID} activation was not bounded to the common origin.",
      );
      $Check(
         ($release['ready'][$PID]['connections'] ?? null) === 2 + $index,
         "The measurement-barrier child {$PID} readiness count changed.",
      );
   }

   $Reject(
      static fn (): array => MeasurementBarrier::release([], 1),
      'An empty parent barrier was accepted.',
   );
   $Reject(
      static fn (): array => MeasurementBarrier::release([1 => STDIN], 0),
      'A zero-duration measurement window was accepted.',
   );
   $Reject(
      static fn (): array => MeasurementBarrier::release([1 => STDIN], 3_601),
      'A measurement window above the bounded maximum was accepted.',
   );
   $Reject(
      static fn (): array => MeasurementBarrier::signal(null, 1),
      'A child barrier without a channel was accepted.',
   );

   [$ExpectedChannel, $ForgedChannel] = MeasurementBarrier::pair();
   $expectedPID = getmypid() + 1;
   $forged = json_encode([
      'schema' => MeasurementBarrier::SCHEMA,
      'event' => 'ready',
      'pid' => getmypid(),
      'connections' => 1,
   ], JSON_THROW_ON_ERROR) . "\n";
   fwrite($ForgedChannel, $forged);
   $Reject(
      static fn (): array => MeasurementBarrier::release([$expectedPID => $ExpectedChannel], 1),
      'A readiness proof from a different PID was accepted.',
   );
   fclose($ExpectedChannel);
   fclose($ForgedChannel);

   [$SignalChannel, $ReleaseChannel] = MeasurementBarrier::pair();
   $forged = json_encode([
      'schema' => MeasurementBarrier::SCHEMA,
      'event' => 'start',
      'origin_ns' => '01',
      'deadline_ns' => '2',
   ], JSON_THROW_ON_ERROR) . "\n";
   fwrite($ReleaseChannel, $forged);
   $Reject(
      static fn (): array => MeasurementBarrier::signal($SignalChannel, 1),
      'A non-canonical monotonic timestamp was accepted.',
   );
   fclose($SignalChannel);
   fclose($ReleaseChannel);
}
finally {
   foreach ($Channels as $Channel) {
      if (is_resource($Channel)) {
         fclose($Channel);
      }
   }
   foreach ($PIDs as $PID) {
      if (!isset($reaped[$PID])) {
         $status = 0;
         pcntl_waitpid($PID, $status);
      }
   }
   foreach ($files as $file) {
      if (is_file($file)) {
         unlink($file);
      }
   }
   if (is_dir($root)) {
      rmdir($root);
   }
}

return true;
   }
);
