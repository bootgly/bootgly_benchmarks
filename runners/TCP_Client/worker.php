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

\spl_autoload_register(function (string $class) {
   $paths = \explode('\\', $class);
   $file = \implode('/', $paths) . '.php';

   $included = @include(BOOTGLY_WORKING_DIR . $file);

   if ($included === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
      @include(BOOTGLY_ROOT_DIR . $file);
   }
});


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Benchmark\HTTP\Tracker;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Events;
use Bootgly\Benchmarks\Runners\Profiles;
use Bootgly\Benchmarks\Runners\RunArtifacts;


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

if ($connections < 1 || $duration < 1) {
   \fwrite(\STDERR, "ERROR: --connections and --duration must be positive integers.\n");
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

/** @var array{method: string, paths: array<string>} $load */
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
// Install SIGALRM handler for Timer BEFORE fork (children inherit it)
// ---------------------------------------------------------------------------
\pcntl_signal(SIGALRM, [Timer::class, 'tick']);


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
   ?string $statsFile
): void {
   $bytesRead    = 0;
   $requestIndex = 0;
   $startTime    = 0.0;
   $latencySum   = 0.0;
   $latencyCount = 0;
   $connectionFailed = 0;
   /** @var array<int,float> $writeTimes */
   $writeTimes = [];
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

   $Client->on(
      Events::WorkerStarted,
      function (TCP_Client_CLI $Client)
         use ($workerConnections, $method, $requests, $requestLengths, $pathCount,
              $pipelinedRequests, $pipelinedLengths, $pipelinePathCount, $duration,
              &$bytesRead, &$requestIndex, &$startTime, &$latencySum, &$latencyCount,
              &$connectionFailed, &$writeTimes, &$Trackers, &$Connections)
      {
         TCP_Client_CLI::$onClientConnect = function ($Socket, $Connection)
            use ($method, $pipelinedRequests, $pipelinedLengths, $pipelinePathCount,
                 &$requestIndex, &$Trackers, &$Connections)
         {
            // @ Fill pipeline: send initial burst of N requests
            $index = $requestIndex % $pipelinePathCount;
            $Tracker = new Tracker($method);
            $Tracker->queue($pipelinedLengths[$index]);
            $Trackers[$Connection->id] = $Tracker;
            $Connections[$Connection->id] = $Connection;
            $Connection->output = $pipelinedRequests[$index];
            $requestIndex += \count($pipelinedLengths[$index]);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         };

         TCP_Client_CLI::$onDataWrite = function ($Socket, $Connection, $Package)
            use (&$writeTimes, &$Trackers)
         {
            $socketID = $Connection->id;
            if (isset($Trackers[$socketID])) {
               // @ Packages::writing() exposes the authoritative unsent suffix.
               $Trackers[$socketID]->accept(\strlen($Connection->output));
            }
            $writeTimes[$socketID] = microtime(true);
            // @ Switch to read mode (remove from writes to prevent duplicate sends)
            TCP_Client_CLI::$Event->del($Socket, TCP_Client_CLI::$Event::EVENT_WRITE);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_READ, $Connection);
         };

         TCP_Client_CLI::$onClientDisconnect = function ($Connection)
            use (&$Trackers, &$Connections, &$writeTimes, &$latencySum, &$latencyCount)
         {
            $socketID = $Connection->id;
            if (isset($Trackers[$socketID])) {
               // ! Reconcile accepted bytes before classifying unfinished requests.
               $Tracker = $Trackers[$socketID];
               $Tracker->accept(\strlen($Connection->output));
               $count = $Tracker->close($Connection->peerEOF);

               // ? An EOF-delimited response becomes complete only at clean EOF.
               if ($count > 0 && isset($writeTimes[$socketID])) {
                  $latencySum += (microtime(true) - $writeTimes[$socketID]) * $count;
                  $latencyCount += $count;
               }
            }

            unset($Connections[$socketID], $writeTimes[$socketID]);
         };

         TCP_Client_CLI::$onDataRead = function ($Socket, $Connection, $Package)
            use ($requests, $requestLengths, $pathCount, &$requestIndex, &$bytesRead,
                 &$latencySum, &$latencyCount, &$writeTimes, &$Trackers)
         {
            $input = $Package->input;
            $bytesRead += \strlen($input);

            $socketID = $Connection->id;
            if (isset($Trackers[$socketID]) === false) {
               $Connection->close();
               return;
            }

            $Tracker = $Trackers[$socketID];
            $count = $Tracker->feed($input);

            if ($count > 0 && isset($writeTimes[$socketID])) {
               $latency = microtime(true) - $writeTimes[$socketID];
               $latencySum += $latency * $count;
               $latencyCount += $count;
               unset($writeTimes[$socketID]);
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
               $index = $requestIndex % $pathCount;
               $burst = $requests[$index];
               $length = $requestLengths[$index];
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
            $sent = @\fwrite($Socket, $burst);
            $accepted = $sent === false ? 0 : (int) $sent;
            $remaining = $length - $accepted;

            if ($remaining === 0) {
               // @ No write ledger allocation is needed after the socket has
               //   accepted every request byte atomically.
               $Tracker->send($count);
               $writeTimes[$socketID] = microtime(true);
               return; // stay registered for EVENT_READ
            }

            // @ Partial/failed write — defer the remainder to the event loop
            //   (onDataWrite flips the connection back to read mode after flush).
            $Tracker->queue($count === 1 ? $length : $lengths);
            $Tracker->accept($remaining);
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

         // @ Record start time
         $startTime = microtime(true);

         // @ Set timer to stop the event loop after duration
         Timer::add(
            interval: $duration,
            handler: function () {
               TCP_Client_CLI::$Event->destroy();
            },
            persistent: false,
         );

         // @ Enter event loop (blocks until Timer destroys it)
         TCP_Client_CLI::$Event->loop();
      }
   );

   $Client->start();

   // ! The duration timer only stops the reactor. Reconcile each live output
   //   suffix, then give every scheduled request one terminal classification.
   foreach ($Connections as $socketID => $Connection) {
      if (isset($Trackers[$socketID])) {
         $Trackers[$socketID]->accept(\strlen($Connection->output));
         $Trackers[$socketID]->abort('measurement_ended');
      }
   }

   // @ Calculate results
   $elapsed = microtime(true) - $startTime;

   $stats = [
      'scheduled' => 0,
      'sent' => 0,
      'responses' => 0,
      'informational' => 0,
      'outstanding' => 0,
      'statuses' => [],
      'failures' => [],
      'write_failures' => [],
      'connection_failures' => $connectionFailed,
      'partial_writes' => 0,
      'accounting' => $connectionFailed === 0,
      'bytes_read' => $bytesRead,
      'elapsed' => $elapsed,
      'latency_sum' => $latencySum,
      'latency_count' => $latencyCount,
   ];

   foreach ($Trackers as $Tracker) {
      $snapshot = $Tracker->inspect();
      foreach (['scheduled', 'sent', 'responses', 'informational', 'outstanding', 'partial_writes'] as $key) {
         $stats[$key] += $snapshot[$key];
      }
      foreach (['statuses', 'failures', 'write_failures'] as $map) {
         foreach ($snapshot[$map] as $key => $count) {
            $stats[$map][$key] = ($stats[$map][$key] ?? 0) + $count;
         }
      }
      $stats['accounting'] = $stats['accounting'] && $snapshot['accounting'];
   }

   \ksort($stats['statuses']);
   \ksort($stats['failures']);
   \ksort($stats['write_failures']);
   $failed = \array_sum($stats['failures']);
   $writeFailed = \array_sum($stats['write_failures']);
   $stats['accounting'] = $stats['accounting']
      && $stats['connection_failures'] === 0
      && $stats['outstanding'] === 0
      && $stats['scheduled'] === $stats['sent'] + $writeFailed
      && $stats['sent'] === $stats['responses'] + $failed
      && $stats['responses'] === \array_sum($stats['statuses']);

   if ($statsFile !== null) {
      // Multi-worker mode: publish one atomic file assigned to this child PID.
      RunArtifacts::commit($statsFile, json_encode($stats, JSON_THROW_ON_ERROR));
   } else {
      // Single-worker mode: output directly
      outputResults($stats);
   }
}

/**
 * @param array<string,mixed> $stats Closed worker accounting plus timing data.
 */
function outputResults (array $stats): void
{
   $responses = (int) ($stats['responses'] ?? 0);
   $bytesRead = (int) ($stats['bytes_read'] ?? 0);
   $elapsed = (float) ($stats['elapsed'] ?? 0.0);
   $latencySum = (float) ($stats['latency_sum'] ?? 0.0);
   $latencyCount = (int) ($stats['latency_count'] ?? 0);
   $failed = \array_sum($stats['failures'] ?? []);
   $writeFailed = \array_sum($stats['write_failures'] ?? []);
   $accounting = ($stats['accounting'] ?? false) === true
      && \is_finite($elapsed)
      && $elapsed > 0
      && \is_finite($latencySum)
      && $latencySum >= 0
      && (int) ($stats['connection_failures'] ?? -1) === 0
      && (int) ($stats['outstanding'] ?? -1) === 0
      && (int) ($stats['scheduled'] ?? -1) === (int) ($stats['sent'] ?? -2) + $writeFailed
      && (int) ($stats['sent'] ?? -1) === $responses + $failed
      && $responses === \array_sum($stats['statuses'] ?? []);
   $RPS = $accounting && $elapsed > 0 ? $responses / $elapsed : null;
   $transferPerSec = $elapsed > 0 ? $bytesRead / $elapsed : 0.0;
   $avgLatency = $latencyCount > 0 ? ($latencySum / $latencyCount) : null;

   if ($transferPerSec >= 1_073_741_824) {
      $transferStr = \number_format($transferPerSec / 1_073_741_824, 2) . 'GB';
   } elseif ($transferPerSec >= 1_048_576) {
      $transferStr = \number_format($transferPerSec / 1_048_576, 2) . 'MB';
   } elseif ($transferPerSec >= 1024) {
      $transferStr = \number_format($transferPerSec / 1024, 2) . 'KB';
   } else {
      $transferStr = \number_format($transferPerSec, 0) . 'B';
   }

   // @ Format latency
   $latencyStr = null;
   if ($avgLatency !== null) {
      if ($avgLatency >= 1.0) {
         $latencyStr = \number_format($avgLatency * 1000, 2) . 'ms';
      } elseif ($avgLatency >= 0.001) {
         $latencyStr = \number_format($avgLatency * 1000, 2) . 'ms';
      } else {
         $latencyStr = \number_format($avgLatency * 1_000_000, 2) . 'us';
      }
   }

   echo json_encode([
      'rps'      => $RPS !== null ? \round($RPS, 2) : null,
      'latency'  => $latencyStr,
      'transfer' => "{$transferStr}/s",
      'scheduled' => (int) ($stats['scheduled'] ?? 0),
      'sent' => (int) ($stats['sent'] ?? 0),
      'responses' => $responses,
      'informational' => (int) ($stats['informational'] ?? 0),
      'outstanding' => (int) ($stats['outstanding'] ?? 0),
      'failed' => $failed,
      'write_failed' => $writeFailed,
      'connection_failed' => (int) ($stats['connection_failures'] ?? 0),
      'accounting' => $accounting,
      'statuses' => (object) ($stats['statuses'] ?? []),
      'failures' => (object) ($stats['failures'] ?? []),
      'write_failures' => (object) ($stats['write_failures'] ?? []),
      'partial_writes' => (int) ($stats['partial_writes'] ?? 0),
   ]);
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
$forkFailed = false;
$baseConnections = \intdiv($connections, $requestedWorkers);
$extraConnections = $connections % $requestedWorkers;

for ($w = 0; $w < $requestedWorkers; $w++) {
   $workerConnections = $baseConnections + ($w < $extraConnections ? 1 : 0);
   $PID = \pcntl_fork();

   if ($PID === 0) {
      // Child process
      $statsFile = $Artifacts->resolve('child-' . \getmypid() . '.json');
      runWorker($host, $port, $workerConnections, $duration,
                $method, $requests, $requestLengths, $pathCount,
                $pipelinedRequests, $pipelinedLengths, $pipelinePathCount, $statsFile);
      exit(0);
   } elseif ($PID > 0) {
      $childPIDs[] = $PID;
      $childFiles[$PID] = $Artifacts->resolve("child-{$PID}.json");
   } else {
      $forkFailed = true;
      \fwrite(\STDERR, "ERROR: pcntl_fork() failed.\n");
   }
}

// @ Parent: wait for all children
foreach ($childPIDs as $PID) {
   $waited = \pcntl_waitpid($PID, $status);
   $childStatuses[$PID] = $waited === $PID
      && \pcntl_wifexited($status)
      && \pcntl_wexitstatus($status) === 0;
}

// @ Aggregate only the exact files assigned to successful child PIDs.
$stats = [
   'scheduled' => 0,
   'sent' => 0,
   'responses' => 0,
   'informational' => 0,
   'outstanding' => 0,
   'statuses' => [],
   'failures' => [],
   'write_failures' => [],
   'connection_failures' => 0,
   'partial_writes' => 0,
   'accounting' => $forkFailed === false && \count($childPIDs) === $requestedWorkers,
   'bytes_read' => 0,
   'elapsed' => 0.0,
   'latency_sum' => 0.0,
   'latency_count' => 0,
];
$integerFields = [
   'scheduled', 'sent', 'responses', 'informational', 'outstanding',
   'partial_writes', 'bytes_read', 'latency_count',
   'connection_failures',
];

foreach ($childPIDs as $PID) {
   $file = $childFiles[$PID];
   $contents = @\file_get_contents($file);
   $data = $contents === false ? null : \json_decode($contents, true);
   $childElapsed = $data['elapsed'] ?? null;
   $childLatency = $data['latency_sum'] ?? null;
   $valid = ($childStatuses[$PID] ?? false)
      && \is_array($data)
      && \is_bool($data['accounting'] ?? null)
      && \is_array($data['statuses'] ?? null)
      && \is_array($data['failures'] ?? null)
      && \is_array($data['write_failures'] ?? null)
      && (\is_int($childElapsed) || \is_float($childElapsed))
      && (\is_int($childLatency) || \is_float($childLatency))
      && \is_finite((float) $childElapsed)
      && (float) $childElapsed > 0
      && \is_finite((float) $childLatency)
      && (float) $childLatency >= 0;

   foreach ($integerFields as $field) {
      $valid = $valid && \is_int($data[$field] ?? null) && $data[$field] >= 0;
   }

   if ($valid) {
      foreach (['statuses', 'failures', 'write_failures'] as $map) {
         foreach ($data[$map] as $key => $count) {
            $valid = $valid && \is_int($count) && $count >= 0;
            if ($map === 'statuses') {
               $valid = $valid && \is_int($key) && $key >= 100 && $key <= 599;
            }
         }
      }
   }

   $valid = $valid
      && $data['accounting'] === true
      && $data['connection_failures'] === 0
      && $data['outstanding'] === 0
      && $data['scheduled'] === $data['sent'] + \array_sum($data['write_failures'])
      && $data['sent'] === $data['responses'] + \array_sum($data['failures'])
      && $data['responses'] === \array_sum($data['statuses']);

   if ($valid) {
      foreach ($integerFields as $field) {
         if ($field !== 'bytes_read' && $field !== 'latency_count') {
            $stats[$field] += $data[$field];
         }
      }
      $stats['bytes_read'] += $data['bytes_read'];
      $stats['latency_count'] += $data['latency_count'];
      $stats['elapsed'] = max($stats['elapsed'], (float) $data['elapsed']);
      $stats['latency_sum'] += (float) $data['latency_sum'];

      foreach (['statuses', 'failures', 'write_failures'] as $map) {
         foreach ($data[$map] as $key => $count) {
            $stats[$map][$key] = ($stats[$map][$key] ?? 0) + $count;
         }
      }
   }

   $stats['accounting'] = $stats['accounting'] && $valid;
}

\ksort($stats['statuses']);
\ksort($stats['failures']);
\ksort($stats['write_failures']);
$stats['accounting'] = $stats['accounting']
   && $stats['connection_failures'] === 0
   && $stats['outstanding'] === 0
   && $stats['scheduled'] === $stats['sent'] + \array_sum($stats['write_failures'])
   && $stats['sent'] === $stats['responses'] + \array_sum($stats['failures'])
   && $stats['responses'] === \array_sum($stats['statuses']);

outputResults($stats);
$Artifacts->clean();
exit(0);
