<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — WS_Raw Benchmark Worker
 * --------------------------------------------------------------------------
 * Standalone subprocess that generates WebSocket load using WS_Client_CLI.
 * Spawned by the WS_Raw runner per load. Three closed-loop scenarios, chosen
 * by the load's `mode`:
 *
 *   echo      — each connection sends a frame; on the echoed reply it counts
 *               and immediately resends (1 message in flight per connection).
 *   broadcast — every connection joins one server channel; two "ping-pong"
 *               senders keep the fan-out alive (each resends on receipt), and
 *               every received fan-out frame is counted across all connections.
 *   connect   — open → handshake → close, repeated in batches until the
 *               duration elapses; counts completed handshakes (conn/s).
 *
 * Usage:
 *   php worker.php --host=127.0.0.1 --port=8085 --connections=514
 *                  --duration=10 --load-file=/tmp/load.json [--workers=4]
 *
 * Load JSON format:
 *   {"mode": "echo",      "payload": "xxxx", "binary": false}
 *   {"mode": "broadcast", "payload": "xxxx", "binary": false}
 *   {"mode": "connect"}
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
   \define('BOOTGLY_VERSION', '0.19.0-beta');
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
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


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

$host        = $opts['host']        ?? '127.0.0.1';
$port        = (int) ($opts['port']        ?? 8085);
$connections = (int) ($opts['connections'] ?? 514);
$duration    = (int) ($opts['duration']    ?? 10);
$loadFile    = $opts['load-file'] ?? '';

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
if (!\is_array($decoded) || !isset($decoded['mode']) || !\is_string($decoded['mode'])) {
   \fwrite(\STDERR, "ERROR: Invalid load data. Need 'mode'.\n");
   exit(1);
}

$mode     = $decoded['mode'];
$payload  = isset($decoded['payload']) && \is_string($decoded['payload']) ? $decoded['payload'] : '';
$binary   = (bool) ($decoded['binary'] ?? false);
// @ echo only — number of frames kept in flight per connection (1 = closed
//   loop; > 1 pipelines, like HTTP plaintext, to measure frame-processing
//   throughput instead of round-trip latency).
$pipeline = \max(1, (int) ($decoded['pipeline'] ?? 1));

if (($mode === 'echo' || $mode === 'broadcast') && $payload === '') {
   \fwrite(\STDERR, "ERROR: '{$mode}' mode needs a non-empty 'payload'.\n");
   exit(1);
}
if (!\in_array($mode, ['echo', 'broadcast', 'connect'], true)) {
   \fwrite(\STDERR, "ERROR: Unknown mode '{$mode}'.\n");
   exit(1);
}


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

// # Broadcast is single-process by design. The fan-out rate is bound by one
//   client's receive loop, and extra client workers only add sender pairs that
//   thrash the server's fan-out (measured: throughput collapses as workers
//   grow). Force one process and keep exactly two senders; cap at FD_SETSIZE.
if ($mode === 'broadcast') {
   $requestedWorkers = 1;
   $connectionsPerWorker = \min($connections, $maxFdsPerWorker);
}


// ---------------------------------------------------------------------------
// Suppress log output — we only want the JSON result on stdout
// ---------------------------------------------------------------------------
Display::show(Display::NONE);


// ---------------------------------------------------------------------------
// Install SIGALRM handler for Timer BEFORE fork (children inherit it)
// ---------------------------------------------------------------------------
\pcntl_signal(SIGALRM, [Timer::class, 'tick']);


/**
 * Build a WS_Client_CLI in test mode (no Process/state-lock), configured for
 * the benchmark (no compression, no client heartbeat).
 */
function makeClient (string $host, int $port): WS_Client_CLI
{
   $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
   $Client->configure(
      host: $host,
      port: $port,
      compression: false,
      heartbeatInterval: 0,
   );

   return $Client;
}

/**
 * Open every constructed client, THEN run the shared loop. Clients MUST be
 * constructed before any open() — each constructor overwrites the static event
 * loop, so the live loop is the last-constructed one and every open() must
 * target it.
 *
 * @param array<int,WS_Client_CLI> $Clients
 */
function openAndRun (array $Clients, int $duration): void
{
   foreach ($Clients as $Client) {
      if ($Client->open('/') === false) {
         break;
      }
   }

   // @ Stop the shared loop after the duration window.
   Timer::add(
      interval: $duration,
      handler: function () {
         WS_Client_CLI::$Event->destroy();
      },
      persistent: false,
   );

   WS_Client_CLI::run();
}


