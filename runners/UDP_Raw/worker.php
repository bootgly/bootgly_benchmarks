<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — UDP_Raw Benchmark Worker
 * --------------------------------------------------------------------------
 * Standalone subprocess that generates raw UDP load using UDP_Client_CLI.
 * Spawned by UDP_Raw runner per load.
 *
 * Usage:
 *   php worker.php --host=127.0.0.1 --port=8084 --connections=514
 *                  --duration=10 --load-file=/tmp/load.json
 *                  [--workers=4]
 *
 * Load JSON format:
 *   {"message": "PING\n"}
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
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Events;

require_once __DIR__ . '/../RunArtifacts.php';


// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', [
   'host:',
   'port:',
   'connections:',
   'duration:',
   'load-file:',
   'workers:',
]);

$host          = $opts['host']          ?? '127.0.0.1';
$port          = (int) ($opts['port']          ?? 8084);
$connections   = (int) ($opts['connections']   ?? 514);
$duration      = (int) ($opts['duration']      ?? 10);
$loadFile  = $opts['load-file'] ?? '';

if ($loadFile === '' || !\file_exists($loadFile)) {
   \fwrite(\STDERR, "ERROR: --load-file is required and must exist.\n");
   exit(1);
}


// ---------------------------------------------------------------------------
// Load load
// ---------------------------------------------------------------------------
$json = file_get_contents($loadFile);
if ($json === false) {
   \fwrite(\STDERR, "ERROR: Cannot read load file.\n");
   exit(1);
}

$decoded = json_decode($json, true);
if (
   !\is_array($decoded)
   || !isset($decoded['message'])
   || !\is_string($decoded['message'])
   || $decoded['message'] === ''
) {
   \fwrite(\STDERR, "ERROR: Invalid load data. Need 'message'.\n");
   exit(1);
}

$message = $decoded['message'];


// ---------------------------------------------------------------------------
// Determine worker count based on FD_SETSIZE limit
// ---------------------------------------------------------------------------
$maxFdsPerWorker = 1000;
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
// Worker function: runs a single-process UDP Client benchmark
// ---------------------------------------------------------------------------
function runWorker (
   string $host, int $port, int $workerConnections, int $duration,
   string $message,
   ?string $statsFile
): void {
   $responsesReceived = 0;
   $bytesRead         = 0;
   $startTime         = 0.0;
   $latencySum        = 0.0;
   $latencyCount      = 0;
   /** @var array<int,float> $writeTimes */
   $writeTimes        = [];

   $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_DEFAULT);
   /** @var \Bootgly\ACI\Process $Process */
   $Process = $Client->__get('Process');
   $Process->State->lock(LOCK_UN);

   $Client->configure(
      host: $host,
      port: $port,
      workers: 0,
   );

   $Client->on(
      Events::WorkerStarted,
      function (UDP_Client_CLI $Client)
         use ($workerConnections, $message, $duration,
              &$responsesReceived, &$bytesRead, &$startTime,
              &$latencySum, &$latencyCount, &$writeTimes)
      {
         UDP_Client_CLI::$onClientConnect = function ($Socket, $Connection)
            use ($message, &$writeTimes)
         {
            // UDP writes are instant (non-blocking sendto); send immediately
            // and register EVENT_READ to wait for the echo reply.
            $Connection->output = $message;
            $Connection->writing($Socket);
            $writeTimes[(int) $Socket] = microtime(true);

            UDP_Client_CLI::$Event->add($Socket, UDP_Client_CLI::$Event::EVENT_READ, $Connection);
         };

         UDP_Client_CLI::$onDatagramRead = function ($Socket, $Connection, $Package)
            use ($message, &$responsesReceived, &$bytesRead,
                 &$latencySum, &$latencyCount, &$writeTimes)
         {
            $input = $Package->input;
            $responsesReceived++;
            $bytesRead += \strlen($input);

            $socketId = (int) $Socket;
            if (isset($writeTimes[$socketId])) {
               $latencySum   += microtime(true) - $writeTimes[$socketId];
               $latencyCount++;
            }

            // @ Remove READ, send next datagram, re-register READ
            UDP_Client_CLI::$Event->del($Socket, UDP_Client_CLI::$Event::EVENT_READ);
            $Connection->output = $message;
            $Connection->writing($Socket);
            $writeTimes[$socketId] = microtime(true);
            UDP_Client_CLI::$Event->add($Socket, UDP_Client_CLI::$Event::EVENT_READ, $Connection);
         };

         // @ Open sockets
         for ($i = 0; $i < $workerConnections; $i++) {
            $socket = $Client->connect();
            if ($socket === false) break;
         }

         // @ Record start time
         $startTime = microtime(true);

         // @ Stop event loop after duration
         Timer::add(
            interval: $duration,
            handler: function () {
               UDP_Client_CLI::$Event->destroy();
            },
            persistent: false,
         );

         // @ Enter event loop
         UDP_Client_CLI::$Event->loop();
      }
   );

   $Client->start();

   $elapsed = microtime(true) - $startTime;

   if ($statsFile !== null) {
      RunArtifacts::commit($statsFile, json_encode([
         'responses'     => $responsesReceived,
         'bytes_read'    => $bytesRead,
         'elapsed'       => $elapsed,
         'latency_sum'   => $latencySum,
         'latency_count' => $latencyCount,
      ], JSON_THROW_ON_ERROR));
   } else {
      outputResults($responsesReceived, $bytesRead, $elapsed, $latencySum, $latencyCount);
   }
}

