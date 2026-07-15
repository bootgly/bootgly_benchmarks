<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Opponent server output capture
 * --------------------------------------------------------------------------
 * Opponent wrappers may launch a second server process. The runner's outer
 * stdout/stderr capture cannot see that process when a wrapper redirects it
 * internally, so harness runs persist the inner streams here.
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;

use RuntimeException;

require_once __DIR__ . '/RunArtifacts.php';


final class ServerCapture
{
   public static function run (string $command): int
   {
      $directory = \getenv('BENCHMARK_SERVER_DIR');

      // @ Preserve standalone opponent behavior outside the benchmark harness.
      if (!\is_string($directory) || $directory === '') {
         $output = [];
         $exit = 0;
         \exec($command . ' > /dev/null 2>&1', $output, $exit);

         return $exit;
      }

      if (!\is_dir($directory)) {
         throw new RuntimeException("Benchmark server artifact directory does not exist: {$directory}");
      }

      $directory = \rtrim($directory, \DIRECTORY_SEPARATOR);
      $capture = "{$directory}/daemon.capture";
      $daemon = "{$directory}/daemon";

      if (!@\mkdir($capture, 0o755)) {
         throw new RuntimeException("Could not create benchmark opponent capture: {$capture}");
      }

      $descriptors = [
         0 => ['file', '/dev/null', 'r'],
         1 => ['file', "{$capture}/stdout.log", 'xb'],
         2 => ['file', "{$capture}/stderr.log", 'xb'],
      ];
      $process = @\proc_open($command, $descriptors, $pipes);

      if (!\is_resource($process)) {
         self::record($directory, 'start-failed', 127, $capture);
         throw new RuntimeException('Could not start the benchmark opponent server process.');
      }

      $exit = \proc_close($process);

      try {
         self::commit($capture, $daemon);
      }
      catch (\Throwable $Throwable) {
         // ! Publication failure is precisely when the captured diagnostics
         //   matter. Retain the unique staging directory and point an atomic
         //   failure record at it instead of deleting the only evidence.
         self::record($directory, 'publication-failed', $exit, $capture);
         throw $Throwable;
      }

      return $exit;
   }

   private static function commit (string $capture, string $daemon): void
   {
      if (\file_exists($daemon) || \is_link($daemon)) {
         throw new RuntimeException("Benchmark opponent capture already exists: {$daemon}");
      }
      if (!@\rename($capture, $daemon)) {
         throw new RuntimeException("Could not commit benchmark opponent capture: {$daemon}");
      }
   }

   private static function record (
      string $directory,
      string $state,
      int $exit,
      string $capture,
   ): void
   {
      try {
         $JSON = \json_encode([
            'state' => $state,
            'exit' => $exit,
            'stdout' => "{$capture}/stdout.log",
            'stderr' => "{$capture}/stderr.log",
         ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
         RunArtifacts::commit("{$directory}/daemon.failure.json", $JSON . "\n");
      }
      catch (\Throwable) {
         // The retained staging directory remains the primary evidence.
      }
   }
}
