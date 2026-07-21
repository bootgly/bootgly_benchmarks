<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — Benchmark Worker
 * --------------------------------------------------------------------------
 * Standalone subprocess that generates HTTP load using TCP_Client_CLI.
 * Spawned by tcp_client runner per load.
 *
 * Usage:
 *   php worker.php --host=127.0.0.1 --port=8082 --connections=514
 *                  --duration=10 --paths-file=/tmp/paths.json
 *                  [--workers=4] [--pipeline=16]
 *
 * Output: JSON line on stdout with benchmark results.
 * --------------------------------------------------------------------------
 */


// @ Bootstrap Bootgly class autoloader (without CLI framework boot)
// BOOTGLY_CLIENT_DIR env overrides the default sibling checkout (e.g. to run
// the load generator from a git worktree of bootgly for client-side A/B).
$clientDir = \getenv('BOOTGLY_CLIENT_DIR') ?: \realpath(__DIR__ . '/../../../bootgly');
$rootDir = \rtrim((string) $clientDir, '/') . '/';
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
   \define('BOOTGLY_VERSION', '0.16.0-beta');
}

@include($rootDir . 'vendor/autoload.php');

require_once __DIR__ . '/../Profiles.php';
require_once __DIR__ . '/../RunArtifacts.php';
require_once __DIR__ . '/../MeasurementBarrier.php';
require_once __DIR__ . '/../WorkerTelemetry.php';

\spl_autoload_register(function (string $class) {
   $paths = \explode('\\', $class);
   $file = \implode('/', $paths) . '.php';

   $included = @include(BOOTGLY_WORKING_DIR . $file);

   if ($included === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
      @include(BOOTGLY_ROOT_DIR . $file);
   }
});


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Benchmark\HTTP\Tracker;
use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Events;
use Bootgly\Benchmarks\Runners\MeasurementBarrier;
use Bootgly\Benchmarks\Runners\Profiles;
use Bootgly\Benchmarks\Runners\RunArtifacts;
use Bootgly\Benchmarks\Runners\WorkerTelemetry;


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
   'pipeline:',
]);

/** @var array<string, string> $opts */
$host        = $opts['host']        ?? '127.0.0.1';
$port        = (int) ($opts['port']        ?? 8082);
$connections = (int) ($opts['connections'] ?? 514);
$duration    = (int) ($opts['duration']    ?? 10);
$pathsFile   = $opts['paths-file']  ?? '';
$pipeline    = (int) ($opts['pipeline']    ?? 1);
if ($pipeline < 1) $pipeline = 1;

if ($connections < 1 || $duration < 1 || $duration > 3_600) {
   \fwrite(\STDERR, "ERROR: --connections must be positive and --duration must be between 1 and 3600 seconds.\n");
   exit(1);
}

if ($pathsFile === '' || !\file_exists($pathsFile)) {
   \fwrite(\STDERR, "ERROR: --paths-file is required and must exist.\n");
   exit(1);
}


// ---------------------------------------------------------------------------
// Load paths and pre-build HTTP request strings
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

// @ Pre-build raw HTTP request bytes (zero allocation in hot path)
/** @var array<string> $requests */
$requests = [];
/** @var array<int> $requestLengths */
$requestLengths = [];
foreach ($paths as $path) {
   $request = "{$method} {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nConnection: keep-alive\r\n\r\n";
   $requests[] = $request;
   $requestLengths[] = \strlen($request);
}


// ---------------------------------------------------------------------------
// Determine worker count based on FD_SETSIZE limit
// ---------------------------------------------------------------------------
$maxFDsPerWorker = 1000; // FD_SETSIZE=1024 minus ~24 reserved fds
$requestedWorkers = isset($opts['workers'])
   ? (int) $opts['workers']
   : (int) max(1, \ceil($connections / $maxFDsPerWorker));
$requestedWorkers = max(
   (int) \ceil($connections / $maxFDsPerWorker),
   min(max(1, $requestedWorkers), $connections),
);
$connectionsPerWorker = (int) \ceil($connections / $requestedWorkers);


// ---------------------------------------------------------------------------
// Suppress log output — we only want the JSON result on stdout
// ---------------------------------------------------------------------------
Display::show(Display::NONE);


