<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — Benchmark profiler artifacts
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Bootgly\Benchmarks\Runners;


use function bin2hex;
use function dirname;
use function fclose;
use function fflush;
use function fopen;
use function function_exists;
use function fsync;
use function fwrite;
use function getenv;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rename;
use function rtrim;
use function str_starts_with;
use function strtolower;
use function strlen;
use function substr;
use function trim;
use function unlink;


/**
 * Resolve and atomically publish benchmark profiler artifacts.
 */
final class Profiles
{
   /**
    * Resolve one profiler scope inside the active benchmark run.
    *
    * The legacy directory is used only when execution is not attached to a
    * benchmark run workspace.
    */
   public static function resolve (string $scope, string $legacy): string
   {
      $runDirectory = getenv('BENCHMARK_RUN_DIR');
      if ($runDirectory === false || $runDirectory === '') {
         return $legacy;
      }

      if ($scope !== 'server' && $scope !== 'client') {
         throw new \InvalidArgumentException("Invalid profiler scope: $scope");
      }

      $directory = rtrim($runDirectory, '/\\') . "/profiles/$scope";

      $round = getenv('BENCHMARK_ROUND');
      if ($round !== false && $round !== '') {
         $round = self::normalize($round);
         $directory .= str_starts_with($round, 'round-')
            ? "/$round"
            : "/round-$round";
      }

      $profileScope = getenv('BENCHMARK_PROFILE_SCOPE');
      if ($profileScope !== false && $profileScope !== '') {
         $profileScope = self::normalize($profileScope);
         $directory .= str_starts_with($profileScope, 'scope-')
            ? "/$profileScope"
            : "/scope-$profileScope";
      }

      // # One opponent may execute several client loads in the same round.
      //   PID-only filenames can then collide after process-ID reuse, so keep
      //   profiles below the exact runner invocation that spawned the worker.
      $invocationDirectory = getenv('BENCHMARK_INVOCATION_DIR');
      if ($invocationDirectory !== false && $invocationDirectory !== '') {
         $invocation = self::normalize(basename(rtrim($invocationDirectory, '/\\')));
         $directory .= str_starts_with($invocation, 'invocation-')
            ? "/$invocation"
            : "/invocation-$invocation";
      }

      return $directory;
   }

   /**
    * Publish one complete profile through a same-directory atomic rename.
    */
   public static function publish (string $file, string $contents): bool
   {
      $directory = dirname($file);
      if (
         is_dir($directory) === false
         && @mkdir($directory, 0777, true) === false
         && is_dir($directory) === false
      ) {
         return false;
      }

      $Handle = false;
      $temporary = '';
      for ($attempt = 0; $attempt < 8; $attempt++) {
         $temporary = $file . '.' . bin2hex(random_bytes(16)) . '.tmp';
         $Handle = @fopen($temporary, 'x+b');
         if ($Handle !== false) {
            break;
         }
      }
      if ($Handle === false) {
         return false;
      }

      $complete = false;
      try {
         $length = strlen($contents);
         $offset = 0;
         while ($offset < $length) {
            $written = fwrite($Handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }
         $complete = $offset === $length
            && fflush($Handle)
            && (function_exists('fsync') === false || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($complete === false) {
            @unlink($temporary);
         }
      }

      if ($complete === false || @rename($temporary, $file) === false) {
         @unlink($temporary);
         return false;
      }

      return true;
   }

   /**
    * Normalize one environment-provided value into a safe path segment.
    */
   private static function normalize (string $segment): string
   {
      $segment = strtolower(trim($segment));
      if ($segment === '') {
         throw new \InvalidArgumentException('Invalid empty benchmark profile segment.');
      }

      return \preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $segment) === 1
         ? $segment
         : 'encoded-' . bin2hex($segment);
   }
}
