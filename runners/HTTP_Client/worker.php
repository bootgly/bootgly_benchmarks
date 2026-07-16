<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — Benchmark Worker
 * --------------------------------------------------------------------------
 * Standalone subprocess that generates HTTP load using HTTP_Client_CLI.
 * Spawned by http_client runner per load.
 *
 * Usage:
 *   php worker.php --host=127.0.0.1 --port=8082 --connections=514
 *                  --duration=10 --paths-file=/tmp/paths.json
 *                  [--workers=4]
 *
 * Output: JSON line on stdout with benchmark results.
 * --------------------------------------------------------------------------
 */


// @ Bootstrap Bootgly class autoloader (without CLI framework boot)
$rootDir = \realpath(__DIR__ . '/../../../bootgly') . '/';
if (!\defined('BOOTGLY_ROOT_BASE')) {
   \define('BOOTGLY_ROOT_BASE', \rtrim($rootDir, '/'));
   \define('BOOTGLY_ROOT_DIR', $rootDir);
}
if (!\defined('BOOTGLY_WORKING_BASE')) {
   \define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
   \define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
}
if (!\defined('BOOTGLY_STORAGE_BASE')) {
   \define('BOOTGLY_STORAGE_BASE', BOOTGLY_WORKING_DIR . 'storage');
   \define('BOOTGLY_STORAGE_DIR', BOOTGLY_STORAGE_BASE . \DIRECTORY_SEPARATOR);
}
if (!\defined('BOOTGLY_VERSION')) {
   \define('BOOTGLY_VERSION', '0.12.1-beta');
}

@include($rootDir . 'vendor/autoload.php');

\spl_autoload_register(function (string $class) {
   $paths = \explode('\\', $class);
   $file = \implode('/', $paths) . '.php';

   $included = @include(BOOTGLY_WORKING_DIR . $file);

   if ($included === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
      @include(BOOTGLY_ROOT_DIR . $file);
   }
});


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use Bootgly\Benchmarks\Runners\MeasurementBarrier;
use Bootgly\Benchmarks\Runners\RunArtifacts;
use Bootgly\Benchmarks\Runners\WorkerTelemetry;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;

require_once __DIR__ . '/../RunArtifacts.php';
require_once __DIR__ . '/../MeasurementBarrier.php';
require_once __DIR__ . '/../WorkerTelemetry.php';


// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', [
   'host:',
   'port:',
   'connections:',
   'duration:',
   'paths-file:',
   'workers:',
]);

/** @var array<string, string> $opts */
$host        = $opts['host']        ?? '127.0.0.1';
$port        = (int) ($opts['port']        ?? 8082);
$connections = (int) ($opts['connections'] ?? 514);
$duration    = (int) ($opts['duration']    ?? 10);
$pathsFile   = $opts['paths-file']  ?? '';

if ($connections < 1 || $duration < 1 || $duration > 3_600) {
   \fwrite(\STDERR, "ERROR: --connections must be positive and --duration must be between 1 and 3600 seconds.\n");
   exit(1);
}

if ($pathsFile === '' || !\file_exists($pathsFile)) {
   \fwrite(\STDERR, "ERROR: --paths-file is required and must exist.\n");
   exit(1);
}


// ---------------------------------------------------------------------------
// Load load data
// ---------------------------------------------------------------------------
$json = file_get_contents($pathsFile);
if ($json === false) {
   \fwrite(\STDERR, "ERROR: Cannot read paths file.\n");
   exit(1);
}

$decoded = json_decode($json, true);
if (
   !\is_array($decoded)
   || !isset($decoded['method'], $decoded['paths'])
   || !\is_array($decoded['paths'])
   || $decoded['paths'] === []
) {
   \fwrite(\STDERR, "ERROR: Invalid load data.\n");
   exit(1);
}

/** @var array{method: mixed, paths: array<mixed>} $load */
$load = $decoded;

$method    = $load['method'];
$paths     = $load['paths'];
$pathCount = count($paths);

if (
   \is_string($method) === false
   || \preg_match("/\A[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\z/D", $method) !== 1
) {
   \fwrite(\STDERR, "ERROR: Invalid HTTP request method.\n");
   exit(1);
}
foreach ($paths as $path) {
   if (\is_string($path) === false || $path === '' || \str_contains($path, "\r") || \str_contains($path, "\n")) {
      \fwrite(\STDERR, "ERROR: Invalid HTTP request path.\n");
      exit(1);
   }
}
/** @var string $method */
/** @var array<string> $paths */


