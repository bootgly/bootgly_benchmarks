<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI SSE supervisor cost probe
 * --------------------------------------------------------------------------
 * Holds N open event streams against ONE worker and samples an unrelated
 * route's latency while they are held, to answer a question no benchmark load
 * reaches: what does a sustained stream cost the worker that is not serving
 * it?
 *
 * SSE parks nothing in the reactor — an open stream is driven by a `Timer`
 * supervisor. `Timer::tick()` buckets tasks by the absolute second they come
 * due and reschedules each one at `time() + interval`, so streams opened in
 * the same second are rescheduled into the same bucket forever: their
 * supervisors fire as ONE burst inside a single SIGALRM handler instead of
 * spreading across the cadence. That was the suspicion this probe was built
 * to test.
 *
 * It does not hold up. Every count is measured TWICE against the same worker
 * shape — once holding event streams, once holding plain keep-alive
 * connections. Both arms pay the reactor's per-descriptor scan; only the
 * first installs a supervisor, so the difference between them is the
 * supervisor and nothing else. Measured at one worker, 5s cadence:
 *
 *        held    sse p50   idle p50     delta    spread
 *           0     0.30ms     0.30ms    0.00ms    0.02ms
 *         200     0.40ms     0.39ms    0.00ms    0.04ms
 *         800     0.67ms     0.65ms    0.02ms    0.03ms
 *
 * The cost is real and linear — about 0.46µs of added median per held
 * connection — but it belongs to `stream_select`, not to the timers: the
 * supervisor delta stays under its own row's round-to-round spread while the
 * total sits an order of magnitude above it. A sustained-stream deployment
 * scales like connections, not like timers.
 *
 * Two rules this probe exists to enforce, both learned by violating them:
 *
 *   - Rounds, not runs. At one round the 0-held row — where both arms are
 *     identical BY CONSTRUCTION — separated by 0.19ms, which is larger than
 *     the entire effect being chased. At three rounds it separates by 0.02ms.
 *   - Judge a loaded delta against the LOADED row's spread. Comparing it to
 *     the empty row's floor once turned a 0.05ms noise wobble into a reported
 *     "16% supervisor share" that the next run did not reproduce.
 *
 * Usage:
 *   php sse_supervisor.php                 # sweep the default stream counts
 *   SSE_PROBE_STREAMS=0,800 php sse_supervisor.php
 *   SSE_PROBE_TRACE=1 ...                  # per-phase timings when it stalls
 * --------------------------------------------------------------------------
 */

namespace BootglyBenchmarks\HTTP_Server_CLI\Probes;


use const PHP_EOL;
use const STDOUT;
use function abs;
use function array_map;
use function count;
use function define;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function fclose;
use function fgets;
use function file_exists;
use function fwrite;
use function getenv;
use function is_resource;
use function max;
use function microtime;
use function fread;
use function number_format;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;
use function strlen;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function trim;
use function usleep;


define(__NAMESPACE__ . '\\START', microtime(true));

$BenchmarksRoot = dirname(__DIR__, 2);
$BootglyDir = getenv('BOOTGLY_DIR') ?: dirname($BenchmarksRoot) . '/bootgly';

if (file_exists($BootglyDir . '/bootgly') === false) {
   line("FAIL: bootgly checkout not found at {$BootglyDir}. Set BOOTGLY_DIR.");
   exit(1);
}

exit(sweep($BootglyDir) ? 0 : 1);


/**
 * Run the probe across every requested stream count.
 */
