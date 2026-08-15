<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — SSE_Raw worker (load generator)
 * --------------------------------------------------------------------------
 * Opens N persistent `text/event-stream` connections and counts the events the
 * server pushes down them.
 *
 * SSE is one-directional: after the request the client never writes again, so
 * there is no closed loop to pace the server and no round trip to time. That
 * makes the measurement genuinely different from every request/response load
 * in this suite — the number here is what the SERVER chose to deliver, not
 * what the client managed to ask for.
 *
 * Raw sockets on purpose. An SSE client is a head read plus a byte counter;
 * wrapping that in a protocol client would add a parser between the wire and
 * the count without measuring anything more.
 * --------------------------------------------------------------------------
 */

use Bootgly\Benchmarks\Runners\RunArtifacts;

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

$host        = $opts['host']               ?? '127.0.0.1';
$port        = (int) ($opts['port']        ?? 8082);
$connections = (int) ($opts['connections'] ?? 514);
$duration    = (int) ($opts['duration']    ?? 10);
$loadFile    = $opts['load-file']          ?? '';
$requestedWorkers = max(1, (int) ($opts['workers'] ?? 1));

if ($loadFile === '' || ! file_exists($loadFile)) {
   fwrite(STDERR, "ERROR: --load-file is required and must exist.\n");
   exit(1);
}

$json = file_get_contents($loadFile);
if ($json === false) {
   fwrite(STDERR, "ERROR: Cannot read load file.\n");
   exit(1);
}

$decoded = json_decode($json, true);
if (! is_array($decoded) || ! isset($decoded['mode']) || ! is_string($decoded['mode'])) {
   fwrite(STDERR, "ERROR: Invalid load data. Need 'mode'.\n");
   exit(1);
}

$mode = $decoded['mode'];
$path = isset($decoded['path']) && is_string($decoded['path'])
   ? $decoded['path']
   : '/sse/stream';

if ($mode !== 'stream' && $mode !== 'open') {
   fwrite(STDERR, "ERROR: unknown mode '{$mode}'. Use stream | open.\n");
   exit(1);
}

$connectionsPerWorker = (int) max(1, intdiv($connections, $requestedWorkers));


// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

/**
 * Hold N streams open and count the events pushed down them.
 *
 * @return array{events:int,bytes:int,elapsed:float,latency_sum:float,latency_count:int}
 */
