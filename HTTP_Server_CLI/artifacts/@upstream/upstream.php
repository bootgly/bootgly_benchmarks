<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — delayed upstream
 * --------------------------------------------------------------------------
 * Accepts on a Unix socket, holds each connection for a fixed delay, writes a
 * fixed-width payload and closes. Backs the `deferred_fanout` load, whose
 * point is that every in-flight request holds its OWN descriptor parked in the
 * server's reactor — the population that reactor-wide work is linear in.
 *
 * Deliberately dependency-free, on plain `stream_select`:
 *
 *   - The instrument must not be the thing being measured. An upstream built
 *     on Bootgly's reactor would move with every reactor change under test.
 *   - The delay cannot live in the measured worker. `Timer` is SIGALRM-driven
 *     at one-second granularity, and `Select::defer()` keeps monotonic timers
 *     in a list walked twice per iteration — N pending delays would add an
 *     O(N) term per tick, on top of the O(N) term the case exists to measure.
 *   - Unix sockets, not TCP: at benchmark rates a per-request TCP connect
 *     exhausts ephemeral ports into TIME_WAIT within seconds.
 *
 * Usage:
 *   php upstream.php --socket=/tmp/bootgly-upstream.sock --delay=5
 *   php upstream.php --socket=/tmp/bootgly-upstream.sock stop
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

// ! Options
$options = [
   'socket' => getenv('BENCHMARK_UPSTREAM_SOCKET') ?: '/tmp/bootgly-upstream.sock',
   'delay' => getenv('BENCHMARK_UPSTREAM_DELAY') ?: '5',
   'backlog' => getenv('BENCHMARK_UPSTREAM_BACKLOG') ?: '4096',
];
$action = 'start';

foreach (array_slice($argv, 1) as $argument) {
   if (preg_match('/\A--([a-z]+)=(.*)\z/D', $argument, $matches) === 1) {
      if (array_key_exists($matches[1], $options) === false) {
         fwrite(STDERR, "upstream: unknown option --{$matches[1]}.\n");
         exit(1);
      }
      $options[$matches[1]] = $matches[2];

      continue;
   }

   $action = $argument;
}

$socket = (string) $options['socket'];
$delayMS = (int) $options['delay'];
$backlog = (int) $options['backlog'];

if ($socket === '' || str_contains($socket, "\0")) {
   fwrite(STDERR, "upstream: --socket must be a filesystem path.\n");
   exit(1);
}

$pidFile = "{$socket}.pid";

// ? Stop is a separate, idempotent entry point so the opponent teardown never
//   leaves a listener owning the path a later run needs to bind.
if ($action === 'stop') {
   $PID = is_file($pidFile) ? (int) file_get_contents($pidFile) : 0;

   if ($PID > 0 && function_exists('posix_kill')) {
      posix_kill($PID, SIGTERM);
   }

   @unlink($pidFile);
   @unlink($socket);

   exit(0);
}

if ($action !== 'start') {
   fwrite(STDERR, "upstream: unknown action '{$action}'.\n");
   exit(1);
}
if ($delayMS < 0 || $delayMS > 60_000) {
   fwrite(STDERR, "upstream: --delay must be between 0 and 60000 ms.\n");
   exit(1);
}

// @ Reclaim the path from a dead predecessor. A stale socket file is not a
//   live listener, and bind() cannot tell the difference.
if (file_exists($socket) && @stream_socket_client("unix://{$socket}", $code, $error, 0.2) === false) {
   @unlink($socket);
}

// ! An explicit listen backlog, because PHP's default of 128 is far below the
//   regime this upstream exists to create. A refused connect surfaces as a 503
//   from the server under test, so a short backlog does not degrade the load —
//   it invalidates it. The kernel still clamps to net.core.somaxconn.
$Context = stream_context_create(['socket' => ['backlog' => $backlog]]);

$Listener = @stream_socket_server(
   "unix://{$socket}",
   $code,
   $error,
   STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
   $Context
);

if ($Listener === false) {
   fwrite(STDERR, "upstream: cannot listen on {$socket} — {$error}\n");
   exit(1);
}

stream_set_blocking($Listener, false);
file_put_contents($pidFile, (string) getmypid());

// ! A fixed width the client can read to completion without framing.
$payload = str_pad('upstream', 8, ' ', STR_PAD_RIGHT);
$delayNS = $delayMS * 1_000_000;

$Pending = [];   // id => connection
$due = [];       // id => monotonic deadline in ns
$running = true;

if (function_exists('pcntl_signal')) {
   pcntl_async_signals(true);
   $Stop = static function () use (&$running): void {
      $running = false;
   };
   pcntl_signal(SIGTERM, $Stop);
   pcntl_signal(SIGINT, $Stop);
}

// @@ Serve
while ($running) {
   $now = hrtime(true);

   // @ Flush every connection whose hold has elapsed.
   $wait = null;
   foreach ($due as $id => $deadline) {
      if ($deadline > $now) {
         $remaining = ($deadline - $now) / 1_000_000_000;
         $wait = $wait === null ? $remaining : min($wait, $remaining);

         continue;
      }

      $Connection = $Pending[$id];
      unset($Pending[$id], $due[$id]);

      if (is_resource($Connection)) {
         @fwrite($Connection, $payload);
         @fclose($Connection);
      }
   }

   $Reads = [$Listener];
   $Writes = [];
   $Excepts = [];

   // ! No pending hold means an indefinite block until the next connection —
   //   never a zero timeout, which would spin a core the measured server needs.
   $seconds = $wait === null ? null : (int) $wait;
   $microseconds = $wait === null ? 0 : (int) (($wait - $seconds) * 1_000_000);

   $ready = @stream_select($Reads, $Writes, $Excepts, $seconds, $microseconds);

   if ($ready === false) {
      // # Interrupted by a signal — re-evaluate the loop condition.
      continue;
   }
   if ($ready === 0) {
      continue;
   }

   // @ Accept everything queued in this wakeup, not one per iteration: at
   //   benchmark rates the backlog refills faster than the loop can drain it.
   for ($accepted = 0; $accepted < $backlog; $accepted++) {
      $Connection = @stream_socket_accept($Listener, 0);

      if ($Connection === false) {
         break;
      }

      stream_set_blocking($Connection, false);

      $id = (int) $Connection;
      $Pending[$id] = $Connection;
      $due[$id] = hrtime(true) + $delayNS;
   }
}

// @ Teardown
foreach ($Pending as $Connection) {
   if (is_resource($Connection)) {
      @fclose($Connection);
   }
}
fclose($Listener);
@unlink($pidFile);
@unlink($socket);

exit(0);