// ---------------------------------------------------------------------------
// echo — closed-loop send/echo per connection
// ---------------------------------------------------------------------------
function runEcho (
   string $host, int $port, int $workerConnections, int $duration,
   string $payload, bool $binary, int $pipeline,
   ?string $statsFile
): void {
   $responses    = 0;
   $bytesRead    = 0;
   $latencySum   = 0.0;
   $latencyCount = 0;
   /** @var array<int,array<int,float>> $inflight id => FIFO of dispatch times */
   $inflight = [];

   // @ Send one frame and queue its dispatch time (FIFO per connection — a
   //   pipelined connection holds several frames in flight at once).
   $dispatch = function ($Session) use ($payload, $binary, &$inflight) {
      $inflight[\spl_object_id($Session)][] = \microtime(true);
      $Session->send($payload, $binary);
   };

   $onConnected = function ($Session) use ($dispatch, $pipeline) {
      // @ Prime the pipeline: $pipeline frames in flight from the start
      //   ($pipeline = 1 is the plain closed loop).
      for ($i = 0; $i < $pipeline; $i++) {
         $dispatch($Session);
      }
   };
   $onMessage = function ($Session, $Message) use (
      $dispatch, &$responses, &$bytesRead, &$latencySum, &$latencyCount, &$inflight
   ) {
      $responses++;
      $bytesRead += \strlen($Message->payload);

      // @ Match the echo to the oldest outstanding send (the server echoes in
      //   order), then replenish one frame to hold the in-flight depth constant.
      $id = \spl_object_id($Session);
      if (!empty($inflight[$id])) {
         $latencySum += \microtime(true) - \array_shift($inflight[$id]);
         $latencyCount++;
      }
      $dispatch($Session);
   };

   $Clients = [];
   for ($i = 0; $i < $workerConnections; $i++) {
      $Client = makeClient($host, $port);
      $Client->on(Events::Connected, $onConnected);
      $Client->on(Events::MessageReceived, $onMessage);
      $Clients[] = $Client;
   }

   $startTime = \microtime(true);
   openAndRun($Clients, $duration);
   $elapsed = \microtime(true) - $startTime;

   report($responses, $bytesRead, $elapsed, $latencySum, $latencyCount, $statsFile);
}


// ---------------------------------------------------------------------------
// broadcast — fan-out throughput; two ping-pong senders keep it alive
// ---------------------------------------------------------------------------
function runBroadcast (
   string $host, int $port, int $workerConnections, int $duration,
   string $payload, bool $binary,
   ?string $statsFile
): void {
   $responses    = 0;
   $bytesRead    = 0;
   $latencySum   = 0.0;
   $latencyCount = 0;
   /** @var array<int,float> $writeTimes */
   $writeTimes = [];

   /** @var array<int,object> $Senders id => client Session, for stall recovery */
   $Senders = [];

   // @ Senders: send on connect and resend on every receipt. With the server
   //   excluding the sender from its own fan-out, two senders form a 2-token
   //   loop (each sustains the other). Under a fork/handshake storm a sender's
   //   first token can be lost (no peer joined yet); the stall-recovery timer
   //   below re-kicks them, so the loop self-heals within a second.
   $onSenderConnected = function ($Session) use ($payload, $binary, &$writeTimes, &$Senders) {
      $Senders[\spl_object_id($Session)] = $Session;
      $writeTimes[\spl_object_id($Session)] = \microtime(true);
      $Session->send($payload, $binary);
   };
   $onSenderMessage = function ($Session, $Message) use (
      $payload, $binary, &$responses, &$bytesRead, &$latencySum, &$latencyCount, &$writeTimes
   ) {
      $responses++;
      $bytesRead += \strlen($Message->payload);

      $id = \spl_object_id($Session);
      if (isset($writeTimes[$id])) {
         $latencySum += \microtime(true) - $writeTimes[$id];
         $latencyCount++;
      }

      $writeTimes[$id] = \microtime(true);
      $Session->send($payload, $binary);
   };
   // @ Receivers: count fan-out frames; never send.
   $onReceiverMessage = function ($Session, $Message) use (&$responses, &$bytesRead) {
      $responses++;
      $bytesRead += \strlen($Message->payload);
   };

   $senders = \min(2, $workerConnections);

   $Clients = [];
   for ($i = 0; $i < $workerConnections; $i++) {
      $Client = makeClient($host, $port);
      if ($i < $senders) {
         $Client->on(Events::Connected, $onSenderConnected);
         $Client->on(Events::MessageReceived, $onSenderMessage);
      } else {
         $Client->on(Events::MessageReceived, $onReceiverMessage);
      }
      $Clients[] = $Client;
   }

   $startTime = \microtime(true);

   // @ Open all clients (each open() targets the shared, last-constructed loop).
   foreach ($Clients as $Client) {
      if ($Client->open('/') === false) {
         break;
      }
   }

   // @ Stall recovery: if no fan-out frame arrived in the last second, the token
   //   loop died at startup (all initial tokens lost in the handshake storm) —
   //   re-kick every sender. A healthy high-rate loop always advances $responses,
   //   so this never fires there (no unbounded token growth).
   $lastResponses = 0;
   Timer::add(
      interval: 1,
      handler: function () use (&$responses, &$lastResponses, &$Senders, $payload, $binary, &$writeTimes) {
         if ($responses === $lastResponses) {
            foreach ($Senders as $id => $Session) {
               if ($Session->disconnected) {
                  continue;
               }
               $writeTimes[$id] = \microtime(true);
               $Session->send($payload, $binary);
            }
         }
         $lastResponses = $responses;
      },
      persistent: true,
   );

   // @ Stop the shared loop after the duration window.
   Timer::add(
      interval: $duration,
      handler: function () {
         WS_Client_CLI::$Event->destroy();
      },
      persistent: false,
   );

   WS_Client_CLI::run();
   $elapsed = \microtime(true) - $startTime;

   report($responses, $bytesRead, $elapsed, $latencySum, $latencyCount, $statsFile);
}