function sweep (string $BootglyDir): bool
{
   $counts = array_map(
      static fn (string $value): int => (int) trim($value),
      explode(',', env('SSE_PROBE_STREAMS', '0,100,250,500')),
   );
   $seconds = (int) env('SSE_PROBE_SECONDS', '25');
   $rounds = max(1, (int) env('SSE_PROBE_ROUNDS', '3'));
   $heartbeat = (int) env('SSE_PROBE_HEARTBEAT', '5');
   $port = (int) env('PORT', '8082');

   line('Bootgly HTTP_Server_CLI SSE supervisor cost probe');
   line('--------------------------------------------------');
   line(
      "Worker: 1  ·  sample window: {$seconds}s  ·  rounds: {$rounds}"
      . "  ·  SSE heartbeat: {$heartbeat}s"
   );
   line('');
   line(sprintf(
      '  %8s  %10s  %10s  %10s  %10s',
      'held', 'sse p50', 'idle p50', 'delta', 'spread',
   ));

   $rows = [];
   $failed = false;

   foreach ($counts as $streams) {
      // ! Each count is measured twice against the SAME worker shape: once
      //   holding event streams, once holding plain keep-alive connections.
      //   Both pay the reactor's per-descriptor scan; only the first installs
      //   a supervisor. Without the idle arm every number here would be
      //   attributed to SSE when most of it belongs to `stream_select`.
      //
      //   Interleaved across rounds rather than run back to back: one round
      //   of each arm is a single sample of a noisy machine, and the 0-held
      //   row — where both arms are identical BY CONSTRUCTION — exists to
      //   print how large that noise is. A delta smaller than the spread on
      //   that row is not a measurement.
      $sse = [];
      $idle = [];

      for ($round = 0; $round < $rounds; $round++) {
         $SSE = measure($BootglyDir, $port, $streams, $seconds, $heartbeat, 'sse');
         $Idle = measure($BootglyDir, $port, $streams, $seconds, $heartbeat, 'idle');

         if ($SSE === null || $Idle === null) {
            line(sprintf('  %8d  %s', $streams, 'FAILED'));
            $failed = true;
            continue 2;
         }

         $sse[] = $SSE['p50'];
         $idle[] = $Idle['p50'];
      }

      sort($sse);
      sort($idle);
      $sseMedian = $sse[(int) ((int) count($sse) / 2)];
      $idleMedian = $idle[(int) ((int) count($idle) / 2)];
      $spread = max($sse[count($sse) - 1] - $sse[0], $idle[count($idle) - 1] - $idle[0]);

      $rows[$streams] = [
         'sse' => $sseMedian,
         'idle' => $idleMedian,
         'spread' => $spread,
      ];
      line(sprintf(
         '  %8d  %10s  %10s  %10s  %10s',
         $streams,
         ms($sseMedian), ms($idleMedian),
         ms($sseMedian - $idleMedian),
         ms($spread),
      ));
   }

   line('');

   // ? The zero row is the whole experiment: without it there is nothing to
   //   attribute a cost to.
   $Base = $rows[0] ?? null;
   if ($Base === null || count($rows) < 2) {
      line('INCONCLUSIVE: need the 0-stream control plus at least one loaded row.');

      return false;
   }

   $top = 0;
   foreach ($rows as $streams => $Row) {
      $top = max($top, $streams);
   }

   $Top = $rows[$top];
   $descriptor = $Top['idle'] - $Base['idle'];
   $supervisor = $Top['sse'] - $Top['idle'];
   $total = $Top['sse'] - $Base['sse'];

   // ! Two different noise floors, because they answer two questions.
   //
   //   The 0-held row calibrates the harness: both arms run identical code
   //   there, so anything separating them is drift.
   //
   //   The delta at the TOP row must clear that row's OWN spread instead —
   //   a loaded worker is measurably noisier than an empty one, and judging
   //   a loaded delta against the empty row's floor manufactures findings.
   //   At 800 held connections this is the difference between reporting a
   //   16% supervisor share and reporting that it does not resolve.
   $floor = max($Base['spread'], abs($Base['sse'] - $Base['idle']));
   $loaded = max($floor, $Top['spread']);

   line(sprintf('Noise floor (0-held row, identical arms): %s', ms($floor)));
   line(sprintf('Noise floor at %d held (that row\'s spread):  %s', $top, ms($loaded)));
   line('');
   line(sprintf('At %d held connections, versus the empty worker:', $top));
   line(sprintf(
      '  reactor scan (idle arm)   %s  — paid by ANY held connection',
      ms($descriptor),
   ));
   line(sprintf(
      '  SSE supervisor (delta)    %s  — paid only by event streams',
      ms($supervisor),
   ));
   line(sprintf('  total median cost         %s', ms($total)));
   line('');

   if ($total <= $loaded) {
      line(
         'INCONCLUSIVE: the whole effect at ' . $top . ' held connections is'
         . ' under the noise floor. Raise SSE_PROBE_STREAMS or'
         . ' SSE_PROBE_SECONDS before reading anything into these columns.'
      );

      return false;
   }

   if (abs($supervisor) <= $loaded) {
      line(
         'FINDING: the supervisor does NOT resolve here — its delta sits under'
         . ' this row\'s own spread while the total is well above it. What a'
         . ' held stream costs an unrelated route is the descriptor scan, the'
         . ' same price any idle keep-alive connection charges: SSE scales'
         . ' like connections, not like timers, at these counts and cadence.'
      );

      return $failed === false;
   }

   $share = $total > 0.0 ? $supervisor / $total : 0.0;
   line(sprintf(
      'FINDING: the supervisor accounts for %.0f%% of the added median, above'
      . ' the noise floor. The per-stream timer is a real cost of a'
      . ' sustained-stream deployment.',
      $share * 100,
   ));

   return $failed === false;
}