// ---------------------------------------------------------------------------
// Determine worker count based on FD_SETSIZE limit
// ---------------------------------------------------------------------------
$maxFdsPerWorker = 1000; // FD_SETSIZE=1024 minus ~24 reserved fds
$requestedWorkers = isset($opts['workers'])
   ? (int) $opts['workers']
   : (int) max(1, \ceil($connections / $maxFdsPerWorker));
$requestedWorkers = max(
   (int) \ceil($connections / $maxFdsPerWorker),
   min(max(1, $requestedWorkers), $connections),
);
$connectionsPerWorker = (int) \ceil($connections / $requestedWorkers);


// ---------------------------------------------------------------------------
// Suppress log output — we only want the JSON result on stdout
// ---------------------------------------------------------------------------
Display::show(Display::NONE);


// ---------------------------------------------------------------------------
// Worker function: runs a single-process HTTP Client benchmark
// ---------------------------------------------------------------------------
/**
 * @param array<string> $paths Load paths.
 */
function runWorker (
   string $host, int $port, int $workerConnections, int $duration,
   string $method, array $paths, int $pathCount,
   ?string $statsFile, mixed $Channel = null,
): void {
   $scheduled       = 0;
   $sent            = 0;
   $responses       = 0;
   $bytesRead       = 0;
   $requestIndex    = 0;
   $connectionFailed = 0;
   $originNS = 0;
   $deadlineNS = 0;
   $startLagNS = 0;
   $timingValid = true;
   $Histogram = new Histogram($duration * 1_000_000_000);
   $Series = new Series(0, $duration * 1_000_000_000);
   /** @var \WeakMap<object,int> $SendTimes */
   $SendTimes = new \WeakMap;
   /** @var array<int,int> $statuses */
   $statuses = [];
   /** @var array<string,int> $failures */
   $failures = [];
   /** @var array<string,int> $writeFailures */
   $writeFailures = [];

   // ? The runner owns process lifecycle. Test mode uses the same HTTP/event
   //   engine without installing a self-signalling CLI shutdown process.
   $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);

   $Client->configure(
      host: $host,
      port: $port,
      workers: 0,
   );

   // @ Register HTTP hooks
   $Client
      ->on(Events::ClientConnect, function () use (&$scheduled): void {
         // @ One initial request is scheduled for each established connection.
         $scheduled++;
      })
      ->on(Events::WorkerStarted, function (HTTP_Client_CLI $Client)
         use (
            $method,
            $paths,
            $pathCount,
            $workerConnections,
            $duration,
            $Channel,
            &$requestIndex,
            &$connectionFailed,
            &$originNS,
            &$deadlineNS,
            &$startLagNS,
            &$Series,
         )
      {
         // @ Prepare initial request
         $Client->request($method, $paths[$requestIndex++ % $pathCount]);

         // @ Open connections (auto-sends the pending request on each)
         $connected = 0;
         for ($i = 0; $i < $workerConnections; $i++) {
            $socket = $Client->connect();
            if ($socket === false) {
               break;
            }
            $connected++;
         }
         $connectionFailed = $workerConnections - $connected;

         if (\is_resource($Channel)) {
            $window = MeasurementBarrier::signal($Channel, $connected);
            \fclose($Channel);
            $originNS = $window['origin_ns'];
            $deadlineNS = $window['deadline_ns'];
            $startLagNS = $window['start_lag_ns'];
         }
         else {
            $originNS = (int) \hrtime(true);
            $deadlineNS = $originNS + ($duration * 1_000_000_000);
            $startLagNS = 0;
         }
         $Series = new Series($originNS, $deadlineNS);

         HTTP_Client_CLI::$Event->defer(
            $deadlineNS,
            static function (): void {
               HTTP_Client_CLI::$Event->destroy();
            },
         );

         // @ Enter the reactor until its absolute monotonic deadline.
         HTTP_Client_CLI::$Event->loop();
      })
      ->on(Events::DataWrite, function ($Socket, $Connection, $Request)
         use (&$deadlineNS, &$sent, &$timingValid, &$SendTimes, &$Series)
      {
         $nowNS = (int) \hrtime(true);
         if ($nowNS >= $deadlineNS) {
            return;
         }

         $sent++;
         $Series->accumulate($nowNS, 1);
         if (\is_object($Request)) {
            $SendTimes[$Request] = $nowNS;
         }
         else {
            $timingValid = false;
         }
      })
      ->on(Events::DataRead, function ($Socket, $Connection, int $receivedNS)
         use (&$bytesRead, &$deadlineNS, &$Series): void
      {
         if ($receivedNS >= $deadlineNS) {
            return;
         }
         $bytes = \strlen($Connection->input);
         if ($bytes > 0) {
            $bytesRead += $bytes;
            $Series->accumulate($receivedNS, 0, 0, $bytes);
         }
      })
      ->on(Events::ResponseReceive, function ($Request, $Response, int $receivedNS)
         use (
            $Client,
            $method,
            $paths,
            $pathCount,
            $Histogram,
            &$requestIndex,
            &$scheduled,
            &$responses,
            &$statuses,
            &$failures,
            &$writeFailures,
            &$deadlineNS,
            &$timingValid,
            &$SendTimes,
            &$Series,
         )
      {
         if ($receivedNS >= $deadlineNS) {
            return;
         }

         $sentNS = \is_object($Request) && isset($SendTimes[$Request])
            ? $SendTimes[$Request]
            : null;
         if (\is_object($Request)) {
            unset($SendTimes[$Request]);
         }
         $status = (int) $Response->code;
         if ($status >= 200 && $status <= 599) {
            if (!\is_int($sentNS) || $sentNS > $receivedNS) {
               $timingValid = false;
               return;
            }
            $responses++;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $Histogram->record($receivedNS - $sentNS);
            $Series->accumulate($receivedNS, 0, 1);
         }
         else {
            $reason = strtolower(trim((string) $Response->status));
            $reason = preg_replace('/[^a-z0-9]+/', '_', $reason) ?? '';
            $reason = trim($reason, '_');
            $reason = $reason !== '' ? $reason : 'http_client_failure';
            if (\is_int($sentNS)) {
               $failures[$reason] = ($failures[$reason] ?? 0) + 1;
               $Series->accumulate($receivedNS, failed: 1);
            }
            else {
               $writeFailures[$reason] = ($writeFailures[$reason] ?? 0) + 1;
               $Series->accumulate($receivedNS, writeFailed: 1);
            }
         }

         if ((int) \hrtime(true) < $deadlineNS) {
            // @ Queue the next request while the half-open window remains live.
            $scheduled++;
            $Client->request($method, $paths[$requestIndex++ % $pathCount]);
         }
      });

   $Client->start();

   // ! The half-open cutoff is not an error. Requests fully written before the
   //   boundary are response-censored; queued/unwritten work is write-censored.
   $elapsed = ($deadlineNS - $originNS) / 1_000_000_000;
   $failed = array_sum($failures);
   $writeFailed = array_sum($writeFailures);
   $censored = max(0, $sent - $responses - $failed);
   $writeCensored = max(0, $scheduled - $sent - $writeFailed);
   $censors = $censored > 0 ? ['measurement_ended' => $censored] : [];
   $writeCensors = $writeCensored > 0 ? ['measurement_ended' => $writeCensored] : [];
   $terminalMetrics = [];
   if ($censored > 0) {
      $terminalMetrics['censored'] = $censored;
   }
   if ($writeCensored > 0) {
      $terminalMetrics['write_censored'] = $writeCensored;
   }
   if ($terminalMetrics !== []) {
      $Series->record($deadlineNS - 1, $terminalMetrics);
   }
   ksort($statuses);
   ksort($failures);
   ksort($censors);
   ksort($writeFailures);
   ksort($writeCensors);
   $latencySummary = $Histogram->inspect();
   $series = $Series->export();
   $accounting = $timingValid
      && $connectionFailed === 0
      && $scheduled === $sent + $writeFailed + $writeCensored
      && $sent === $responses + $failed + $censored
      && $responses === array_sum($statuses)
      && $latencySummary['fidelity'] === true
      && $latencySummary['count'] === $responses
      && $series['totals']['sent'] === $sent
      && $series['totals']['responses'] === $responses
      && $series['totals']['failed'] === $failed
      && $series['totals']['censored'] === $censored
      && $series['totals']['write_failed'] === $writeFailed
      && $series['totals']['write_censored'] === $writeCensored;
   $stats = [
      'schema' => 'bootgly.benchmark-worker.v2',
      'scheduled' => $scheduled,
      'sent' => $sent,
      'responses' => $responses,
      'informational' => 0,
      'outstanding' => 0,
      'statuses' => $statuses,
      'failures' => $failures,
      'censors' => $censors,
      'write_failures' => $writeFailures,
      'write_censors' => $writeCensors,
      'connection_failures' => $connectionFailed,
      'partial_writes' => 0,
      'accounting' => $accounting,
      'bytes_read' => $bytesRead,
      'elapsed' => $elapsed,
      'start_lag_ns' => $startLagNS,
      'latency_summary' => $latencySummary,
      'latency_histogram' => $Histogram->export(),
      'time_series' => $series,
   ];

   $Telemetry = WorkerTelemetry::import($stats, $elapsed);
   if ($Telemetry === null) {
      throw new \RuntimeException('The HTTP load worker produced structurally invalid telemetry.');
   }

   if ($statsFile !== null) {
      RunArtifacts::commit(
         $statsFile,
         json_encode($Telemetry->export(), JSON_THROW_ON_ERROR),
      );
   }
   else {
      echo $Telemetry->render();
   }
}


