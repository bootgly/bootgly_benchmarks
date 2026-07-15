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


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\Benchmarks\Runners\RunArtifacts;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;

require_once __DIR__ . '/../RunArtifacts.php';


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

/** @var array{method: string, paths: array<string>} $load */
$load = $decoded;

$method    = $load['method'];
$paths     = $load['paths'];
$pathCount = count($paths);


// ---------------------------------------------------------------------------
// Determine worker count based on FD_SETSIZE limit
// ---------------------------------------------------------------------------
$maxFdsPerWorker = 1000; // FD_SETSIZE=1024 minus ~24 reserved fds
$requestedWorkers = isset($opts['workers'])
   ? (int) $opts['workers']
   : (int) max(1, \ceil($connections / $maxFdsPerWorker));
$connectionsPerWorker = (int) \ceil($connections / $requestedWorkers);
if ($connectionsPerWorker > $maxFdsPerWorker) {
   $connectionsPerWorker = $maxFdsPerWorker;
}


// ---------------------------------------------------------------------------
// Suppress log output — we only want the JSON result on stdout
// ---------------------------------------------------------------------------
Display::show(Display::NONE);


// ---------------------------------------------------------------------------
// Install SIGALRM handler for Timer BEFORE fork (children inherit it)
// ---------------------------------------------------------------------------
\pcntl_signal(SIGALRM, [Timer::class, 'tick']);


// ---------------------------------------------------------------------------
// Worker function: runs a single-process HTTP Client benchmark
// ---------------------------------------------------------------------------
/**
 * @param array<string> $paths Load paths.
 */
function runWorker (
   string $host, int $port, int $workerConnections, int $duration,
   string $method, array $paths, int $pathCount,
   ?string $statsFile
): void {
   $scheduled       = 0;
   $sent            = 0;
   $responses       = 0;
   $concluded       = 0;
   $requestIndex    = 0;
   $startTime       = 0.0;
   $latencySum      = 0.0;
   $latencyCount    = 0;
   $connectionFailed = 0;
   $stopping        = false;
   /** @var array<int,int> $statuses */
   $statuses = [];
   /** @var array<string,int> $failures */
   $failures = [];
   /** @var array<int,float> $writeTimes */
   $writeTimes = [];

   // @ Once the requested window closes, stop replenishing and let already
   //   scheduled requests receive one terminal classification. Timer uses
   //   integer ticks, so a one-tick hard bound prevents a stalled drain.
   $Finish = static function () use (&$stopping, &$scheduled, &$sent, &$concluded): void {
      if ($stopping && $scheduled === $sent && $sent === $concluded) {
         HTTP_Client_CLI::$Event->destroy();
      }
   };

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
            $Finish,
            &$requestIndex,
            &$startTime,
            &$connectionFailed,
            &$stopping,
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

         // @ Record start time
         $startTime = microtime(true);

         // @ Close the traffic window, then drain only work already scheduled.
         Timer::add(
            interval: $duration,
            handler: function () use (&$stopping, $Finish): void {
               $stopping = true;
               $Finish();
            },
            persistent: false,
         );
         Timer::add(
            interval: $duration + 1,
            handler: static function (): void {
               HTTP_Client_CLI::$Event->destroy();
            },
            persistent: false,
         );

         // @ Enter event loop (blocks until Timer destroys it)
         HTTP_Client_CLI::$Event->loop();
      })
      ->on(Events::DataWrite, function ($Socket)
         use ($Finish, &$sent, &$writeTimes, &$latencySum, &$latencyCount)
      {
         $sent++;
         $socketID = (int) $Socket;
         $now = microtime(true);

         // @ Compute latency from previous request on this socket
         if (isset($writeTimes[$socketID])) {
            $latencySum += $now - $writeTimes[$socketID];
            $latencyCount++;
         }

         $writeTimes[$socketID] = $now;
         $Finish();
      })
      ->on(Events::ResponseReceive, function ($Request, $Response)
         use (
            $Client,
            $method,
            $paths,
            $pathCount,
            $Finish,
            &$requestIndex,
            &$scheduled,
            &$responses,
            &$concluded,
            &$statuses,
            &$failures,
            &$stopping,
         )
      {
         $concluded++;
         $status = (int) $Response->code;
         if ($status >= 200 && $status <= 599) {
            $responses++;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
         }
         else {
            $reason = strtolower(trim((string) $Response->status));
            $reason = preg_replace('/[^a-z0-9]+/', '_', $reason) ?? '';
            $reason = trim($reason, '_');
            $reason = $reason !== '' ? $reason : 'http_client_failure';
            $failures[$reason] = ($failures[$reason] ?? 0) + 1;
         }

         if (!$stopping) {
            // @ Queue next request (auto-sent by HTTP_Client_CLI).
            $scheduled++;
            $Client->request($method, $paths[$requestIndex++ % $pathCount]);
         }

         $Finish();
      });

   $Client->start();

   // ! Reconcile the terminal cut. Fully written requests without a response
   //   become measurement_ended failures; queued output that did not drain is
   //   conservatively classified as a partial write failure.
   $elapsed = microtime(true) - $startTime;
   $bytesRead = HTTP_Client_CLI::$bytesReceived;
   $writeFailed = max(0, $scheduled - $sent);
   $terminalFailed = max(0, $sent - $concluded);
   $writeFailures = $writeFailed > 0
      ? ['measurement_ended' => $writeFailed]
      : [];
   if ($terminalFailed > 0) {
      $failures['measurement_ended'] = ($failures['measurement_ended'] ?? 0) + $terminalFailed;
   }
   ksort($statuses);
   ksort($failures);
   ksort($writeFailures);
   $failed = array_sum($failures);
   $accounting = $connectionFailed === 0
      && $scheduled === $sent + $writeFailed
      && $sent === $responses + $failed
      && $responses === array_sum($statuses);
   $stats = [
      'scheduled' => $scheduled,
      'sent' => $sent,
      'responses' => $responses,
      'informational' => 0,
      'outstanding' => 0,
      'statuses' => $statuses,
      'failures' => $failures,
      'write_failures' => $writeFailures,
      'connection_failures' => $connectionFailed,
      'partial_writes' => $writeFailed,
      'accounting' => $accounting,
      'bytes_read' => $bytesRead,
      'elapsed' => $elapsed,
      'latency_sum' => $latencySum,
      'latency_count' => $latencyCount,
   ];

   if ($statsFile !== null) {
      RunArtifacts::commit($statsFile, json_encode($stats, JSON_THROW_ON_ERROR));
   }
   else {
      outputResults($stats);
   }
}