// ---------------------------------------------------------------------------
// Pre-build pipeline burst strings (Phase 3: burst N requests per write)
// ---------------------------------------------------------------------------
/** @var array<string> $pipelinedRequests */
$pipelinedRequests = [];
/** @var array<array<int>> $pipelinedLengths */
$pipelinedLengths = [];
for ($i = 0; $i < $pathCount; $i++) {
   $burst = '';
   $lengths = [];
   for ($j = 0; $j < $pipeline; $j++) {
      $index = ($i + $j) % $pathCount;
      $burst .= $requests[$index];
      $lengths[] = $requestLengths[$index];
   }
   $pipelinedRequests[] = $burst;
   $pipelinedLengths[] = $lengths;
}
$pipelinePathCount = count($pipelinedRequests);


// ---------------------------------------------------------------------------
// Worker function: runs a single-process TCP Client benchmark
// ---------------------------------------------------------------------------
/**
 * @param array<string> $requests Individual HTTP request strings.
 * @param array<int> $requestLengths Individual request byte lengths.
 * @param array<string> $pipelinedRequests Pre-built initial burst strings (N requests each).
 * @param array<array<int>> $pipelinedLengths Initial burst request boundaries.
 */
function runWorker (
   string $host, int $port, int $workerConnections, int $duration,
   string $method, array $requests, array $requestLengths, int $pathCount,
   array $pipelinedRequests, array $pipelinedLengths, int $pipelinePathCount,
   ?string $statsFile, mixed $Channel = null,
): void {
   $bytesRead    = 0;
   $requestIndex = 0;
   $connectionFailed = 0;
   $originNS = 0;
   $deadlineNS = 0;
   $startLagNS = 0;
   $Histogram = new Histogram($duration * 1_000_000_000);
   // ? Replaced with the aligned absolute window before the reactor starts.
   $Series = new Series(0, $duration * 1_000_000_000);
   // # Per-bucket batch for the hot-path Series counters. accumulate() is a
   //   validated transactional call — invoking it per request/response cycle
   //   costs two 8-arg calls plus ~12 guards and an intdiv per cycle at
   //   ~1M cycles/s. Buffer the three hot counters in scalars and flush once
   //   per 1-second bucket (and at every exit path), attributing the batch
   //   to a timestamp inside its own bucket. Totals and per-bucket values
   //   are byte-identical to the unbatched form.
   $batchAtNS = 0;
   $batchEndNS = 0;
   $batchSent = 0;
   $batchResponses = 0;
   $batchBytes = 0;
   /** @var array<int,Tracker> $Trackers */
   $Trackers = [];
   /** @var array<int,\Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection> $Connections */
   $Connections = [];

   // @ Optional excimer sampling profiler (BOOTGLY_CLIENT_PROFILE=1)
   if (\getenv('BOOTGLY_CLIENT_PROFILE') && \class_exists(\ExcimerProfiler::class)) {
      $Profiler = new \ExcimerProfiler;
      $Profiler->setPeriod(0.0001);
      $Profiler->setEventType(\EXCIMER_CPU);
      $Profiler->setMaxDepth(64);
      $Profiler->start();
      \register_shutdown_function(static function () use ($Profiler): void {
         $Profiler->stop();
         $directory = Profiles::resolve('client', '/tmp/bootgly_client_profile');
         $file = $directory . '/client-' . \getmypid() . '.collapsed';
         if (Profiles::publish($file, $Profiler->getLog()->formatCollapsed()) === false) {
            \fwrite(\STDERR, "ERROR: Cannot publish client profile: $file\n");
         }
      });
   }

   // ? The outer harness already owns process forking/lifecycle. Test mode
   //   keeps the same connections/reactor path without registering a process
   //   shutdown signal that would turn a completed child into exit status 130.
   $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

   $Client->configure(
      host: $host,
      port: $port,
      workers: 0,
   );

   // # Flush the batched hot counters into the Series, attributing them to a
   //   timestamp inside their own 1-second bucket. Cheap no-op when empty.
   $flush = function () use (&$Series, &$batchAtNS, &$batchSent, &$batchResponses, &$batchBytes): void {
      if (($batchSent | $batchResponses | $batchBytes) !== 0) {
         $Series->accumulate($batchAtNS, $batchSent, $batchResponses, $batchBytes);
         $batchSent = 0;
         $batchResponses = 0;
         $batchBytes = 0;
      }
   };

   $Client->on(
      Events::WorkerStarted,
      function (TCP_Client_CLI $Client)
         use ($workerConnections, $method, $requests, $requestLengths, $pathCount,
              $pipelinedRequests, $pipelinedLengths, $pipelinePathCount, $duration,
              $Histogram, $Channel, &$bytesRead, &$requestIndex, &$connectionFailed,
              &$originNS, &$deadlineNS, &$startLagNS, &$Series,
              &$Trackers, &$Connections,
              $flush, &$batchAtNS, &$batchEndNS, &$batchSent, &$batchResponses, &$batchBytes)
      {
         // ! Hot-loop hoists: the reactor stamps each select() wakeup once
         //   ($Event->wakeNS) and the single-path replenish never needs the
         //   modulo + per-response array lookups.
         $Event = TCP_Client_CLI::$Event;
         $singleRequest = $requests[0] ?? '';
         $singleLength = $requestLengths[0] ?? 0;

         TCP_Client_CLI::$onClientConnect = function ($Socket, $Connection)
            use ($method, $pipelinedRequests, $pipelinedLengths, $pipelinePathCount,
                 $Histogram, &$requestIndex, &$Trackers, &$Connections)
         {
            // @ Fill pipeline: send initial burst of N requests
            $index = $requestIndex % $pipelinePathCount;
            $Tracker = new Tracker($method, Histogram: $Histogram);
            $Tracker->queue($pipelinedLengths[$index]);
            $Trackers[$Connection->id] = $Tracker;
            $Connections[$Connection->id] = $Connection;
            $Connection->output = $pipelinedRequests[$index];
            $requestIndex += \count($pipelinedLengths[$index]);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         };

         TCP_Client_CLI::$onDataProgress = function (
            $Socket, $Connection, $Package, int $accepted, int $remaining
         ) use (&$deadlineNS, &$Trackers,
                $flush, &$batchAtNS, &$batchEndNS, &$batchSent): void
         {
            $nowNS = (int) \hrtime(true);
            if ($nowNS >= $deadlineNS) {
               return;
            }

            $socketID = $Connection->id;
            if (isset($Trackers[$socketID])) {
               $sent = $Trackers[$socketID]->accept($remaining, $nowNS);
               if ($sent > 0) {
                  if ($nowNS >= $batchEndNS) {
                     $flush();
                     $batchAtNS = $nowNS;
                     $batchEndNS += (\intdiv($nowNS - $batchEndNS, 1_000_000_000) + 1) * 1_000_000_000;
                  }
                  $batchSent += $sent;
               }
            }
         };

         TCP_Client_CLI::$onDataWrite = function ($Socket, $Connection, $Package)
         {
            // @ Switch to read mode (remove from writes to prevent duplicate sends)
            TCP_Client_CLI::$Event->del($Socket, TCP_Client_CLI::$Event::EVENT_WRITE);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_READ, $Connection);
         };

         TCP_Client_CLI::$onClientDisconnect = function ($Connection)
            use (&$deadlineNS, &$Series, &$Trackers, &$Connections)
         {
            $socketID = $Connection->id;
            if (isset($Trackers[$socketID])) {
               $nowNS = (int) \hrtime(true);
               $Tracker = $Trackers[$socketID];
               if ($nowNS < $deadlineNS) {
                  $sent = $Tracker->accept(\strlen($Connection->output), $nowNS);
                  $count = $Tracker->close($Connection->peerEOF, $nowNS);
                  $metrics = [];
                  if ($sent > 0) {
                     $metrics['sent'] = $sent;
                  }
                  if ($count > 0) {
                     $metrics['responses'] = $count;
                  }
                  $snapshot = $Tracker->inspect();
                  $failed = \array_sum($snapshot['failures']);
                  $writeFailed = \array_sum($snapshot['write_failures']);
                  if ($failed > 0) {
                     $metrics['failed'] = $failed;
                  }
                  if ($writeFailed > 0) {
                     $metrics['write_failed'] = $writeFailed;
                  }
                  if ($metrics !== []) {
                     $Series->record($nowNS, $metrics);
                  }
               }
               else {
                  $Tracker->censor();
                  $snapshot = $Tracker->inspect();
                  $metrics = [];
                  $censored = \array_sum($snapshot['censors']);
                  $writeCensored = \array_sum($snapshot['write_censors']);
                  if ($censored > 0) {
                     $metrics['censored'] = $censored;
                  }
                  if ($writeCensored > 0) {
                     $metrics['write_censored'] = $writeCensored;
                  }
                  if ($metrics !== []) {
                     $Series->record($deadlineNS - 1, $metrics);
                  }
               }
            }

            unset($Connections[$socketID]);
         };

         TCP_Client_CLI::$onDataRead = function ($Socket, $Connection, $Package)
            use ($Event, $singleRequest, $singleLength,
                 $requests, $requestLengths, $pathCount, &$requestIndex, &$bytesRead,
                 &$deadlineNS, &$Trackers,
                 $flush, &$batchAtNS, &$batchEndNS, &$batchSent, &$batchResponses, &$batchBytes)
         {
            // ?! Receive instant = the select() wakeup stamp: every response
            //    handled here was kernel-buffered before the wakeup, so one
            //    clock read per wakeup is both cheaper and skew-free across
            //    the sockets dispatched in it.
            $nowNS = $Event->wakeNS;
            if ($nowNS >= $deadlineNS) {
               return;
            }

            $input = $Package->input;
            $inputBytes = \strlen($input);
            $bytesRead += $inputBytes;

            $Tracker = $Trackers[$Connection->id] ?? null;
            if ($Tracker === null) {
               $Connection->close();
               return;
            }

            $count = $Tracker->feed($input, $nowNS);

            if ($inputBytes > 0 || $count > 0) {
               if ($nowNS >= $batchEndNS) {
                  $flush();
                  $batchAtNS = $nowNS;
                  $batchEndNS += (\intdiv($nowNS - $batchEndNS, 1_000_000_000) + 1) * 1_000_000_000;
               }
               $batchResponses += $count;
               $batchBytes += $inputBytes;
            }

            // ! A malformed stream has already been classified by Tracker;
            //   close it without attempting to resynchronize on body bytes.
            if ($Tracker->error !== null) {
               $Connection->close();
               return;
            }

            // ? Partial/informational response, or a connection that HTTP
            //   framing says cannot be reused: do not replenish it.
            if ($count === 0 || $Tracker->reusable === false || $Tracker->terminal) {
               return;
            }

            // @ Replenish exactly the completed responses. Pipeline 1 is the
            //   dominant throughput case: keep it scalar instead of allocating
            //   a burst string and request-boundary array on every response.
            if ($count === 1) {
               // ? Single-endpoint runs replenish a constant request — skip
               //   the modulo and the two per-response array lookups.
               if ($pathCount === 1) {
                  $burst = $singleRequest;
                  $length = $singleLength;
               }
               else {
                  $index = $requestIndex % $pathCount;
                  $burst = $requests[$index];
                  $length = $requestLengths[$index];
               }
               $requestIndex++;
            }
            else {
               $burst = '';
               $lengths = [];
               for ($i = 0; $i < $count; $i++) {
                  $index = $requestIndex % $pathCount;
                  $burst .= $requests[$index];
                  $lengths[] = $requestLengths[$index];
                  $requestIndex++;
               }

               $length = \strlen($burst);
            }

            // @ Direct write: a keep-alive socket that just delivered a response is
            //   almost always writable — skip the EVENT_WRITE round-trip (saves one
            //   select() round + 4 event-set mutations per request/response cycle).
            //   No fresh deadline read before the write: the post-write $sentNS
            //   check below already classifies a boundary-crossing send as
            //   queued (censored later) instead of in-window sent work.
            $written = @\fwrite($Socket, $burst);
            $accepted = $written === false ? 0 : (int) $written;
            $remaining = $length - $accepted;
            $sentNS = (int) \hrtime(true);

            if ($remaining === 0) {
               if ($sentNS < $deadlineNS) {
                  // @ No write ledger allocation is needed after the socket has
                  //   accepted every request byte atomically.
                  $Tracker->send($count, $sentNS);
                  if ($sentNS >= $batchEndNS) {
                     $flush();
                     $batchAtNS = $sentNS;
                     $batchEndNS += (\intdiv($sentNS - $batchEndNS, 1_000_000_000) + 1) * 1_000_000_000;
                  }
                  $batchSent += $count;
               }
               else {
                  // ! The syscall crossed the half-open measurement boundary;
                  //   retain scheduling but classify it outside primary sent work.
                  $Tracker->queue($count === 1 ? $length : $lengths);
               }
               return; // stay registered for EVENT_READ
            }

            // @ Partial/failed write — defer the remainder to the event loop
            //   (onDataWrite flips the connection back to read mode after flush).
            $Tracker->queue($count === 1 ? $length : $lengths);
            if ($sentNS < $deadlineNS) {
               $completed = $Tracker->accept($remaining, $sentNS);
               if ($completed > 0) {
                  if ($sentNS >= $batchEndNS) {
                     $flush();
                     $batchAtNS = $sentNS;
                     $batchEndNS += (\intdiv($sentNS - $batchEndNS, 1_000_000_000) + 1) * 1_000_000_000;
                  }
                  $batchSent += $completed;
               }
            }
            $Connection->output = \substr($burst, $accepted);
            TCP_Client_CLI::$Event->del($Socket, TCP_Client_CLI::$Event::EVENT_READ);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         };

         // @ Open connections
         for ($i = 0; $i < $workerConnections; $i++) {
            $socket = $Client->connect();
            if ($socket === false) break;
         }
         $connectionFailed = $workerConnections - \count($Trackers);

         // # Align every forked load worker before allowing its reactor to
         //   process the already-registered initial writes.
         if (\is_resource($Channel)) {
            $window = MeasurementBarrier::signal($Channel, \count($Trackers));
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
         // ! Rebase the hot-counter batch onto the aligned window.
         $batchAtNS = $originNS;
         $batchEndNS = $originNS + 1_000_000_000;
         $batchSent = 0;
         $batchResponses = 0;
         $batchBytes = 0;

         TCP_Client_CLI::$Event->defer(
            $deadlineNS,
            static function (): void {
               TCP_Client_CLI::$Event->destroy();
            },
         );

         // @ Enter the reactor until its absolute monotonic deadline.
         TCP_Client_CLI::$Event->loop();
      }
   );

   $Client->start();

   // ! Publish the final partial bucket before any export/censor accounting.
   $flush();

   // ! Give every request still live at the half-open deadline a non-error
   //   terminal classification. Do not reinterpret post-deadline socket state
   //   as in-window write progress.
   foreach ($Connections as $socketID => $Connection) {
      if (isset($Trackers[$socketID])) {
         $Tracker = $Trackers[$socketID];
         $Tracker->censor();
         $snapshot = $Tracker->inspect();
         $metrics = [];
         $censored = \array_sum($snapshot['censors']);
         $writeCensored = \array_sum($snapshot['write_censors']);
         if ($censored > 0) {
            $metrics['censored'] = $censored;
         }
         if ($writeCensored > 0) {
            $metrics['write_censored'] = $writeCensored;
         }
         if ($metrics !== []) {
            $Series->record($deadlineNS - 1, $metrics);
         }
      }
   }

   // @ Calculate results from the declared monotonic window, not scheduler lag.
   $elapsed = ($deadlineNS - $originNS) / 1_000_000_000;

   $stats = [
      'schema' => 'bootgly.benchmark-worker.v2',
      'scheduled' => 0,
      'sent' => 0,
      'responses' => 0,
      'informational' => 0,
      'outstanding' => 0,
      'statuses' => [],
      'failures' => [],
      'censors' => [],
      'write_failures' => [],
      'write_censors' => [],
      'connection_failures' => $connectionFailed,
      'partial_writes' => 0,
      'accounting' => $connectionFailed === 0,
      'bytes_read' => $bytesRead,
      'elapsed' => $elapsed,
      'start_lag_ns' => $startLagNS,
   ];

   foreach ($Trackers as $Tracker) {
      $snapshot = $Tracker->inspect();
      foreach (['scheduled', 'sent', 'responses', 'informational', 'outstanding', 'partial_writes'] as $key) {
         $stats[$key] += $snapshot[$key];
      }
      foreach (['statuses', 'failures', 'censors', 'write_failures', 'write_censors'] as $map) {
         foreach ($snapshot[$map] as $key => $count) {
            $stats[$map][$key] = ($stats[$map][$key] ?? 0) + $count;
         }
      }
      $stats['accounting'] = $stats['accounting'] && $snapshot['accounting'];
   }

   \ksort($stats['statuses']);
   \ksort($stats['failures']);
   \ksort($stats['censors']);
   \ksort($stats['write_failures']);
   \ksort($stats['write_censors']);
   $failed = \array_sum($stats['failures']);
   $censored = \array_sum($stats['censors']);
   $writeFailed = \array_sum($stats['write_failures']);
   $writeCensored = \array_sum($stats['write_censors']);
   $latencySummary = $Histogram->inspect();
   $series = $Series->export();
   $stats['accounting'] = $stats['accounting']
      && $stats['connection_failures'] === 0
      && $stats['outstanding'] === 0
      && $stats['scheduled'] === $stats['sent'] + $writeFailed + $writeCensored
      && $stats['sent'] === $stats['responses'] + $failed + $censored
      && $stats['responses'] === \array_sum($stats['statuses'])
      && $latencySummary['fidelity'] === true
      && $latencySummary['count'] === $stats['responses']
      && $series['totals']['sent'] === $stats['sent']
      && $series['totals']['responses'] === $stats['responses']
      && $series['totals']['failed'] === $failed
      && $series['totals']['censored'] === $censored
      && $series['totals']['write_failed'] === $writeFailed
      && $series['totals']['write_censored'] === $writeCensored
      && $series['totals']['bytes_read'] === $stats['bytes_read'];
   $stats['latency_summary'] = $latencySummary;
   $stats['latency_histogram'] = $Histogram->export();
   $stats['time_series'] = $series;

   $Telemetry = WorkerTelemetry::import($stats, $elapsed);
   if ($Telemetry === null) {
      throw new \RuntimeException('The TCP load worker produced structurally invalid telemetry.');
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
             $method, $requests, $requestLengths, $pathCount,
             $pipelinedRequests, $pipelinedLengths, $pipelinePathCount, null);
   exit(0);
}