/**
 * Hold `$streams` open event streams and sample `/fast` latency throughout.
 *
 * @return null|array{p50:float,p95:float,p99:float,max:float,samples:int}
 */
function measure (
   string $BootglyDir,
   int $port,
   int $streams,
   int $seconds,
   int $heartbeat,
   string $mode,
): null|array
{
   trace("measure({$mode},{$streams}) enter");
   stop($BootglyDir);
   trace('  stop(entry) done');
   start($BootglyDir, $heartbeat);
   trace('  start done');

   $Streams = [];
   $samples = [];

   try {
      if (ready($port) === false) {
         trace('  ready FAILED');

         return null;
      }
      trace('  ready done');

      $held = microtime(true);

      $retries = 0;

      for ($opened = 0; $opened < $streams; $opened++) {
         $Stream = $mode === 'sse' ? open($port, '/sse/stream') : idle($port);

         // ? A freshly forked worker intermittently accepts a connection and
         //   answers nothing. Retry bounded and COUNT it — silently retrying
         //   would hide a real capacity ceiling behind the same code path.
         for ($retry = 0; $Stream === null && $retry < 5; $retry++) {
            $retries++;
            usleep(20_000);
            $Stream = $mode === 'sse' ? open($port, '/sse/stream') : idle($port);
         }

         if ($Stream === null) {
            line("  (could not hold {$mode} connection {$opened} of {$streams})");

            return null;
         }

         $Streams[] = $Stream;
      }

      line(sprintf(
         '    [%s] held %d in %.1fs (%d retries), sampling %ds...',
         $mode, $streams, microtime(true) - $held, $retries, $seconds,
      ));

      // ! Let every supervisor install and settle into its bucket before the
      //   window opens, so the first firing lands inside the samples.
      usleep(500_000);

      $deadline = microtime(true) + $seconds;
      $attempts = 0;

      while (microtime(true) < $deadline) {
         $started = microtime(true);
         $code = probe($port, '/reactor/peak');
         $elapsed = microtime(true) - $started;
         $attempts++;

         if ($code === 200) {
            $samples[] = $elapsed;
         }

         usleep(20_000);
      }

      trace(sprintf(
         '  sampling done (%d attempts, %d accepted)',
         $attempts, count($samples),
      ));
   }
   finally {
      trace('  closing ' . count($Streams) . ' held sockets');

      foreach ($Streams as $Stream) {
         if (is_resource($Stream)) {
            fclose($Stream);
         }
      }

      trace('  closed; stop(exit)...');
      stop($BootglyDir);
      trace('  stop(exit) done');
   }

   if (count($samples) < 50) {
      return null;
   }

   sort($samples);

   return [
      'p50' => percentile($samples, 0.50),
      'p95' => percentile($samples, 0.95),
      'p99' => percentile($samples, 0.99),
      'max' => $samples[count($samples) - 1],
      'samples' => count($samples),
   ];
}

/**
 * Open one event stream and leave it held.
 *
 * @return resource|null
 */
function open (int $port, string $path): mixed
{
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $error, 5.0);

   if (is_resource($Socket) === false) {
      return null;
   }

   // ! A microtime() deadline around fgets() is decorative — fgets() blocks
   //   on a blocking stream and never returns to be checked. The socket
   //   timeout is the only thing that actually bounds these reads, and
   //   without it a worker that stops answering hangs the probe instead of
   //   reporting the count at which it stopped.
   stream_set_timeout($Socket, 5);
   fwrite($Socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

   // ? Read the head before counting the stream as held: a refused stream
   //   answers with an ordinary finite response, and holding its socket
   //   would report a supervisor that was never installed.
   $head = '';
   $deadline = microtime(true) + 5.0;

   while (microtime(true) < $deadline && str_contains($head, "\r\n\r\n") === false) {
      $line = fgets($Socket, 512);

      if ($line === false) {
         usleep(1_000);
         continue;
      }

      $head .= $line;
   }

   if (str_contains($head, 'text/event-stream') === false) {
      fclose($Socket);

      return null;
   }

   stream_set_blocking($Socket, false);

   return $Socket;
}

/**
 * Hold one ordinary keep-alive connection — the control arm.
 *
 * It costs the reactor exactly what an event stream costs it (one more
 * descriptor in every scan) and nothing else: no resource, no supervisor, no
 * timer task. The request is completed and drained so the connection sits
 * idle and established rather than mid-exchange.
 *
 * @return resource|null
 */