/** @param array<string,mixed> $stats */
function outputResults (array $stats): void
{
   $responses = (int) ($stats['responses'] ?? 0);
   $bytesRead = (int) ($stats['bytes_read'] ?? 0);
   $elapsed = (float) ($stats['elapsed'] ?? 0.0);
   $latencySum = (float) ($stats['latency_sum'] ?? 0.0);
   $latencyCount = (int) ($stats['latency_count'] ?? 0);
   $failed = array_sum($stats['failures'] ?? []);
   $writeFailed = array_sum($stats['write_failures'] ?? []);
   $accounting = ($stats['accounting'] ?? false) === true
      && is_finite($elapsed)
      && $elapsed > 0
      && is_finite($latencySum)
      && $latencySum >= 0
      && (int) ($stats['connection_failures'] ?? -1) === 0
      && (int) ($stats['outstanding'] ?? -1) === 0
      && (int) ($stats['scheduled'] ?? -1) === (int) ($stats['sent'] ?? -2) + $writeFailed
      && (int) ($stats['sent'] ?? -1) === $responses + $failed
      && $responses === array_sum($stats['statuses'] ?? []);
   $RPS = $accounting ? $responses / $elapsed : null;
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
      if ($avgLatency >= 0.001) {
         $latencyStr = \number_format($avgLatency * 1000, 2) . 'ms';
      } else {
         $latencyStr = \number_format($avgLatency * 1_000_000, 2) . 'us';
      }
   }

   echo json_encode([
      'rps'      => $RPS !== null ? \round($RPS, 2) : null,
      'latency'  => $latencyStr,
      'transfer' => "{$transferStr}/s",
      'elapsed' => $elapsed,
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
             $method, $paths, $pathCount, null);
   exit(0);
}

// @ Multi-worker: fork N children into this invocation's exclusive workspace.
$Artifacts = RunArtifacts::create('http-client-workers');
$childPIDs = [];
$childFiles = [];
$childStatuses = [];
$forkFailed = false;

for ($w = 0; $w < $requestedWorkers; $w++) {
   $PID = \pcntl_fork();

   if ($PID === 0) {
      // Child process
      $statsFile = $Artifacts->resolve('child-' . \getmypid() . '.json');
      runWorker($host, $port, $connectionsPerWorker, $duration,
                $method, $paths, $pathCount, $statsFile);
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

// @ Aggregate only closed accounting documents assigned to successful PIDs.
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
   'accounting' => !$forkFailed && count($childPIDs) === $requestedWorkers,
   'bytes_read' => 0,
   'elapsed' => 0.0,
   'latency_sum' => 0.0,
   'latency_count' => 0,
];
$integerFields = [
   'scheduled', 'sent', 'responses', 'informational', 'outstanding',
   'partial_writes', 'bytes_read', 'latency_count', 'connection_failures',
];

foreach ($childPIDs as $PID) {
   $file = $childFiles[$PID];
   $contents = @file_get_contents($file);
   $data = $contents === false ? null : json_decode($contents, true);
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
      && $data['scheduled'] === $data['sent'] + array_sum($data['write_failures'])
      && $data['sent'] === $data['responses'] + array_sum($data['failures'])
      && $data['responses'] === array_sum($data['statuses']);

   if ($valid) {
      foreach ($integerFields as $field) {
         $stats[$field] += $data[$field];
      }
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

ksort($stats['statuses']);
ksort($stats['failures']);
ksort($stats['write_failures']);
$stats['accounting'] = $stats['accounting']
   && $stats['connection_failures'] === 0
   && $stats['outstanding'] === 0
   && $stats['scheduled'] === $stats['sent'] + array_sum($stats['write_failures'])
   && $stats['sent'] === $stats['responses'] + array_sum($stats['failures'])
   && $stats['responses'] === array_sum($stats['statuses']);

if (!$stats['accounting']) {
   \fwrite(\STDERR, "ERROR: one or more benchmark workers produced invalid accounting.\n");
   $Artifacts->clean();
   exit(1);
}

outputResults($stats);
$Artifacts->clean();
exit(0);