function outputResults (int $responses, int $bytesRead, float $elapsed, float $latencySum = 0.0, int $latencyCount = 0): void
{
   $rps = $elapsed > 0 ? $responses / $elapsed : 0.0;
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

   $latencyStr = null;
   if ($avgLatency !== null) {
      if ($avgLatency >= 0.001) {
         $latencyStr = \number_format($avgLatency * 1000, 2) . 'ms';
      } else {
         $latencyStr = \number_format($avgLatency * 1_000_000, 2) . 'us';
      }
   }

   echo json_encode([
      'rps'      => (string) \round($rps, 2),
      'latency'  => $latencyStr,
      'transfer' => "{$transferStr}/s",
   ]);
}


// ---------------------------------------------------------------------------
// Execute: single-worker or multi-worker
// ---------------------------------------------------------------------------
if ($requestedWorkers <= 1) {
   runWorker($host, $port, $connectionsPerWorker, $duration,
             $message, null);
   exit(0);
}

// @ Multi-worker: fork N children into this invocation's exclusive workspace.
$Artifacts = RunArtifacts::create('udp-raw-workers');
$childPIDs = [];
$childFiles = [];
$childStatuses = [];
$forkFailed = false;

for ($w = 0; $w < $requestedWorkers; $w++) {
   $PID = \pcntl_fork();

   if ($PID === 0) {
      $statsFile = $Artifacts->resolve('child-' . \getmypid() . '.json');
      runWorker($host, $port, $connectionsPerWorker, $duration,
                $message, $statsFile);
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

// @ Aggregate only the exact file assigned to each successful child PID.
$totalResponses    = 0;
$totalBytesRead    = 0;
$maxElapsed        = 0.0;
$totalLatencySum   = 0.0;
$totalLatencyCount = 0;
$validAggregation = !$forkFailed && count($childPIDs) === $requestedWorkers;

foreach ($childPIDs as $PID) {
   $file = $childFiles[$PID];
   $contents = @file_get_contents($file);
   $data = $contents === false ? null : json_decode($contents, true);
   $elapsed = $data['elapsed'] ?? null;
   $latencySum = $data['latency_sum'] ?? null;
   $valid = ($childStatuses[$PID] ?? false)
      && \is_array($data)
      && \is_int($data['responses'] ?? null) && $data['responses'] >= 0
      && \is_int($data['bytes_read'] ?? null) && $data['bytes_read'] >= 0
      && \is_int($data['latency_count'] ?? null) && $data['latency_count'] >= 0
      && (\is_int($elapsed) || \is_float($elapsed))
      && \is_finite((float) $elapsed) && (float) $elapsed > 0
      && (\is_int($latencySum) || \is_float($latencySum))
      && \is_finite((float) $latencySum) && (float) $latencySum >= 0;

   if ($valid) {
      $totalResponses += $data['responses'];
      $totalBytesRead += $data['bytes_read'];
      $maxElapsed = max($maxElapsed, (float) $elapsed);
      $totalLatencySum += (float) $latencySum;
      $totalLatencyCount += $data['latency_count'];
   }

   $validAggregation = $validAggregation && $valid;
}

if (!$validAggregation) {
   \fwrite(\STDERR, "ERROR: one or more benchmark workers failed or produced invalid stats.\n");
   $Artifacts->clean();
   exit(1);
}

outputResults($totalResponses, $totalBytesRead, $maxElapsed, $totalLatencySum, $totalLatencyCount);
$Artifacts->clean();
exit(0);
