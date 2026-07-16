<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — aligned monotonic measurement barrier
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;


use const PHP_INT_MAX;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use const STREAM_IPPROTO_IP;
use function array_key_exists;
use function array_keys;
use function count;
use function fclose;
use function fgets;
use function fwrite;
use function getmypid;
use function hrtime;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function min;
use function preg_match;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function substr;
use function time_nanosleep;
use RuntimeException;


/**
 * Align forked load workers on one absolute monotonic measurement window.
 *
 * Each child establishes its benchmark connections before announcing READY.
 * The parent releases every exact child PID with the same future origin and
 * deadline. Since the client reactor has not started yet, no load bytes can be
 * written before that release boundary.
 */
final class MeasurementBarrier
{
   public const string SCHEMA = 'bootgly.measurement-barrier.v1';
   public const int START_DELAY_NS = 250_000_000;
   public const int MAXIMUM_START_LAG_NS = 100_000_000;
   public const int TIMEOUT_NS = 60_000_000_000;


   /** @return array{0:resource,1:resource} */
   public static function pair (): array
   {
      $Channels = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Channels === false || count($Channels) !== 2) {
         throw new RuntimeException('Could not create a benchmark measurement barrier channel.');
      }

      return [$Channels[0], $Channels[1]];
   }

   /**
    * Announce child readiness, receive the common window, and wait for origin.
    *
    * @param resource $Channel
    * @return array{origin_ns:int,deadline_ns:int,activated_ns:int,start_lag_ns:int}
    */
   public static function signal ($Channel, int $connections): array
   {
      if (!is_resource($Channel) || $connections < 0) {
         throw new RuntimeException('Invalid child measurement barrier state.');
      }

      self::write($Channel, json_encode([
         'schema' => self::SCHEMA,
         'event' => 'ready',
         'pid' => getmypid(),
         'connections' => $connections,
      ], JSON_THROW_ON_ERROR) . "\n");

      $message = self::read($Channel, (int) hrtime(true) + self::TIMEOUT_NS);
      $data = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
      if (
         !is_array($data)
         || array_keys($data) !== ['schema', 'event', 'origin_ns', 'deadline_ns']
         || ($data['schema'] ?? null) !== self::SCHEMA
         || ($data['event'] ?? null) !== 'start'
      ) {
         throw new RuntimeException('Invalid parent measurement barrier release.');
      }

      $originNS = self::parse($data['origin_ns'] ?? null);
      $deadlineNS = self::parse($data['deadline_ns'] ?? null);
      if ($deadlineNS <= $originNS) {
         throw new RuntimeException('Invalid monotonic measurement window.');
      }

      while (($remainingNS = $originNS - (int) hrtime(true)) > 0) {
         $sleepNS = (int) min($remainingNS, 5_000_000);
         time_nanosleep(0, $sleepNS);
      }

      $activatedNS = (int) hrtime(true);
      $startLagNS = $activatedNS - $originNS;
      if ($startLagNS > self::MAXIMUM_START_LAG_NS) {
         throw new RuntimeException('A load worker missed the common measurement origin.');
      }

      return [
         'origin_ns' => $originNS,
         'deadline_ns' => $deadlineNS,
         'activated_ns' => $activatedNS,
         'start_lag_ns' => $startLagNS,
      ];
   }

   /**
    * Wait for every exact child and release one shared future window.
    *
    * @param array<int,resource> $Channels Child PID => parent channel.
    * @return array{origin_ns:int,deadline_ns:int,ready:array<int,array{connections:int}>}
    */
   public static function release (array $Channels, int $duration): array
   {
      if ($Channels === [] || $duration < 1 || $duration > 3_600) {
         throw new RuntimeException('Invalid parent measurement barrier state.');
      }
      if ($duration > intdiv(PHP_INT_MAX - self::START_DELAY_NS, 1_000_000_000)) {
         throw new RuntimeException('Measurement duration overflows the monotonic window.');
      }

      $deadline = (int) hrtime(true) + self::TIMEOUT_NS;
      $pending = $Channels;
      $ready = [];
      foreach ($pending as $Channel) {
         if (!is_resource($Channel)) {
            throw new RuntimeException('Invalid parent measurement barrier channel.');
         }
         stream_set_blocking($Channel, false);
      }

      while ($pending !== []) {
         $Readable = array_values($pending);
         $Writable = null;
         $Except = null;
         $remainingNS = $deadline - (int) hrtime(true);
         if ($remainingNS <= 0) {
            throw new RuntimeException('Timed out waiting for load-worker readiness.');
         }

         $seconds = intdiv($remainingNS, 1_000_000_000);
         $microseconds = intdiv($remainingNS % 1_000_000_000, 1_000);
         $selected = @stream_select($Readable, $Writable, $Except, $seconds, $microseconds);
         if ($selected === false || $selected === 0) {
            continue;
         }

         foreach ($Readable as $Channel) {
            $PID = array_search($Channel, $pending, true);
            if (!is_int($PID)) {
               throw new RuntimeException('Unknown load-worker barrier channel became readable.');
            }

            $line = self::read($Channel, $deadline);
            $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (
               !is_array($data)
               || array_keys($data) !== ['schema', 'event', 'pid', 'connections']
               || ($data['schema'] ?? null) !== self::SCHEMA
               || ($data['event'] ?? null) !== 'ready'
               || ($data['pid'] ?? null) !== $PID
               || !is_int($data['connections'] ?? null)
               || $data['connections'] < 0
            ) {
               throw new RuntimeException("Invalid readiness proof from load worker {$PID}.");
            }

            $ready[$PID] = ['connections' => $data['connections']];
            unset($pending[$PID]);
         }
      }

      $originNS = (int) hrtime(true) + self::START_DELAY_NS;
      $deadlineNS = $originNS + ($duration * 1_000_000_000);
      $release = json_encode([
         'schema' => self::SCHEMA,
         'event' => 'start',
         'origin_ns' => (string) $originNS,
         'deadline_ns' => (string) $deadlineNS,
      ], JSON_THROW_ON_ERROR) . "\n";

      foreach ($Channels as $Channel) {
         self::write($Channel, $release);
      }

      return [
         'origin_ns' => $originNS,
         'deadline_ns' => $deadlineNS,
         'ready' => $ready,
      ];
   }

   /** Read one complete line under an absolute monotonic timeout. */
   private static function read (mixed $Channel, int $deadlineNS): string
   {
      if (!is_resource($Channel)) {
         throw new RuntimeException('Invalid measurement barrier read channel.');
      }

      stream_set_blocking($Channel, false);
      $buffer = '';
      while (!str_contains($buffer, "\n")) {
         $Readable = [$Channel];
         $Writable = null;
         $Except = null;
         $remainingNS = $deadlineNS - (int) hrtime(true);
         if ($remainingNS <= 0) {
            throw new RuntimeException('Timed out waiting for a measurement barrier message.');
         }
         $seconds = intdiv($remainingNS, 1_000_000_000);
         $microseconds = intdiv($remainingNS % 1_000_000_000, 1_000);
         $selected = @stream_select($Readable, $Writable, $Except, $seconds, $microseconds);
         if ($selected === false || $selected === 0) {
            continue;
         }

         $chunk = fgets($Channel);
         if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Measurement barrier channel closed before release.');
         }
         $buffer .= $chunk;
         if (strlen($buffer) > 4_096) {
            throw new RuntimeException('Measurement barrier message exceeded its bound.');
         }
      }

      return substr($buffer, 0, strpos($buffer, "\n") + 1);
   }

   /** Write every byte of one bounded control message. */
   private static function write (mixed $Channel, string $message): void
   {
      if (!is_resource($Channel)) {
         throw new RuntimeException('Invalid measurement barrier write channel.');
      }

      $length = strlen($message);
      $offset = 0;
      while ($offset < $length) {
         $written = fwrite($Channel, substr($message, $offset));
         if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write a measurement barrier message.');
         }
         $offset += $written;
      }
   }

   /** Parse one positive JSON-safe decimal nanosecond value. */
   private static function parse (mixed $value): int
   {
      if (!is_string($value) || preg_match('/\A[1-9]\d*\z/D', $value) !== 1) {
         throw new RuntimeException('Invalid monotonic nanosecond value in barrier message.');
      }
      $maximum = (string) PHP_INT_MAX;
      if (
         strlen($value) > strlen($maximum)
         || (strlen($value) === strlen($maximum) && $value > $maximum)
      ) {
         throw new RuntimeException('Monotonic nanosecond value exceeds PHP integer range.');
      }

      return (int) $value;
   }
}