// @ Multi-worker: fork N children into this invocation's exclusive workspace.
$Artifacts = RunArtifacts::create('tcp-client-workers');
$childPIDs = [];
$childFiles = [];
$childStatuses = [];
$Channels = [];
$forkFailed = false;
$baseConnections = \intdiv($connections, $requestedWorkers);
$extraConnections = $connections % $requestedWorkers;

for ($w = 0; $w < $requestedWorkers; $w++) {
   $workerConnections = $baseConnections + ($w < $extraConnections ? 1 : 0);
   [$ParentChannel, $ChildChannel] = MeasurementBarrier::pair();
   $PID = \pcntl_fork();

   if ($PID === 0) {
      // Child process
      \fclose($ParentChannel);
      $statsFile = $Artifacts->resolve('child-' . \getmypid() . '.json');
      runWorker($host, $port, $workerConnections, $duration,
                $method, $requests, $requestLengths, $pathCount,
                $pipelinedRequests, $pipelinedLengths, $pipelinePathCount,
                $statsFile, $ChildChannel);
      exit(0);
   } elseif ($PID > 0) {
      \fclose($ChildChannel);
      $childPIDs[] = $PID;
      $childFiles[$PID] = $Artifacts->resolve("child-{$PID}.json");
      $Channels[$PID] = $ParentChannel;
   } else {
      \fclose($ParentChannel);
      \fclose($ChildChannel);
      $forkFailed = true;
      \fwrite(\STDERR, "ERROR: pcntl_fork() failed.\n");
   }
}

// # Do not release any child until every successfully forked worker has
//   established its assigned connections and proved readiness on its PID-bound
//   control channel.
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
         if (\is_resource($Channel)) {
            \fclose($Channel);
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