function idle (int $port): mixed
{
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $error, 5.0);

   if (is_resource($Socket) === false) {
      return null;
   }

   stream_set_timeout($Socket, 5);
   fwrite(
      $Socket,
      "GET /reactor/peak HTTP/1.1\r\nHost: 127.0.0.1\r\n"
      . "Connection: keep-alive\r\n\r\n",
   );

   // @ Headers only — every header line ends in a newline, so fgets() returns
   //   each one immediately and the loop ends at the blank-line terminator.
   $head = '';

   while (str_contains($head, "\r\n\r\n") === false) {
      $line = fgets($Socket, 512);

      if ($line === false) {
         break;
      }

      $head .= $line;
   }

   if (str_contains($head, 'HTTP/1.1 200') === false) {
      fclose($Socket);

      return null;
   }

   // @ Body by LENGTH, never by fgets(). The payload carries no trailing
   //   newline and a kept-alive connection never reaches EOF, so a line read
   //   here blocks for the whole socket timeout before handing back bytes it
   //   already holds — 5s per connection, which turns holding a few hundred
   //   of them into an apparent hang rather than a measurement.
   if (preg_match('/\r\nContent-Length:\s*(\d+)/i', $head, $matches) === 1) {
      $remaining = (int) $matches[1];

      while ($remaining > 0) {
         $chunk = fread($Socket, $remaining);

         if ($chunk === false || $chunk === '') {
            break;
         }

         $remaining -= strlen($chunk);
      }
   }

   stream_set_blocking($Socket, false);

   return $Socket;
}

/**
 * One blocking request; returns the status code (0 on failure).
 */
function probe (int $port, string $path): int
{
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $error, 5.0);

   if (is_resource($Socket) === false) {
      return 0;
   }

   stream_set_timeout($Socket, 5);
   fwrite(
      $Socket,
      "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
   );

   $raw = '';
   $deadline = microtime(true) + 10.0;

   while (microtime(true) < $deadline) {
      $line = fgets($Socket, 2048);

      if ($line === false) {
         break;
      }

      $raw .= $line;
   }

   fclose($Socket);

   return str_contains($raw, 'HTTP/1.1 200') ? 200 : 0;
}

/**
 * Wait until the worker answers.
 */
function ready (int $port): bool
{
   for ($attempt = 0; $attempt < 200; $attempt++) {
      if (probe($port, '/reactor/peak') === 200) {
         return true;
      }

      usleep(50_000);
   }

   return false;
}

/**
 * Start one benchmark worker with the SSE route available.
 */
function start (string $BootglyDir, int $heartbeat): void
{
   $env = 'BENCHMARK_LOAD_SET=benchmark BOOTGLY_WORKERS=1 '
      . "BENCHMARK_SSE_HEARTBEAT={$heartbeat} ";

   exec(
      $env . 'php ' . escapeshellarg("{$BootglyDir}/bootgly")
      . ' project Benchmark/HTTP_Server_CLI start > /dev/null 2>&1'
   );
   usleep(500_000);
}

/**
 * Stop the benchmark worker.
 */
function stop (string $BootglyDir): void
{
   exec(
      'php ' . escapeshellarg("{$BootglyDir}/bootgly")
      . ' project Benchmark/HTTP_Server_CLI stop > /dev/null 2>&1'
   );
   usleep(300_000);
}

/**
 * Nearest-rank percentile of an ascending sample list.
 *
 * @param array<int,float> $sorted
 */
function percentile (array $sorted, float $quantile): float
{
   $count = count($sorted);
   $rank = (int) max(0, (int) ($quantile * $count) - 1);

   return $sorted[$rank] ?? $sorted[$count - 1];
}

/**
 * Render seconds as milliseconds.
 */
function ms (float $seconds): string
{
   return number_format($seconds * 1000, 2) . 'ms';
}

/**
 * Read one environment variable with a default.
 */
function env (string $name, string $default): string
{
   $value = getenv($name);

   return $value === false || $value === '' ? $default : $value;
}

/**
 * Print one phase trace line when SSE_PROBE_TRACE=1.
 *
 * The loaded arms of this probe drive a server, hundreds of sockets and two
 * child processes; when one of those stalls, the run simply stops printing.
 * Phase traces are what turn "it hung" into "it hung in stop(exit)".
 */
function trace (string $text): void
{
   if (env('SSE_PROBE_TRACE', '0') !== '1') {
      return;
   }

   line(sprintf('    %8.2fs  %s', microtime(true) - START, $text));
}

/**
 * Print one probe line.
 */
function line (string $text): void
{
   fwrite(STDOUT, $text . PHP_EOL);
}