// ---------------------------------------------------------------------------
// Execute: single-worker or multi-worker
// ---------------------------------------------------------------------------
if ($requestedWorkers <= 1) {
   // @ Single-worker: no fork, run directly
   runWorker($host, $port, $connectionsPerWorker, $duration,
             $method, $paths, $pathCount, null);
   exit(0);
}

// @ Multi-worker: fork N children into this invocation's exclusive workspace.
$Artifacts = RunArtifacts::create('http-client-workers');
$childPIDs = [];
$childFiles = [];
$childStatuses = [];
$Channels = [];
$forkFailed = false;
$baseConnections = intdiv($connections, $requestedWorkers);
$extraConnections = $connections % $requestedWorkers;

for ($w = 0; $w < $requestedWorkers; $w++) {
   $workerConnections = $baseConnections + ($w < $extraConnections ? 1 : 0);
   [$ParentChannel, $ChildChannel] = MeasurementBarrier::pair();
   $PID = \pcntl_fork();

   if ($PID === 0) {
      // Child process
      fclose($ParentChannel);
      $statsFile = $Artifacts->resolve('child-' . \getmypid() . '.json');
      runWorker($host, $port, $workerConnections, $duration,
                $method, $paths, $pathCount, $statsFile, $ChildChannel);
      exit(0);
   } elseif ($PID > 0) {
      fclose($ChildChannel);
      $childPIDs[] = $PID;
      $childFiles[$PID] = $Artifacts->resolve("child-{$PID}.json");
      $Channels[$PID] = $ParentChannel;
   } else {
      fclose($ParentChannel);
      fclose($ChildChannel);
      $forkFailed = true;
      \fwrite(\STDERR, "ERROR: pcntl_fork() failed.\n");
   }
}