// ---------------------------------------------------------------------------
// connect — handshake throughput; batches of open→handshake→close
// ---------------------------------------------------------------------------
function runConnect (
   string $host, int $port, int $workerConnections, int $duration,
   ?string $statsFile
): void {
   $handshakes   = 0;
   $latencySum   = 0.0;
   $latencyCount = 0;
   /** @var array<int,float> $dialTimes spl_object_id(client) => dial time */
   $dialTimes = [];

   // @ Latency = handshake completion (dial → Connected). The client Session
   //   carries a back-ref to its owning client, so the Connected hook can match
   //   the dial timestamp recorded just before open().
   $onConnected = function ($Session) use (&$handshakes, &$latencySum, &$latencyCount, &$dialTimes) {
      $handshakes++;
      $cid = \spl_object_id($Session->Client);
      if (isset($dialTimes[$cid])) {
         $latencySum += \microtime(true) - $dialTimes[$cid];
         $latencyCount++;
         unset($dialTimes[$cid]);
      }
      $Session->close();
   };

   // @ One global stop at t=duration; between batches the loop returns on its
   //   own (every connection closes on connect), so this only fires to cut a
   //   final in-flight batch.
   Timer::add(
      interval: $duration,
      handler: function () {
         WS_Client_CLI::$Event->destroy();
      },
      persistent: false,
   );

   $startTime = \microtime(true);
   while ((\microtime(true) - $startTime) < $duration) {
      $Clients = [];
      for ($i = 0; $i < $workerConnections; $i++) {
         $Client = makeClient($host, $port);
         $Client->on(Events::Connected, $onConnected);
         $Clients[] = $Client;
      }

      foreach ($Clients as $Client) {
         $dialTimes[\spl_object_id($Client)] = \microtime(true);
         if ($Client->open('/') === false) {
            break;
         }
      }

      WS_Client_CLI::run();
   }
   $elapsed = \microtime(true) - $startTime;

   // @ No payload bytes; handshakes are the "responses" for the conn/s metric,
   //   latency is the average handshake (upgrade) completion time.
   report($handshakes, 0, $elapsed, $latencySum, $latencyCount, $statsFile);
}


// ---------------------------------------------------------------------------
// Result emit / aggregate
// ---------------------------------------------------------------------------
function report (int $responses, int $bytesRead, float $elapsed, float $latencySum, int $latencyCount, ?string $statsFile): void
{
   if ($statsFile !== null) {
      file_put_contents($statsFile, json_encode([
         'responses'     => $responses,
         'bytes_read'    => $bytesRead,
         'elapsed'       => $elapsed,
         'latency_sum'   => $latencySum,
         'latency_count' => $latencyCount,
      ]));
      return;
   }

   outputResults($responses, $bytesRead, $elapsed, $latencySum, $latencyCount);
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

/**
 * Run one in-process scenario by mode.
 */
function runWorker (
   string $mode, string $host, int $port, int $workerConnections, int $duration,
   string $payload, bool $binary, int $pipeline, ?string $statsFile
): void {
   match ($mode) {
      'echo'      => runEcho($host, $port, $workerConnections, $duration, $payload, $binary, $pipeline, $statsFile),
      'broadcast' => runBroadcast($host, $port, $workerConnections, $duration, $payload, $binary, $statsFile),
      'connect'   => runConnect($host, $port, $workerConnections, $duration, $statsFile),
   };
}


// ---------------------------------------------------------------------------
// Execute: single-worker or multi-worker
// ---------------------------------------------------------------------------
if ($requestedWorkers <= 1) {
   runWorker($mode, $host, $port, $connectionsPerWorker, $duration, $payload, $binary, $pipeline, null);
   exit(0);
}

// @ Multi-worker: fork N children
$tmpDir    = sys_get_temp_dir();
$statsBase = "{$tmpDir}/bootgly_ws_bench_" . \getmypid() . '_';
$childPids = [];

for ($w = 0; $w < $requestedWorkers; $w++) {
   $pid = \pcntl_fork();

   if ($pid === 0) {
      $statsFile = "{$statsBase}" . \getmypid() . '.json';
      runWorker($mode, $host, $port, $connectionsPerWorker, $duration, $payload, $binary, $pipeline, $statsFile);
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

   $totalResponses    += $data['responses']      ?? 0;
   $totalBytesRead    += $data['bytes_read']      ?? 0;
   $maxElapsed         = max($maxElapsed, (float) ($data['elapsed']        ?? 0.0));
   $totalLatencySum   += (float) ($data['latency_sum']   ?? 0.0);
   $totalLatencyCount += (int)   ($data['latency_count'] ?? 0);

   @\unlink($file);
}

outputResults($totalResponses, $totalBytesRead, $maxElapsed, $totalLatencySum, $totalLatencyCount);
exit(0);
