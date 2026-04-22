<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — TCP_Raw Benchmark Worker
 * --------------------------------------------------------------------------
 * Standalone subprocess that generates raw TCP load using TCP_Client_CLI.
 * Spawned by TCP_Raw runner per scenario.
 *
 * Usage:
 *   php worker.php --host=127.0.0.1 --port=8083 --connections=514
 *                  --duration=10 --scenario-file=/tmp/scenario.json
 *                  [--workers=4]
 *
 * Scenario JSON format:
 *   {"message": "PING\n", "delimiter": "\n"}
 *   {"message": "GET / HTTP/1.1\r\n...", "delimiter": "HTTP/1."}
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
use Bootgly\WPI\Interfaces\TCP_Client_CLI;


// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', [
   'host:',
   'port:',
   'connections:',
   'duration:',
   'scenario-file:',
   'workers:',
]);

$host          = $opts['host']          ?? '127.0.0.1';
$port          = (int) ($opts['port']          ?? 8083);
$connections   = (int) ($opts['connections']   ?? 514);
$duration      = (int) ($opts['duration']      ?? 10);
$scenarioFile  = $opts['scenario-file'] ?? '';

if ($scenarioFile === '' || !\file_exists($scenarioFile)) {
   \fwrite(\STDERR, "ERROR: --scenario-file is required and must exist.\n");
   exit(1);
}


// ---------------------------------------------------------------------------
// Load scenario
// ---------------------------------------------------------------------------
$json = file_get_contents($scenarioFile);
if ($json === false) {
   \fwrite(\STDERR, "ERROR: Cannot read scenario file.\n");
   exit(1);
}

$decoded = json_decode($json, true);
if (
   !\is_array($decoded)
   || !isset($decoded['message'], $decoded['delimiter'])
   || !\is_string($decoded['message'])
   || !\is_string($decoded['delimiter'])
   || $decoded['message'] === ''
   || $decoded['delimiter'] === ''
) {
   \fwrite(\STDERR, "ERROR: Invalid scenario data. Need 'message' and 'delimiter'.\n");
   exit(1);
}

$message   = $decoded['message'];
$delimiter = $decoded['delimiter'];


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
Logger::$display = Logger::DISPLAY_NONE;


// ---------------------------------------------------------------------------
// Install SIGALRM handler for Timer BEFORE fork (children inherit it)
// ---------------------------------------------------------------------------
\pcntl_signal(SIGALRM, [Timer::class, 'tick']);


// ---------------------------------------------------------------------------
// Worker function: runs a single-process TCP Client benchmark
// ---------------------------------------------------------------------------
function runWorker (
   string $host, int $port, int $workerConnections, int $duration,
   string $message, string $delimiter,
   ?string $statsFile
): void {
   $responsesReceived = 0;
   $bytesRead         = 0;
   $startTime         = 0.0;
   $latencySum        = 0.0;
   $latencyCount      = 0;
   /** @var array<int,float> $writeTimes */
   $writeTimes        = [];

   $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_DEFAULT);
   /** @var \Bootgly\ACI\Process $Process */
   $Process = $Client->__get('Process');
   $Process->State->lock(LOCK_UN);

   $Client->configure(
      host: $host,
      port: $port,
      workers: 0,
   );

   $Client->on(
      instance: function (TCP_Client_CLI $Client)
         use ($workerConnections, $message, $delimiter, $duration,
              &$responsesReceived, &$bytesRead, &$startTime,
              &$latencySum, &$latencyCount, &$writeTimes)
      {
         TCP_Client_CLI::$onConnect = function ($Socket, $Connection)
            use ($message)
         {
            $Connection->output = $message;
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         };

         TCP_Client_CLI::$onWrite = function ($Socket, $Connection, $Package)
            use (&$writeTimes)
         {
            $writeTimes[(int) $Socket] = microtime(true);
            TCP_Client_CLI::$Event->del($Socket, TCP_Client_CLI::$Event::EVENT_WRITE);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_READ, $Connection);
         };

         TCP_Client_CLI::$onRead = function ($Socket, $Connection, $Package)
            use ($message, $delimiter, &$responsesReceived, &$bytesRead,
                 &$latencySum, &$latencyCount, &$writeTimes)
         {
            $input = $Package->input;
            $count = substr_count($input, $delimiter);
            $responsesReceived += $count;
            $bytesRead += \strlen($input);

            $socketId = (int) $Socket;
            if (isset($writeTimes[$socketId])) {
               $latency = microtime(true) - $writeTimes[$socketId];
               $latencySum += $latency * $count;
               $latencyCount += $count;
               unset($writeTimes[$socketId]);
            }

            // @ Send next message (no pipelining: 1 message at a time)
            $Connection->output = $message;
            TCP_Client_CLI::$Event->del($Socket, TCP_Client_CLI::$Event::EVENT_READ);
            TCP_Client_CLI::$Event->add($Socket, TCP_Client_CLI::$Event::EVENT_WRITE, $Connection);
         };

         // @ Open connections
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
               TCP_Client_CLI::$Event->destroy();
            },
            persistent: false,
         );

         // @ Enter event loop
         TCP_Client_CLI::$Event->loop();
      },
   );

   $Client->start();

   // @ Calculate results
   $elapsed = microtime(true) - $startTime;

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

   $latencyStr = null;
   if ($avgLatency !== null) {
      if ($avgLatency >= 0.001) {
         $latencyStr = \number_format($avgLatency * 1000, 2) . 'ms';
      } else {
         $latencyStr = \number_format($avgLatency * 1_000_000, 2) . 'us';
      }
   }

   echo json_encode([
      'rps'      => (string) \round($rps, 2) . ' msg/s',
      'latency'  => $latencyStr,
      'transfer' => "{$transferStr}/s",
   ]);
}


// ---------------------------------------------------------------------------
// Execute: single-worker or multi-worker
// ---------------------------------------------------------------------------
if ($requestedWorkers <= 1) {
   runWorker($host, $port, $connectionsPerWorker, $duration,
             $message, $delimiter, null);
   exit(0);
}

// @ Multi-worker: fork N children
$tmpDir    = sys_get_temp_dir();
$statsBase = "{$tmpDir}/bootgly_tcp_bench_" . \getmypid() . '_';
$childPids = [];

for ($w = 0; $w < $requestedWorkers; $w++) {
   $pid = \pcntl_fork();

   if ($pid === 0) {
      $statsFile = "{$statsBase}" . \getmypid() . '.json';
      runWorker($host, $port, $connectionsPerWorker, $duration,
                $message, $delimiter, $statsFile);
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
$totalResponses    = 0;
$totalBytesRead    = 0;
$maxElapsed        = 0.0;
$totalLatencySum   = 0.0;
$totalLatencyCount = 0;

$statsFiles = glob("{$statsBase}*.json") ?: [];
foreach ($statsFiles as $file) {
   $data = json_decode((string) file_get_contents($file), true);
   if (!\is_array($data)) continue;

   $totalResponses    += $data['responses'] ?? 0;
   $totalBytesRead    += $data['bytes_read'] ?? 0;
   $maxElapsed         = max($maxElapsed, (float) ($data['elapsed'] ?? 0.0));
   $totalLatencySum   += (float) ($data['latency_sum'] ?? 0.0);
   $totalLatencyCount += (int) ($data['latency_count'] ?? 0);

   @\unlink($file);
}

outputResults($totalResponses, $totalBytesRead, $maxElapsed, $totalLatencySum, $totalLatencyCount);
exit(0);