if ($Channels !== []) {
   try {
      MeasurementBarrier::release($Channels, $duration);
   }
   catch (\Throwable $Throwable) {
      $forkFailed = true;
      \fwrite(\STDERR, 'ERROR: ' . $Throwable->getMessage() . "\n");
   }
   finally {
      foreach ($Channels as $Channel) {
         if (is_resource($Channel)) {
            fclose($Channel);
         }
      }
   }
}

// @ Parent: wait for all children
foreach ($childPIDs as $PID) {
   $waited = \pcntl_waitpid($PID, $status);
   $childStatuses[$PID] = $waited === $PID
      && \pcntl_wifexited($status)
      && \pcntl_wexitstatus($status) === 0;
}

// @ Import only exact files assigned to successful child PIDs, then merge the
//   underlying distributions and aligned buckets rather than child percentiles.
$MergedTelemetry = null;
foreach ($childPIDs as $PID) {
   $contents = @\file_get_contents($childFiles[$PID]);
   $data = $contents === false ? null : \json_decode($contents, true);
   $Telemetry = ($childStatuses[$PID] ?? false)
      ? WorkerTelemetry::import($data, (float) $duration)
      : null;

   if ($Telemetry === null || $Telemetry->accounting === false) {
      $forkFailed = true;
      continue;
   }

   if ($MergedTelemetry === null) {
      $MergedTelemetry = $Telemetry;
      continue;
   }

   try {
      $MergedTelemetry->merge($Telemetry);
   }
   catch (\Throwable $Throwable) {
      $forkFailed = true;
      \fwrite(\STDERR, 'ERROR: ' . $Throwable->getMessage() . "\n");
   }
}

if (
   $forkFailed
   || \count($childPIDs) !== $requestedWorkers
   || $MergedTelemetry === null
   || $MergedTelemetry->accounting === false
) {
   \fwrite(\STDERR, "ERROR: one or more benchmark workers produced invalid telemetry.\n");
   $Artifacts->clean();
   exit(1);
}

echo $MergedTelemetry->render();
$Artifacts->clean();
exit(0);
