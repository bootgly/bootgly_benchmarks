<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — Benchmark Worker
 * --------------------------------------------------------------------------
 * Standalone subprocess that generates HTTP load using HTTP_Client_CLI.
 * Spawned by http_client runner per scenario.
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
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


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
// Load scenario data
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
   \fwrite(\STDERR, "ERROR: Invalid scenario data.\n");
   exit(1);
}

/** @var array{method: string, paths: array<string>} $scenario */
$scenario = $decoded;

$method    = $scenario['method'];
$paths     = $scenario['paths'];
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
Logger::$display = Logger::DISPLAY_NONE;


// ---------------------------------------------------------------------------
// Install SIGALRM handler for Timer BEFORE fork (children inherit it)
// ---------------------------------------------------------------------------
\pcntl_signal(SIGALRM, [Timer::class, 'tick']);


// ---------------------------------------------------------------------------
// Worker function: runs a single-process HTTP Client benchmark
// ---------------------------------------------------------------------------
/**
 * @param array<string> $paths Scenario paths.
 */
function runWorker (
   string $host, int $port, int $workerConnections, int $duration,
   string $method, array $paths, int $pathCount,
   ?string $statsFile
): void {
   $responsesReceived = 0;
   $requestIndex      = 0;
   $startTime         = 0.0;
   $latencySum        = 0.0;
   $latencyCount      = 0;
   /** @var array<int,float> $writeTimes */
   $writeTimes        = [];

   // @ Bootstrap HTTP Client
   $Client = new HTTP_Client_CLI;
   /** @var \Bootgly\ACI\Process $Process */
   $Process = $Client->__get('Process');
   $Process->State->lock(LOCK_UN);

   $Client->configure(
      host: $host,
      port: $port,
      workers: 0,
   );

   // @ Register HTTP hooks
   $Client->on(
      instance: function (HTTP_Client_CLI $Client)
         use ($method, $paths, $pathCount, $workerConnections, $duration, &$requestIndex, &$startTime)
      {
         // @ Prepare initial request
         $Client->request($method, $paths[$requestIndex++ % $pathCount]);

         // @ Open connections (auto-sends the pending request on each)
         for ($i = 0; $i < $workerConnections; $i++) {
            $socket = $Client->connect();
            if ($socket === false) break;
         }

         // @ Record start time
         $startTime = microtime(true);

         // @ Set timer to stop the event loop after duration
         Timer::add(
            interval: $duration,
            handler: function () {
               HTTP_Client_CLI::$Event->destroy();
            },
            persistent: false,
         );

         // @ Enter event loop (blocks until Timer destroys it)
         HTTP_Client_CLI::$Event->loop();
      },
      write: function ($Socket)
         use (&$writeTimes, &$latencySum, &$latencyCount)
      {
         $socketId = (int) $Socket;
         $now = microtime(true);

         // @ Compute latency from previous request on this socket
         if (isset($writeTimes[$socketId])) {
            $latencySum += $now - $writeTimes[$socketId];
            $latencyCount++;
         }

         $writeTimes[$socketId] = $now;
      },
      response: function ()
         use ($Client, $method, $paths, $pathCount, &$requestIndex, &$responsesReceived)
      {
         $responsesReceived++;

         // @ Queue next request (auto-sent by HTTP_Client_CLI)
         $Client->request($method, $paths[$requestIndex++ % $pathCount]);
      }
   );

   $Client->start();

   // @ Calculate results
   $elapsed = microtime(true) - $startTime;
   $bytesRead = HTTP_Client_CLI::$bytesReceived;

   if ($statsFile !== null) {
      file_put_contents($statsFile, json_encode([
         'responses' => $responsesReceived,
         'bytes_read' => $bytesRead,
         'elapsed' => $elapsed,
         'latency_sum' => $latencySum,
         'latency_count' => $latencyCount,
      ]));
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
      'rps'      => \round($rps, 2),
      'latency'  => $latencyStr,
      'transfer' => "{$transferStr}/s",
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

// @ Multi-worker: fork N children
$tmpDir    = sys_get_temp_dir();
$statsBase = "{$tmpDir}/bootgly_bench_" . \getmypid() . '_';
$childPids = [];

for ($w = 0; $w < $requestedWorkers; $w++) {
   $pid = \pcntl_fork();

   if ($pid === 0) {
      // Child process
      $statsFile = "{$statsBase}" . \getmypid() . '.json';
      runWorker($host, $port, $connectionsPerWorker, $duration,
                $method, $paths, $pathCount, $statsFile);
      exit(0);
   } elseif ($pid > 0) {
      $childPids[] = $pid;
   } else {
      \fwrite(\STDERR, "ERROR: pcntl_fork() failed.\n");
   }
}

// @ Parent: wait for all children
foreach ($childPids as $pid) {
   \pcntl_waitpid($pid, $status);
}

// @ Aggregate stats from temp files
$totalResponses  = 0;
$totalBytesRead  = 0;
$maxElapsed      = 0.0;
$totalLatencySum   = 0.0;
$totalLatencyCount = 0;

$statsFiles = glob("{$statsBase}*.json") ?: [];
foreach ($statsFiles as $file) {
   $data = json_decode((string) file_get_contents($file), true);
   if (!\is_array($data)) continue;

   /** @var array{responses?: int, bytes_read?: int, elapsed?: float, latency_sum?: float, latency_count?: int} $data */
   $totalResponses    += $data['responses'] ?? 0;
   $totalBytesRead    += $data['bytes_read'] ?? 0;
   $maxElapsed         = max($maxElapsed, (float) ($data['elapsed'] ?? 0.0));
   $totalLatencySum   += (float) ($data['latency_sum'] ?? 0.0);
   $totalLatencyCount += (int) ($data['latency_count'] ?? 0);

   @\unlink($file);
}

outputResults($totalResponses, $totalBytesRead, $maxElapsed, $totalLatencySum, $totalLatencyCount);
exit(0);