function stream (string $host, int $port, int $connections, int $duration, string $path): array
{
   $Sockets = [];
   $opened = 0.0;
   $latencySum = 0.0;
   $latencyCount = 0;

   // @@ Establish every stream first. A stream still being opened is not yet
   //    receiving, so counting during setup would divide real events by a
   //    window that includes the ramp.
   for ($index = 0; $index < $connections; $index++) {
      $dialed = microtime(true);
      $Socket = @stream_socket_client("tcp://{$host}:{$port}", $code, $error, 5.0);

      if (is_resource($Socket) === false) {
         continue;
      }

      stream_set_timeout($Socket, 5);
      fwrite($Socket, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\n\r\n");

      // ? The head proves the stream OPENED. A refused SSE answers an ordinary
      //   finite response, and counting its bytes as stream traffic would
      //   report throughput for streams that never existed.
      $head = '';
      while (str_contains($head, "\r\n\r\n") === false) {
         $line = fgets($Socket, 512);

         if ($line === false) {
            break;
         }

         $head .= $line;
      }

      if (str_contains($head, 'text/event-stream') === false) {
         fclose($Socket);
         continue;
      }

      $latencySum += microtime(true) - $dialed;
      $latencyCount++;
      stream_set_blocking($Socket, false);
      $Sockets[(int) $Socket] = $Socket;
   }

   if ($Sockets === []) {
      return [
         'events' => 0, 'bytes' => 0, 'elapsed' => 0.0,
         'latency_sum' => 0.0, 'latency_count' => 0,
      ];
   }

   $events = 0;
   $bytes = 0;
   $started = microtime(true);
   $deadline = $started + $duration;

   while (microtime(true) < $deadline) {
      $reads = $Sockets;
      $writes = null;
      $excepts = null;

      if (@stream_select($reads, $writes, $excepts, 0, 200_000) < 1) {
         continue;
      }

      foreach ($reads as $Socket) {
         $chunk = @fread($Socket, 65_536);

         if ($chunk === false || ($chunk === '' && feof($Socket))) {
            unset($Sockets[(int) $Socket]);
            @fclose($Socket);
            continue;
         }

         $bytes += strlen($chunk);
         // ! One `data:` field per event. The payload is a run of a single
         //   character, so it can never contain the marker itself.
         $events += substr_count($chunk, 'data:');
      }

      if ($Sockets === []) {
         break;
      }
   }

   $elapsed = microtime(true) - $started;
   $opened = (float) count($Sockets);

   foreach ($Sockets as $Socket) {
      @fclose($Socket);
   }

   return [
      'events' => $events,
      'bytes' => $bytes,
      'elapsed' => $elapsed,
      'latency_sum' => $latencySum,
      'latency_count' => $latencyCount,
   ];
}

/**
 * Open and immediately release streams, counting establishments per second.
 *
 * @return array{events:int,bytes:int,elapsed:float,latency_sum:float,latency_count:int}
 */
function open (string $host, int $port, int $connections, int $duration, string $path): array
{
   $opens = 0;
   $bytes = 0;
   $latencySum = 0.0;
   $latencyCount = 0;
   $started = microtime(true);
   $deadline = $started + $duration;

   // @@ Serial by design: this measures how fast the server can ESTABLISH an
   //    event stream, which is a per-stream cost. Holding many in flight would
   //    measure the accept backlog instead.
   while (microtime(true) < $deadline) {
      $dialed = microtime(true);
      $Socket = @stream_socket_client("tcp://{$host}:{$port}", $code, $error, 5.0);

      if (is_resource($Socket) === false) {
         continue;
      }

      stream_set_timeout($Socket, 5);
      fwrite($Socket, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\n\r\n");

      $head = '';
      while (str_contains($head, "\r\n\r\n") === false) {
         $line = fgets($Socket, 512);

         if ($line === false) {
            break;
         }

         $head .= $line;
      }

      $bytes += strlen($head);
      fclose($Socket);

      if (str_contains($head, 'text/event-stream') === false) {
         continue;
      }

      $latencySum += microtime(true) - $dialed;
      $latencyCount++;
      $opens++;
   }

   return [
      'events' => $opens,
      'bytes' => $bytes,
      'elapsed' => microtime(true) - $started,
      'latency_sum' => $latencySum,
      'latency_count' => $latencyCount,
   ];
}


// ---------------------------------------------------------------------------
// Result emit / aggregate
// ---------------------------------------------------------------------------

/**
 * @param array{events:int,bytes:int,elapsed:float,latency_sum:float,latency_count:int} $Stats
 */
function report (array $Stats, null|string $statsFile): void
{
   if ($statsFile !== null) {
      RunArtifacts::commit($statsFile, json_encode([
         'responses'     => $Stats['events'],
         'bytes_read'    => $Stats['bytes'],
         'elapsed'       => $Stats['elapsed'],
         'latency_sum'   => $Stats['latency_sum'],
         'latency_count' => $Stats['latency_count'],
      ], JSON_THROW_ON_ERROR));

      return;
   }

   emit(
      $Stats['events'],
      $Stats['bytes'],
      $Stats['elapsed'],
      $Stats['latency_sum'],
      $Stats['latency_count'],
   );
}

function emit (
   int $responses,
   int $bytesRead,
   float $elapsed,
   float $latencySum = 0.0,
   int $latencyCount = 0,
): void
{
   $rps = $elapsed > 0 ? $responses / $elapsed : 0.0;
   $transferPerSec = $elapsed > 0 ? $bytesRead / $elapsed : 0.0;
   $average = $latencyCount > 0 ? ($latencySum / $latencyCount) : null;

   if ($transferPerSec >= 1_073_741_824) {
      $transfer = number_format($transferPerSec / 1_073_741_824, 2) . 'GB';
   }
   elseif ($transferPerSec >= 1_048_576) {
      $transfer = number_format($transferPerSec / 1_048_576, 2) . 'MB';
   }
   elseif ($transferPerSec >= 1024) {
      $transfer = number_format($transferPerSec / 1024, 2) . 'KB';
   }
   else {
      $transfer = number_format($transferPerSec, 0) . 'B';
   }

   $latency = null;
   if ($average !== null) {
      $latency = $average >= 0.001
         ? number_format($average * 1000, 2) . 'ms'
         : number_format($average * 1_000_000, 2) . 'us';
   }

   echo json_encode([
      'rps'      => (string) round($rps, 2),
      'latency'  => $latency,
      'transfer' => "{$transfer}/s",
   ]);
}

/**
 * @return array{events:int,bytes:int,elapsed:float,latency_sum:float,latency_count:int}
 */
function drive (
   string $mode,
   string $host,
   int $port,
   int $connections,
   int $duration,
   string $path,
): array
{
   return match ($mode) {
      'stream' => stream($host, $port, $connections, $duration, $path),
      'open'   => open($host, $port, $connections, $duration, $path),
   };
}


// ---------------------------------------------------------------------------
// Execute: single-worker or multi-worker
// ---------------------------------------------------------------------------
if ($requestedWorkers <= 1) {
   report(drive($mode, $host, $port, $connectionsPerWorker, $duration, $path), null);
   exit(0);
}

$Artifacts = RunArtifacts::create('sse-raw-workers');
$childPIDs = [];
$childFiles = [];
$childStatuses = [];
$forkFailed = false;

for ($worker = 0; $worker < $requestedWorkers; $worker++) {
   $PID = pcntl_fork();

   if ($PID === 0) {
      $statsFile = $Artifacts->resolve('child-' . getmypid() . '.json');
      report(drive($mode, $host, $port, $connectionsPerWorker, $duration, $path), $statsFile);
      exit(0);
   }
   elseif ($PID > 0) {
      $childPIDs[] = $PID;
      $childFiles[$PID] = $Artifacts->resolve("child-{$PID}.json");
   }
   else {
      $forkFailed = true;
      fwrite(STDERR, "ERROR: pcntl_fork() failed.\n");
   }
}

foreach ($childPIDs as $PID) {
   $waited = pcntl_waitpid($PID, $status);
   $childStatuses[$PID] = $waited === $PID
      && pcntl_wifexited($status)
      && pcntl_wexitstatus($status) === 0;
}

$totalEvents = 0;
$totalBytes = 0;
$maxElapsed = 0.0;
$totalLatencySum = 0.0;
$totalLatencyCount = 0;
$valid = $forkFailed === false && count($childPIDs) === $requestedWorkers;

foreach ($childPIDs as $PID) {
   $contents = @file_get_contents($childFiles[$PID]);
   $data = $contents === false ? null : json_decode($contents, true);
   $elapsed = $data['elapsed'] ?? null;
   $latencySum = $data['latency_sum'] ?? null;
   $accepted = ($childStatuses[$PID] ?? false)
      && is_array($data)
      && is_int($data['responses'] ?? null) && $data['responses'] >= 0
      && is_int($data['bytes_read'] ?? null) && $data['bytes_read'] >= 0
      && is_int($data['latency_count'] ?? null) && $data['latency_count'] >= 0
      && (is_int($elapsed) || is_float($elapsed))
      && is_finite((float) $elapsed) && (float) $elapsed > 0
      && (is_int($latencySum) || is_float($latencySum))
      && is_finite((float) $latencySum) && (float) $latencySum >= 0;

   if ($accepted) {
      $totalEvents += $data['responses'];
      $totalBytes += $data['bytes_read'];
      $maxElapsed = max($maxElapsed, (float) $elapsed);
      $totalLatencySum += (float) $latencySum;
      $totalLatencyCount += $data['latency_count'];
   }

   $valid = $valid && $accepted;
}

if ($valid === false) {
   fwrite(STDERR, "ERROR: one or more benchmark workers failed or produced invalid stats.\n");
   $Artifacts->clean();
   exit(1);
}

emit($totalEvents, $totalBytes, $maxElapsed, $totalLatencySum, $totalLatencyCount);
$Artifacts->clean();
exit(0);
